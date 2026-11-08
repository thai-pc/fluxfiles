<?php

/**
 * End-to-end test of licence key refresh through the real CLI (`bin/fluxfiles`), against a
 * fake licence server and a fake update server, both `php -S` on ephemeral loopback ports.
 *
 * The CLI verifies keys against the EMBEDDED public key, whose private half is offline, so
 * the test mints keys with a throwaway Ed25519 keypair and injects its public half into the
 * CLI subprocess through `LicenseManager::overridePublicKeys()` (an auto_prepend_file seam;
 * no env override exists in production).
 *
 * Covers: refreshed (file 0600 + overlay), current, 401/404/429/500 leave the file alone,
 * a forged / older / earlier-expiring key is rejected, a foreign file is ignored, a newer
 * pasted env key wins over the file, FLUXFILES_LICENSE_REFRESH=false makes no call, and
 * `update` sends the refreshed key (and still runs when the licence server is down).
 *
 * Usage: php tests/e2e/test-license-refresh-http.php   (requires the curl extension)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\LicenseKeyFile;
use FluxFiles\LicenseManager;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

if (!function_exists('curl_init') || !function_exists('proc_open')) {
    echo "SKIP: needs curl + proc_open\n";
    exit(0);
}

$coreDir = (string) realpath(__DIR__ . '/../..');
$tmp = sys_get_temp_dir() . '/ff-lr-e2e-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$stateFile = "$tmp/state.json";
$logFile = "$tmp/licence-requests.log";
$updLog = "$tmp/update-requests.log";
$licFile = "$tmp/license-refresh.json";

// ── Throwaway signing keypair + key minting ────────────────────────────────
$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$kp = sodium_crypto_sign_keypair();
$SEC = sodium_crypto_sign_secretkey($kp);
$PUB = base64_encode(sodium_crypto_sign_publickey($kp));
LicenseManager::overridePublicKeys(['k1' => $PUB]);
$mint = static function (array $over = [], ?string $sec = null) use ($b64, $SEC): string {
    $p = array_merge(['customer' => 'acme', 'edition' => 'pro', 'modules' => ['share'], 'issued' => 1000, 'expires' => 5000, 'grace' => 0,
        'jti' => bin2hex(random_bytes(12)), 'enforcement' => 'subscription'], $over);
    $h = $b64((string) json_encode(['alg' => 'Ed25519', 'kid' => 'k1']));
    $pl = $b64((string) json_encode($p));
    return $h . '.' . $pl . '.' . $b64(sodium_crypto_sign_detached($h . '.' . $pl, $sec ?? $SEC));
};

// ── Servers ─────────────────────────────────────────────────────────────────
file_put_contents("$tmp/licence-router.php", <<<'PHP'
<?php
$st = json_decode((string) @file_get_contents(getenv('FFTEST_STATE')), true) ?: [];
$h = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
file_put_contents(getenv('FFTEST_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
    'auth' => $h['authorization'] ?? '', 'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/refresh') { http_response_code(404); echo '{"error":"nope"}'; return; }
switch ($st['mode'] ?? 'current') {
    case 'refreshed': echo json_encode(['status' => 'refreshed', 'license_key' => $st['key'], 'jti' => 'x', 'issued' => 1, 'expires' => 2]); break;
    case 'current': echo '{"status":"current"}'; break;
    case '401': http_response_code(401); echo '{"error":"invalid key"}'; break;
    case '404': http_response_code(404); echo '{"error":"not available","hint":"paste the key from your licence e-mail"}'; break;
    case '429': http_response_code(429); header('Retry-After: 60'); echo '{"error":"too many attempts"}'; break;
    default: http_response_code(500); echo '{"error":"internal error"}';
}
PHP);
file_put_contents("$tmp/update-router.php", <<<'PHP'
<?php
$h = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
file_put_contents(getenv('FFTEST_LOG'), json_encode(['uri' => $_SERVER['REQUEST_URI'], 'auth' => $h['authorization'] ?? '']) . "\n", FILE_APPEND);
http_response_code(403); echo '{"error":"stub update server"}';
PHP);
file_put_contents("$tmp/seam.php", <<<'PHP'
<?php
require getenv('FFTEST_AUTOLOAD');
\FluxFiles\LicenseManager::overridePublicKeys(['k1' => getenv('FFTEST_PUB')]);
PHP);

$freePort = static function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $n = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) strrchr($n, ':'), 1);
};
$procs = [];
$boot = static function (string $router, int $port, array $env) use (&$procs, $tmp): void {
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/srv-$port.out", 'w'], 2 => ['file', "$tmp/srv-$port.err", 'w']], $pipes, $tmp, $env + ['PATH' => (string) getenv('PATH')]);
    $procs[] = $p;
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($c) { fclose($c); return; }
        usleep(100000);
    }
    throw new \RuntimeException("server on $port did not start");
};
$LIC_PORT = $freePort();
$UPD_PORT = $freePort();
$DEAD_PORT = $freePort();
$boot("$tmp/licence-router.php", $LIC_PORT, ['FFTEST_STATE' => $stateFile, 'FFTEST_LOG' => $logFile]);
$boot("$tmp/update-router.php", $UPD_PORT, ['FFTEST_LOG' => $updLog]);

register_shutdown_function(static function () use (&$procs, $tmp): void {
    foreach ($procs as $p) { @proc_terminate($p); @proc_close($p); }
    $rm = static function (string $d) use (&$rm): void {
        foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) { is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); }
        @rmdir($d);
    };
    $rm($tmp);
});

// ── Helpers ─────────────────────────────────────────────────────────────────
$state = static fn (array $s) => file_put_contents($stateFile, json_encode($s));
$requests = static function (string $log): array {
    return is_file($log) ? array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($log)))) : [];
};
$wipe = static function () use ($logFile, $updLog, $licFile): void { @unlink($logFile); @unlink($updLog); @unlink($licFile); @unlink($licFile . '.lock'); };
/** @return array{0:int,1:string,2:string} exit, stdout, stderr */
$cli = static function (array $args, array $env) use ($coreDir, $tmp, $PUB): array {
    $full = ['PATH' => (string) getenv('PATH'), 'HOME' => $tmp, 'FFTEST_AUTOLOAD' => $coreDir . '/vendor/autoload.php', 'FFTEST_PUB' => $PUB,
        'FLUXFILES_LICENSE_FILE' => $tmp . '/license-refresh.json', 'FLUXFILES_SECRET' => str_repeat('s', 40)] + $env;
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $tmp . '/seam.php', $coreDir . '/bin/fluxfiles'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, $full);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};
$licEnv = static fn (string $key, array $x = []): array => array_merge(['FLUXFILES_LICENSE_KEY' => $key, 'FLUXFILES_LICENSE_SERVER_URL' => "http://127.0.0.1:$LIC_PORT",
    'FLUXFILES_UPDATE_URL' => "http://127.0.0.1:$UPD_PORT"], $x);
$mode = static fn (string $f): string => substr(sprintf('%o', fileperms($f)), -4);

$CUR = $mint(['issued' => 1000, 'expires' => 5000]);
$NEW = $mint(['issued' => 2000, 'expires' => 7000]);
$noLeak = static function (string $text, string ...$keys): void {
    foreach ($keys as $k) { assertTrue(!str_contains($text, $k), 'a licence key leaked into the output'); }
};

echo "\n{$cyan}══ license refresh (CLI e2e) ══{$reset}\n\n";

test('refreshed: file written 0600, overlay applied, request is POST /refresh with the Bearer header', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $licFile, $mode, $requests, $logFile, $noLeak) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    [$code, $out, $err] = $cli(['license', 'refresh'], $licEnv($CUR));
    assertEqual(0, $code, $err);
    assertTrue(is_file($licFile));
    assertEqual('0600', $mode($licFile));
    $j = json_decode((string) file_get_contents($licFile), true);
    assertEqual($NEW, $j['key']);
    assertEqual(LicenseKeyFile::anchor($CUR), $j['anchor']);
    $r = $requests($logFile);
    assertEqual(1, count($r));
    assertEqual('POST', $r[0]['method']);
    assertEqual('/refresh', $r[0]['uri']);
    assertEqual('Bearer ' . $CUR, $r[0]['auth']);
    assertEqual(1, json_decode($r[0]['body'], true)['v']);
    $noLeak($out . $err, $CUR, $NEW);
    // the overlay shows up in `about`
    [$c2, $o2] = $cli(['about', '--json'], $licEnv($CUR));
    assertEqual('refreshed', json_decode($o2, true)['license']['key_source']['source'] ?? null);
});
test('current: exit 0, file untouched, and the next call presents the refreshed key', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $licFile, $requests, $logFile) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    $cli(['license', 'refresh'], $licEnv($CUR));
    $before = file_get_contents($licFile);
    @unlink($logFile);
    $state(['mode' => 'current']);
    [$code, $out] = $cli(['license', 'refresh', '--json'], $licEnv($CUR));
    assertEqual(0, $code);
    assertEqual('current', json_decode($out, true)['status']);
    assertEqual($before, file_get_contents($licFile));
    assertEqual('Bearer ' . $NEW, $requests($logFile)[0]['auth']);
});
test('401 / 404 / 429 / 500 leave an existing file untouched and exit 3 / 3 / 2 / 2', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $licFile, $noLeak) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    $cli(['license', 'refresh'], $licEnv($CUR));
    $before = file_get_contents($licFile);
    foreach (['401' => 3, '404' => 3, '429' => 2, '500' => 2] as $m => $want) {
        $state(['mode' => $m]);
        [$code, $out, $err] = $cli(['license', 'refresh'], $licEnv($CUR));
        assertEqual($want, $code, "mode $m: $err");
        assertEqual($before, file_get_contents($licFile), "file after $m");
        $noLeak($out . $err, $CUR, $NEW);
    }
});
test('a forged key in the response is rejected (exit 3, nothing written)', function () use ($wipe, $state, $cli, $licEnv, $CUR, $mint, $licFile) {
    $wipe();
    $evil = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $state(['mode' => 'refreshed', 'key' => $mint(['issued' => 9000, 'expires' => 99999], $evil)]);
    [$code, , $err] = $cli(['license', 'refresh'], $licEnv($CUR));
    assertEqual(3, $code);
    assertTrue(!file_exists($licFile));
    assertTrue(str_contains($err, 'ignored'));
});
test('an older or earlier-expiring key is rejected', function () use ($wipe, $state, $cli, $licEnv, $CUR, $mint, $licFile) {
    foreach ([['issued' => 900, 'expires' => 99999], ['issued' => 9000, 'expires' => 4000]] as $over) {
        $wipe(); $state(['mode' => 'refreshed', 'key' => $mint($over)]);
        [$code] = $cli(['license', 'refresh'], $licEnv($CUR));
        assertEqual(3, $code);
        assertTrue(!file_exists($licFile));
    }
});
test('a foreign file (anchored to a different env key) is ignored', function () use ($wipe, $cli, $licEnv, $CUR, $NEW, $licFile, $updLog, $requests, $state) {
    $wipe(); $state(['mode' => 'current']);
    file_put_contents($licFile, json_encode(['v' => 1, 'anchor' => LicenseKeyFile::anchor('some-other-key'), 'key' => $NEW]));
    $cli(['update', 'share', '--no-refresh'], $licEnv($CUR));
    assertEqual('Bearer ' . $CUR, $requests($updLog)[0]['auth'], 'update sent the env key, not the foreign file key');
});
test('an env key pasted newer than the file wins', function () use ($wipe, $cli, $licEnv, $CUR, $NEW, $mint, $licFile, $updLog, $requests) {
    $wipe();
    file_put_contents($licFile, json_encode(['v' => 1, 'anchor' => LicenseKeyFile::anchor($CUR), 'key' => $NEW]));
    $pasted = $mint(['issued' => 3000, 'expires' => 9000]);
    $cli(['update', 'share', '--no-refresh'], $licEnv($pasted));
    assertEqual('Bearer ' . $pasted, $requests($updLog)[0]['auth']);
    $cli(['update', 'share', '--no-refresh'], $licEnv($CUR));
    assertEqual('Bearer ' . $NEW, $requests($updLog)[1]['auth'], 'the anchored newer file still wins for the key it was derived from');
});
test('FLUXFILES_LICENSE_REFRESH=false: update makes no licence-server call; an explicit refresh still runs', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $logFile, $updLog, $requests, $licFile) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    $cli(['update', 'share'], $licEnv($CUR, ['FLUXFILES_LICENSE_REFRESH' => 'false']));
    assertEqual([], $requests($logFile), 'no licence request');
    assertEqual('Bearer ' . $CUR, $requests($updLog)[0]['auth']);
    assertTrue(!file_exists($licFile));
    [$code] = $cli(['license', 'refresh'], $licEnv($CUR, ['FLUXFILES_LICENSE_REFRESH' => 'false']));
    assertEqual(0, $code);
    assertEqual(1, count($requests($logFile)), 'explicit refresh still works');
});
test('update refreshes first and sends the refreshed key', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $updLog, $requests, $licFile) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    $cli(['update', 'share', '--check'], $licEnv($CUR));
    assertTrue(is_file($licFile), 'refresh stored the key');
    assertEqual('Bearer ' . $NEW, $requests($updLog)[0]['auth']);
});
test('update --no-refresh skips the refresh', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $logFile, $requests) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    $cli(['update', 'share', '--no-refresh'], $licEnv($CUR));
    assertEqual([], $requests($logFile));
});
test('an unreachable licence server never blocks update (one stderr line, update still runs)', function () use ($wipe, $cli, $licEnv, $CUR, $updLog, $requests, $DEAD_PORT, $noLeak) {
    $wipe();
    [$code, $out, $err] = $cli(['update', 'share'], $licEnv($CUR, ['FLUXFILES_LICENSE_SERVER_URL' => "http://127.0.0.1:$DEAD_PORT"]));
    assertTrue(str_contains($err, 'Licence refresh skipped'), $err);
    assertEqual(1, count($requests($updLog)), 'update still contacted the update server');
    assertEqual(1, $code, 'update exits on its own result');
    $noLeak($out . $err, $CUR);
});
test('--check reports without writing; --print writes the key to stdout only', function () use ($wipe, $state, $cli, $licEnv, $CUR, $NEW, $licFile) {
    $wipe(); $state(['mode' => 'refreshed', 'key' => $NEW]);
    [$code, $out, $err] = $cli(['license', 'refresh', '--check'], $licEnv($CUR));
    assertEqual(0, $code);
    assertTrue(!file_exists($licFile));
    assertTrue(!str_contains($out . $err, $NEW));
    [$code, $out] = $cli(['license', 'refresh', '--print'], $licEnv($CUR));
    assertEqual(0, $code);
    assertEqual($NEW, trim($out));
    assertTrue(!file_exists($licFile));
});
test('usage and config errors exit 1; an https-less non-loopback URL is refused with no call', function () use ($wipe, $cli, $licEnv, $CUR, $logFile, $requests) {
    $wipe();
    assertEqual(1, $cli(['license'], $licEnv($CUR))[0]);
    assertEqual(1, $cli(['license', 'refresh', '--check', '--print'], $licEnv($CUR))[0]);
    assertEqual(1, $cli(['license', 'refresh'], ['FLUXFILES_LICENSE_KEY' => ''])[0]);
    assertEqual(1, $cli(['license', 'refresh'], $licEnv($CUR, ['FLUXFILES_LICENSE_SERVER_URL' => 'http://example.com']))[0]);
    assertEqual([], $requests($logFile));
});

echo "\n" . ($failed === 0 ? $green : $red) . "{$passed} passed, {$failed} failed{$reset}\n";
exit($failed === 0 ? 0 : 1);

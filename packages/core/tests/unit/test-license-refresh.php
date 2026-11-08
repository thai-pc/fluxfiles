<?php

/**
 * LicenseRefresh (pure client half) + LicenseKeyFile::refresh() against a stub transport:
 * accept() downgrade guard, every server answer, URL rules, and that the key never leaks
 * into a URL, a body, a message or an error_log line.
 *
 * Usage: php tests/unit/test-license-refresh.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\LicenseKeyFile;
use FluxFiles\LicenseManager;
use FluxFiles\LicenseRefresh;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$kp = sodium_crypto_sign_keypair();
$SEC = sodium_crypto_sign_secretkey($kp);
LicenseManager::overridePublicKeys(['k1' => base64_encode(sodium_crypto_sign_publickey($kp))]);
$mint = static function (array $over = [], ?string $sec = null) use ($b64, $SEC): string {
    $p = array_merge(['customer' => 'acme', 'edition' => 'pro', 'modules' => ['share'], 'issued' => 1000, 'expires' => 5000, 'grace' => 0,
        'jti' => bin2hex(random_bytes(12)), 'enforcement' => 'subscription'], $over);
    $h = $b64((string) json_encode(['alg' => 'Ed25519', 'kid' => 'k1']));
    $pl = $b64((string) json_encode($p));
    return $h . '.' . $pl . '.' . $b64(sodium_crypto_sign_detached($h . '.' . $pl, $sec ?? $SEC));
};

$CUR = $mint(['issued' => 1000, 'expires' => 5000]);
$NEW = $mint(['issued' => 2000, 'expires' => 7000]);

/** A stub transport recording every call; $answer is a response array or a callable. */
$stub = function ($answer, array &$calls = null) {
    $calls = $calls ?? [];
    return function (string $m, string $url, array $h, ?string $b, int $t) use ($answer, &$calls): array {
        $calls[] = compact('m', 'url', 'h', 'b', 't');
        return is_callable($answer) ? $answer() : $answer;
    };
};
$ok = static fn (array $json): array => ['status' => 200, 'headers' => [], 'body' => json_encode($json)];

echo "\n{$cyan}══ LicenseRefresh::accept ══{$reset}\n\n";
test('newer issued and later expiry: accepted', function () use ($CUR, $NEW) { assertTrue(LicenseRefresh::accept($CUR, $NEW)); });
test('equal issued: refused', function () use ($CUR, $mint) { assertTrue(!LicenseRefresh::accept($CUR, $mint(['issued' => 1000, 'expires' => 9000]))); });
test('older issued: refused', function () use ($CUR, $mint) { assertTrue(!LicenseRefresh::accept($CUR, $mint(['issued' => 900, 'expires' => 9000]))); });
test('earlier expiry: refused', function () use ($CUR, $mint) { assertTrue(!LicenseRefresh::accept($CUR, $mint(['issued' => 2000, 'expires' => 4000]))); });
test('same expiry: accepted (>=)', function () use ($CUR, $mint) { assertTrue(LicenseRefresh::accept($CUR, $mint(['issued' => 2000, 'expires' => 5000]))); });
test('edition change with later expiry: accepted', function () use ($CUR, $mint) { assertTrue(LicenseRefresh::accept($CUR, $mint(['issued' => 2000, 'expires' => 9000, 'edition' => 'studio']))); });
test('free edition candidate: refused', function () use ($CUR, $mint) { assertTrue(!LicenseRefresh::accept($CUR, $mint(['issued' => 2000, 'expires' => 9000, 'edition' => 'free']))); });
test('forged signature (other keypair): refused', function () use ($CUR, $mint) {
    $evil = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    assertTrue(!LicenseRefresh::accept($CUR, $mint(['issued' => 2000, 'expires' => 9000], $evil)));
});
test('garbage candidate or garbage current: refused', function () use ($CUR, $NEW) {
    assertTrue(!LicenseRefresh::accept($CUR, 'a.b.c'));
    assertTrue(!LicenseRefresh::accept('nope', $NEW));
});
test('a no-expiry candidate is not earlier; an unlimited key is never swapped for a dated one', function () use ($CUR, $mint) {
    assertTrue(LicenseRefresh::accept($CUR, $mint(['issued' => 2000, 'expires' => null])));
    $unl = $mint(['issued' => 1000, 'expires' => null]);
    assertTrue(!LicenseRefresh::accept($unl, $mint(['issued' => 2000, 'expires' => 9000])));
});

echo "\n{$cyan}══ LicenseRefresh::request ══{$reset}\n\n";
test('request shape: POST /refresh, Bearer header, JSON body, key not in URL/body', function () use ($CUR, $stub, $ok) {
    $calls = [];
    $r = LicenseRefresh::request($CUR, 'https://lic.example/', $stub($ok(['status' => 'current']), $calls), 'Core/1.2.0');
    assertEqual('current', $r['status']);
    assertEqual('POST', $calls[0]['m']);
    assertEqual('https://lic.example/refresh', $calls[0]['url']);
    assertEqual('Bearer ' . $CUR, $calls[0]['h']['Authorization']);
    assertEqual(['v' => 1, 'client' => 'core/1.2.0'], json_decode($calls[0]['b'], true));
    assertTrue(!str_contains($calls[0]['url'], $CUR) && !str_contains((string) $calls[0]['b'], $CUR));
    assertEqual(10, $calls[0]['t']);
});
test('refreshed: returns the key', function () use ($CUR, $NEW, $stub, $ok) {
    $r = LicenseRefresh::request($CUR, 'https://l.example', $stub($ok(['status' => 'refreshed', 'license_key' => $NEW, 'jti' => 'x', 'issued' => 2000, 'expires' => 7000])));
    assertEqual('refreshed', $r['status']);
    assertEqual($NEW, $r['key']);
});
test('401 / 404 / 429 (+Retry-After) / 400 / 500 / transport failure map to statuses', function () use ($CUR, $stub) {
    $r = fn (array $resp) => LicenseRefresh::request($CUR, 'https://l.example', $stub($resp));
    assertEqual('invalid', $r(['status' => 401, 'headers' => [], 'body' => '{"error":"invalid key"}'])['status']);
    assertEqual('unavailable', $r(['status' => 404, 'headers' => [], 'body' => '{"error":"not available"}'])['status']);
    $t = $r(['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '']);
    assertEqual('throttled', $t['status']);
    assertEqual(120, $t['retry_after']);
    assertEqual('error', $r(['status' => 400, 'headers' => [], 'body' => ''])['status']);
    assertEqual('error', $r(['status' => 500, 'headers' => [], 'body' => ''])['status']);
    assertEqual('error', $r(['status' => 0, 'headers' => [], 'body' => '', 'error' => 'timeout'])['status']);
});
test('a throwing transport is an error, not an exception', function () use ($CUR) {
    $r = LicenseRefresh::request($CUR, 'https://l.example', function () { throw new \RuntimeException('boom'); });
    assertEqual('error', $r['status']);
});
test('oversize, non-JSON, unknown status and malformed key answers are errors', function () use ($CUR, $stub, $ok) {
    $r = fn (array $resp) => LicenseRefresh::request($CUR, 'https://l.example', $stub($resp));
    assertEqual('error', $r(['status' => 200, 'headers' => [], 'body' => str_repeat('x', LicenseRefresh::MAX_RESPONSE + 1)])['status']);
    assertEqual('error', $r(['status' => 200, 'headers' => [], 'body' => 'nope'])['status']);
    assertEqual('error', $r($ok(['status' => 'weird']))['status']);
    assertEqual('error', $r($ok(['status' => 'refreshed', 'license_key' => 'not a key']))['status']);
    assertEqual('error', $r($ok(['status' => 'refreshed']))['status']);
});
test('a redirect answer is not followed (3xx is an error)', function () use ($CUR, $stub) {
    $calls = [];
    $r = LicenseRefresh::request($CUR, 'https://l.example', $stub(['status' => 302, 'headers' => ['location' => 'https://evil.example'], 'body' => ''], $calls));
    assertEqual('error', $r['status']);
    assertEqual(1, count($calls));
});
test('URL rules: https, or http to loopback only', function () use ($CUR, $stub, $ok) {
    assertTrue(LicenseRefresh::isSecureUrl('https://x.example'));
    assertTrue(LicenseRefresh::isSecureUrl('http://127.0.0.1:8080'));
    assertTrue(LicenseRefresh::isSecureUrl('http://localhost'));
    assertTrue(!LicenseRefresh::isSecureUrl('http://example.com'));
    assertTrue(!LicenseRefresh::isSecureUrl('ftp://example.com'));
    assertTrue(!LicenseRefresh::isSecureUrl('https://user:pw@example.com'));
    $calls = [];
    $r = LicenseRefresh::request($CUR, 'http://example.com', $stub($ok(['status' => 'current']), $calls));
    assertEqual('error', $r['status']);
    assertEqual(0, count($calls), 'no call to an insecure URL');
    assertEqual(LicenseRefresh::DEFAULT_URL, LicenseRefresh::baseUrl(''));
    assertEqual('', LicenseRefresh::baseUrl('http://example.com'));
    assertEqual('https://a.example', LicenseRefresh::baseUrl('https://a.example/'));
});

echo "\n{$cyan}══ LicenseKeyFile::refresh ══{$reset}\n\n";
$dir = sys_get_temp_dir() . '/ff-lr-' . bin2hex(random_bytes(4));
mkdir($dir);
$file = $dir . '/license-refresh.json';
$envFor = static fn (string $key, array $x = []): array => array_merge(['FLUXFILES_LICENSE_KEY' => $key, 'FLUXFILES_LICENSE_FILE' => $file, 'FLUXFILES_LICENSE_SERVER_URL' => 'https://l.example'], $x);

test('refreshed: file written (0600), overlay applied, message free of the key', function () use ($envFor, $CUR, $NEW, $stub, $ok, $file) {
    @unlink($file);
    $res = LicenseKeyFile::refresh($envFor($CUR), $stub($ok(['status' => 'refreshed', 'license_key' => $NEW])));
    assertEqual('refreshed', $res['outcome']);
    assertTrue($res['stored']);
    assertTrue(!isset($res['key']), 'key not returned when stored');
    assertEqual('0600', substr(sprintf('%o', fileperms($file)), -4));
    assertEqual($NEW, LicenseKeyFile::effectiveKey($envFor($CUR)));
    assertTrue(!str_contains(json_encode($res), $CUR) && !str_contains(json_encode($res), $NEW));
});
test('the next refresh presents the EFFECTIVE (refreshed) key', function () use ($envFor, $CUR, $NEW, $stub, $ok) {
    $calls = [];
    $res = LicenseKeyFile::refresh($envFor($CUR), $stub($ok(['status' => 'current']), $calls));
    assertEqual('current', $res['outcome']);
    assertEqual('Bearer ' . $NEW, $calls[0]['h']['Authorization']);
});
test('401 / 404 / 429 / 500 leave the file untouched', function () use ($envFor, $CUR, $stub, $file) {
    $before = file_get_contents($file);
    foreach ([401 => 'invalid', 404 => 'unavailable', 429 => 'throttled', 500 => 'error'] as $code => $outcome) {
        $res = LicenseKeyFile::refresh($envFor($CUR), $stub(['status' => $code, 'headers' => [], 'body' => '']));
        assertEqual($outcome, $res['outcome'], (string) $code);
        assertEqual($before, file_get_contents($file), "file unchanged after $code");
    }
});
test('forged / older / earlier-expiring answers are rejected and not stored', function () use ($envFor, $CUR, $NEW, $mint, $stub, $ok, $file) {
    $before = file_get_contents($file);
    $evil = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    foreach ([
        $mint(['issued' => 9000, 'expires' => 99999], $evil), // forged
        $mint(['issued' => 1500, 'expires' => 99999]),        // older than the effective key
        $mint(['issued' => 9000, 'expires' => 6000]),         // earlier expiry
    ] as $bad) {
        $res = LicenseKeyFile::refresh($envFor($CUR), $stub($ok(['status' => 'refreshed', 'license_key' => $bad])));
        assertEqual('rejected', $res['outcome']);
        assertEqual($before, file_get_contents($file));
    }
});
test('check mode (write=false) reports without writing; the key is returned for --print', function () use ($envFor, $CUR, $mint, $stub, $ok, $file) {
    @unlink($file);
    $newer = $mint(['issued' => 3000, 'expires' => 8000]);
    $res = LicenseKeyFile::refresh($envFor($CUR), $stub($ok(['status' => 'refreshed', 'license_key' => $newer])), false);
    assertEqual('refreshed', $res['outcome']);
    assertEqual(false, $res['stored']);
    assertEqual($newer, $res['key']);
    assertTrue(!file_exists($file));
});
test('config problems: no key, invalid key, bad URL; nothing to refresh without expiry/modules', function () use ($envFor, $CUR, $mint, $stub, $ok) {
    $h = $stub($ok(['status' => 'current']));
    assertEqual('config', LicenseKeyFile::refresh(['FLUXFILES_LICENSE_KEY' => ''], $h)['outcome']);
    assertEqual('config', LicenseKeyFile::refresh($envFor('garbage'), $h)['outcome']);
    assertEqual('config', LicenseKeyFile::refresh($envFor($CUR, ['FLUXFILES_LICENSE_SERVER_URL' => 'http://example.com']), $h)['outcome']);
    assertEqual('nothing', LicenseKeyFile::refresh($envFor($mint(['expires' => null])), $h)['outcome']);
    assertEqual('nothing', LicenseKeyFile::refresh($envFor($mint(['modules' => []])), $h)['outcome']);
});
test('no error_log / stderr line mentions the key', function () use ($envFor, $CUR, $NEW, $stub, $ok, $file) {
    $log = tempnam(sys_get_temp_dir(), 'fflog');
    $prev = ini_set('error_log', $log);
    @unlink($file);
    LicenseKeyFile::refresh($envFor($CUR), $stub(['status' => 500, 'headers' => [], 'body' => $CUR]));
    LicenseKeyFile::refresh($envFor($CUR), function () { throw new \RuntimeException('boom'); });
    LicenseKeyFile::refresh($envFor($CUR), $stub($ok(['status' => 'refreshed', 'license_key' => $NEW])));
    $out = (string) file_get_contents($log);
    ini_set('error_log', $prev === false ? '' : $prev);
    @unlink($log);
    assertTrue(!str_contains($out, $CUR) && !str_contains($out, $NEW), 'key leaked into error_log');
});

foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) { @unlink($dir . '/' . $f); }
@rmdir($dir);
echo "\n" . ($failed === 0 ? $green : $red) . "{$passed} passed, {$failed} failed{$reset}\n";
exit($failed === 0 ? 0 : 1);

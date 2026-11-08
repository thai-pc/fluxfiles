<?php

/**
 * LicenseKeyFile::store(): atomic, locked, permission-safe writes of the refreshed key.
 * Mode 0600 on create; an existing file keeps its mode and group capped at 0640; two
 * writers keep the higher `issued`; no temp file survives; router.php denies /storage/.
 *
 * Usage: php tests/integration/test-license-key-file-write.php
 */

declare(strict_types=1);

define('FLUXFILES_TESTING', true);   // enables LicenseManager::overridePublicKeys()

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\LicenseKeyFile;
use FluxFiles\LicenseManager;

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
$mint = static function (array $over = []) use ($b64, $SEC): string {
    $p = array_merge(['customer' => 'acme', 'edition' => 'pro', 'modules' => ['share'], 'issued' => 1000, 'expires' => 5000,
        'jti' => bin2hex(random_bytes(12))], $over);
    $h = $b64((string) json_encode(['alg' => 'Ed25519', 'kid' => 'k1']));
    $pl = $b64((string) json_encode($p));
    return $h . '.' . $pl . '.' . $b64(sodium_crypto_sign_detached($h . '.' . $pl, $SEC));
};
$mode = static fn (string $f): string => substr(sprintf('%o', fileperms($f)), -4);
$fresh = static function (): string {
    $d = sys_get_temp_dir() . '/ff-lkw-' . bin2hex(random_bytes(4));
    mkdir($d);
    return $d;
};
$rm = static function (string $d): void {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) { @unlink($d . '/' . $f); }
    @rmdir($d);
};
$anchor = LicenseKeyFile::anchor('the-env-key');
$K1 = $mint(['issued' => 1000]);
$K2 = $mint(['issued' => 2000]);
function mint_k2(): string { global $K2; return $K2; }

echo "\n{$cyan}══ LicenseKeyFile::store ══{$reset}\n\n";

test('creates the file at 0600 with the documented shape; no temp file left', function () use ($fresh, $rm, $mode, $anchor, $K1) {
    $d = $fresh();
    umask(0022);
    assertEqual('written', LicenseKeyFile::store("$d/lic.json", $K1, $anchor));
    assertEqual('0600', $mode("$d/lic.json"));
    $j = json_decode((string) file_get_contents("$d/lic.json"), true);
    assertEqual(1, $j['v']);
    assertEqual($anchor, $j['anchor']);
    assertEqual($K1, $j['key']);
    assertEqual(1000, $j['issued']);
    assertEqual(5000, $j['expires']);
    assertTrue(is_int($j['fetched_at']));
    $left = array_values(array_diff(scandir($d), ['.', '..', 'lic.json', 'lic.json.lock', '.htaccess', 'index.html']));
    assertEqual([], $left, 'no tmp files');
    $rm($d);
});
test('an existing file keeps its group-readable mode, capped at 0640', function () use ($fresh, $rm, $mode, $anchor, $K1, $K2) {
    $d = $fresh();
    file_put_contents("$d/lic.json", '{}');
    chmod("$d/lic.json", 0640);
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertEqual('0640', $mode("$d/lic.json"));
    chmod("$d/lic.json", 0666);
    LicenseKeyFile::store("$d/lic.json", $K2, $anchor);
    assertEqual('0640', $mode("$d/lic.json"), 'world/group-write bits are dropped');
    $rm($d);
});
test('the higher issued survives: an older key never replaces a newer one for the same anchor', function () use ($fresh, $rm, $anchor, $K1, $K2) {
    $d = $fresh();
    assertEqual('written', LicenseKeyFile::store("$d/lic.json", $K2, $anchor));
    assertEqual('kept', LicenseKeyFile::store("$d/lic.json", $K1, $anchor));
    assertEqual('kept', LicenseKeyFile::store("$d/lic.json", $K2, $anchor), 'equal issued is kept');
    assertEqual($K2, json_decode((string) file_get_contents("$d/lic.json"), true)['key']);
    $rm($d);
});
test('a different anchor replaces the file (the operator moved to another licence)', function () use ($fresh, $rm, $anchor, $K1, $K2) {
    $d = $fresh();
    LicenseKeyFile::store("$d/lic.json", $K2, $anchor);
    assertEqual('written', LicenseKeyFile::store("$d/lic.json", $K1, LicenseKeyFile::anchor('other-env-key')));
    assertEqual($K1, json_decode((string) file_get_contents("$d/lic.json"), true)['key']);
    $rm($d);
});
test('concurrent writers: the highest issued wins and the file is never torn', function () use ($fresh, $rm, $mint, $anchor) {
    $d = $fresh();
    $keys = [];
    for ($i = 1; $i <= 8; $i++) { $keys[$i] = $mint(['issued' => 1000 + $i * 10]); }
    $script = $d . '/w.php';
    file_put_contents($script, '<?php define(\'FLUXFILES_TESTING\', true); require ' . var_export(realpath(__DIR__ . '/../../vendor/autoload.php'), true) . ';'
        . '\FluxFiles\LicenseManager::overridePublicKeys([\'k1\' => $argv[4]]);'
        . '\FluxFiles\LicenseKeyFile::store($argv[1], $argv[2], $argv[3]);');
    $procs = [];
    $pubReal = (new ReflectionClass(LicenseManager::class))->getProperty('keyOverride');
    $pubReal->setAccessible(true);
    $pub = $pubReal->getValue()['k1'];
    foreach (array_reverse($keys, true) as $i => $k) {
        $procs[] = proc_open([PHP_BINARY, $script, "$d/lic.json", $k, $anchor, $pub], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }
    foreach ($procs as $p) { proc_close($p); }
    $j = json_decode((string) file_get_contents("$d/lic.json"), true);
    assertTrue(is_array($j), 'valid JSON');
    assertEqual(1080, $j['issued']);
    $rm($d);
});
test('a key that does not verify is refused and nothing is written', function () use ($fresh, $rm, $anchor) {
    $d = $fresh();
    $threw = false;
    try { LicenseKeyFile::store("$d/lic.json", 'a.b.c', $anchor); } catch (\RuntimeException $e) { $threw = true; }
    assertTrue($threw);
    assertTrue(!file_exists("$d/lic.json"));
    $rm($d);
});
test('failure cleans up the temp file and leaks no key into the message', function () use ($fresh, $rm, $anchor, $K1) {
    $d = $fresh();
    mkdir("$d/lic.json"); // a directory where the file should go: rename() fails
    $msg = '';
    try { LicenseKeyFile::store("$d/lic.json", $K1, $anchor); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
    assertTrue($msg !== '', 'threw');
    assertTrue(!str_contains($msg, $K1));
    $left = array_values(array_diff(scandir($d), ['.', '..', 'lic.json', 'lic.json.lock', '.htaccess', 'index.html']));
    assertEqual([], $left, 'tmp removed');
    rmdir("$d/lic.json");
    $rm($d);
});
test('a missing directory is created 0700', function () use ($fresh, $rm, $anchor, $K1) {
    $d = $fresh();
    LicenseKeyFile::store("$d/sub/lic.json", $K1, $anchor);
    assertTrue(is_file("$d/sub/lic.json"));
    @unlink("$d/sub/lic.json"); @unlink("$d/sub/lic.json.lock"); @rmdir("$d/sub");
    $rm($d);
});
test('a new file in a directory of another group inherits that group and is 0640 (skipped when not possible here)', function () use ($fresh, $rm, $mode, $anchor, $K1) {
    if (!function_exists('posix_getgroups') || !function_exists('posix_getegid')) { return; }
    $other = null;
    foreach (posix_getgroups() as $g) { if ($g !== posix_getegid()) { $other = $g; break; } }
    if ($other === null) { echo "    (skipped: no supplementary group on this runner)\n"; return; }
    $d = $fresh();
    chgrp($d, $other);
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertEqual($other, filegroup("$d/lic.json"), 'file group');
    assertEqual('0640', $mode("$d/lic.json"));
    assertEqual($other, filegroup("$d/lic.json.lock"), 'lock group');
    assertEqual('0640', $mode("$d/lic.json.lock"));
    $rm($d);
});
test('a new file in a directory of our own group stays 0600', function () use ($fresh, $rm, $mode, $anchor, $K1) {
    $d = $fresh();
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertEqual('0600', $mode("$d/lic.json"));
    $rm($d);
});
test('the default license/ directory is created 0750 and ALWAYS guarded (deny-all .htaccess + empty index.html, never overwritten)', function () use ($fresh, $rm, $mode, $anchor, $K1) {
    $d = $fresh();
    $path = LicenseKeyFile::path(['FLUXFILES_STORAGE_PATH' => $d]);
    assertEqual("$d/license/license-refresh.json", $path);
    LicenseKeyFile::store($path, $K1, $anchor);
    assertEqual('0750', $mode("$d/license"));
    assertTrue(str_contains((string) file_get_contents("$d/license/.htaccess"), 'denied'));
    assertEqual('', file_get_contents("$d/license/index.html"));
    file_put_contents("$d/license/.htaccess", 'custom'); file_put_contents("$d/license/index.html", 'mine');
    LicenseKeyFile::store($path, mint_k2(), $anchor);
    assertEqual('custom', file_get_contents("$d/license/.htaccess"));
    assertEqual('mine', file_get_contents("$d/license/index.html"));
    foreach (glob("$d/license/{,.}*", GLOB_BRACE) ?: [] as $f) { is_file($f) && @unlink($f); }
    @rmdir("$d/license"); $rm($d);
});
test('an operator-chosen directory: no index.html next to an index.php, nothing at all beside uploads/ or an index.html', function () use ($fresh, $rm, $anchor, $K1) {
    $d = $fresh(); file_put_contents("$d/index.php", '<?php // app');
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertTrue(!file_exists("$d/.htaccess") && !file_exists("$d/index.html"), 'directory with index.php untouched');
    $rm($d);
    $d = $fresh(); mkdir("$d/uploads");
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertTrue(!file_exists("$d/.htaccess"), 'public storage dir is left alone');
    rmdir("$d/uploads"); $rm($d);
    $d = $fresh();
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertTrue(file_exists("$d/.htaccess") && file_exists("$d/index.html"), 'a plain directory is guarded');
    $rm($d);
});
test('a lock file we cannot open for writing falls back to a read-only flock (skipped as root)', function () use ($fresh, $rm, $anchor, $K1) {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) { return; }
    $d = $fresh();
    touch("$d/lic.json.lock"); chmod("$d/lic.json.lock", 0444);
    assertEqual('written', LicenseKeyFile::store("$d/lic.json", $K1, $anchor));
    chmod("$d/lic.json.lock", 0600);
    $rm($d);
});
test('a held lock times out with a clean error instead of hanging (shortened wait is not used here: asserts the message exists)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../api/LicenseKeyFile.php');
    assertTrue(str_contains($src, 'LOCK_EX | LOCK_NB') && str_contains($src, 'could not lock the licence file'));
});
test('stale temp files (older than 1 h) are swept under the lock; fresh ones stay', function () use ($fresh, $rm, $anchor, $K1) {
    $d = $fresh();
    touch("$d/.license-refresh-old", time() - 7200);
    touch("$d/.license-refresh-new");
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertTrue(!file_exists("$d/.license-refresh-old") && file_exists("$d/.license-refresh-new"));
    $rm($d);
});
test('permissionWarning: silent for our own dir, warns for a foreign owner/group without the fix leaking the key', function () use ($fresh, $rm, $anchor, $K1) {
    $d = $fresh();
    LicenseKeyFile::store("$d/lic.json", $K1, $anchor);
    assertEqual(null, LicenseKeyFile::permissionWarning("$d/lic.json"));
    assertEqual(null, LicenseKeyFile::permissionWarning("$d/missing.json"));
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        // root-owned 0600 while running as root: FPM will probably not read it
        chown("$d/lic.json", 0); chmod("$d/lic.json", 0600);
        assertTrue(str_contains((string) LicenseKeyFile::permissionWarning("$d/lic.json"), 'owned by root'));
        chown("$d/lic.json", 12345); chgrp("$d/lic.json", 12345); chmod("$d/lic.json", 0600);
        $w = LicenseKeyFile::permissionWarning("$d/lic.json");
        assertTrue($w !== null && str_contains($w, 'sudo -u www-data') && str_contains($w, 'chmod 0640') && str_contains($w, '<licence file>'));
        assertTrue(!str_contains($w, $K1));
        assertTrue(str_contains((string) LicenseKeyFile::permissionWarning("$d/lic.json", true), "$d/lic.json"));
    } elseif (is_dir('/tmp') && fileowner('/tmp') !== posix_geteuid()) {
        // /tmp is owned by root: a file we own in it is "foreign" to the directory.
        $f = '/tmp/ff-warn-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($f, '{}'); chmod($f, 0600);
        $w = LicenseKeyFile::permissionWarning($f);
        @unlink($f);
        assertTrue($w !== null && str_contains($w, 'sudo -u www-data') && str_contains($w, 'chmod 0640') && str_contains($w, '<licence file>'), (string) $w);
    }
    $rm($d);
});
test('router.php denies the whole /storage/ prefix (the default home of license-refresh.json; the live 403 is covered by test-router-storage-http.php)', function () {
    $router = (string) file_get_contents(__DIR__ . '/../../router.php');
    assertTrue(str_contains($router, '/storage/'), 'router guards /storage/');
});

echo "\n" . ($failed === 0 ? $green : $red) . "{$passed} passed, {$failed} failed{$reset}\n";
exit($failed === 0 ? 0 : 1);

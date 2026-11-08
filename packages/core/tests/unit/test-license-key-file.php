<?php

/**
 * LicenseKeyFile / LicenseManager::fromEnv(): the refreshed-key overlay precedence matrix.
 * A key from the refresh file is used only when it is anchored to the exact env key AND
 * strictly newer; everything else falls back to the env key, silently.
 *
 * Usage: php tests/unit/test-license-key-file.php
 */

declare(strict_types=1);

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
    $p = array_merge(['customer' => 'acme', 'edition' => 'pro', 'modules' => ['share'], 'issued' => 1000, 'expires' => 5000, 'grace' => 0,
        'jti' => bin2hex(random_bytes(12)), 'enforcement' => 'subscription'], $over);
    $h = $b64((string) json_encode(['alg' => 'Ed25519', 'kid' => 'k1']));
    $pl = $b64((string) json_encode($p));
    return $h . '.' . $pl . '.' . $b64(sodium_crypto_sign_detached($h . '.' . $pl, $SEC));
};

$dir = sys_get_temp_dir() . '/ff-lkf-' . bin2hex(random_bytes(4));
mkdir($dir);
$file = $dir . '/license-refresh.json';
$writeFile = static function (string $anchorKey, string $fileKey, ?string $path = null) use ($file): void {
    file_put_contents($path ?? $file, json_encode(['v' => 1, 'anchor' => LicenseKeyFile::anchor($anchorKey), 'key' => $fileKey, 'fetched_at' => 1234567890]));
};
$env = static fn (string $key, array $extra = []): array => array_merge(['FLUXFILES_LICENSE_KEY' => $key, 'FLUXFILES_LICENSE_FILE' => $file], $extra);

$A = $mint(['issued' => 1000, 'jti' => 'aaaaaaaaaaaaaaaaaaaaaaaa']);
$A2 = $mint(['issued' => 2000, 'expires' => 9000, 'jti' => 'bbbbbbbbbbbbbbbbbbbbbbbb']);

echo "\n{$cyan}══ LicenseKeyFile overlay ══{$reset}\n\n";

test('no file: env key is used unchanged', function () use ($env, $A, $file) {
    @unlink($file);
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)));
    assertEqual('env', LicenseKeyFile::resolve($env($A))['source']);
});
test('anchored and strictly newer: the file key wins', function () use ($env, $A, $A2, $writeFile) {
    $writeFile($A, $A2);
    $r = LicenseKeyFile::resolve($env($A));
    assertEqual($A2, $r['key']);
    assertEqual('refreshed', $r['source']);
    assertEqual(1234567890, $r['fetched_at']);
    assertEqual(9000, LicenseManager::fromEnv($env($A))->expiresAt(), 'fromEnv() sees the refreshed key');
});
test('whitespace in the env key does not break the anchor', function () use ($env, $A, $A2, $writeFile) {
    $writeFile($A, $A2);
    assertEqual($A2, LicenseKeyFile::effectiveKey($env("  " . substr($A, 0, 30) . "\n" . substr($A, 30) . "\n")));
});
test('anchor mismatch (a different env key): file ignored', function () use ($env, $mint, $A2, $A, $writeFile) {
    $writeFile($A, $A2);
    $B = $mint(['issued' => 500, 'jti' => 'cccccccccccccccccccccccc']);
    assertEqual($B, LicenseKeyFile::effectiveKey($env($B)));
    assertEqual('env', LicenseKeyFile::resolve($env($B))['source']);
});
test('env key pasted newer than the file: env wins (anchor differs and issued is newer)', function () use ($env, $mint, $A, $A2, $writeFile) {
    $writeFile($A, $A2);
    $N = $mint(['issued' => 3000, 'expires' => 12000, 'jti' => 'dddddddddddddddddddddddd']);
    assertEqual($N, LicenseKeyFile::effectiveKey($env($N)));
});
test('file key equal or older than env: env wins even when anchored', function () use ($env, $mint, $A, $writeFile) {
    $writeFile($A, $A);
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)), 'equal issued');
    $old = $mint(['issued' => 10, 'jti' => 'eeeeeeeeeeeeeeeeeeeeeeee']);
    $writeFile($A, $old);
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)), 'older issued');
});
test('bad signature in the file: env wins, source reports unreadable', function () use ($env, $A, $A2, $writeFile) {
    $parts = explode('.', $A2);
    $parts[1] = strtr($parts[1], ['a' => 'b']) . 'x';
    $writeFile($A, implode('.', $parts));
    $r = LicenseKeyFile::resolve($env($A));
    assertEqual($A, $r['key']);
    assertEqual('unreadable', $r['source']);
});
test('malformed JSON / wrong version / unreadable file: env wins', function () use ($env, $A, $A2, $file) {
    file_put_contents($file, '{not json');
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)));
    file_put_contents($file, json_encode(['v' => 2, 'anchor' => LicenseKeyFile::anchor($A), 'key' => $A2]));
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)));
    file_put_contents($file, str_repeat('x', LicenseKeyFile::MAX_BYTES + 1));
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)));
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        file_put_contents($file, json_encode(['v' => 1, 'anchor' => LicenseKeyFile::anchor($A), 'key' => $A2]));
        chmod($file, 0000);
        assertEqual($A, LicenseKeyFile::effectiveKey($env($A)), 'unreadable');
        chmod($file, 0600);
    }
});
test('no env key: the file is not a licence source', function () use ($A, $A2, $writeFile, $file) {
    $writeFile($A, $A2);
    assertEqual('', LicenseKeyFile::effectiveKey(['FLUXFILES_LICENSE_FILE' => $file]));
    assertEqual(null, LicenseManager::fromEnv(['FLUXFILES_LICENSE_FILE' => $file])->id());
});
test('an env key that does not verify: used as given (free), file ignored', function () use ($env, $A, $A2, $writeFile) {
    $writeFile('garbage', $A2);
    assertEqual('garbage', LicenseKeyFile::effectiveKey($env('garbage')));
    assertEqual('free', LicenseManager::fromEnv($env('garbage'))->edition());
});
test('a relative FLUXFILES_LICENSE_FILE is ignored (default path used) and warned about', function () use ($A) {
    $e = ['FLUXFILES_LICENSE_KEY' => $A, 'FLUXFILES_LICENSE_FILE' => 'rel/path.json', 'FLUXFILES_STORAGE_PATH' => '/srv/ff-storage'];
    assertEqual('/srv/ff-storage/license-refresh.json', LicenseKeyFile::path($e));
    assertTrue(LicenseKeyFile::pathWarning($e) !== null);
    assertEqual(null, LicenseKeyFile::pathWarning(['FLUXFILES_LICENSE_FILE' => '/abs/x.json']));
});
test('default path follows FLUXFILES_STORAGE_PATH, else the package storage dir', function () {
    assertEqual('/data/ff/license-refresh.json', LicenseKeyFile::path(['FLUXFILES_STORAGE_PATH' => '/data/ff/']));
    assertTrue(str_ends_with(LicenseKeyFile::path([]), '/storage/license-refresh.json'));
});
test('the memo invalidates when the file changes', function () use ($env, $A, $A2, $writeFile, $file) {
    $writeFile($A, $A2);
    assertEqual($A2, LicenseKeyFile::effectiveKey($env($A)));
    @unlink($file); // new inode
    $writeFile($A, $A); // equal issued: not newer
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)));
    @unlink($file);
    assertEqual($A, LicenseKeyFile::effectiveKey($env($A)), 'file gone');
});
test('issuedAt() reads the signed value', function () use ($A, $A2) {
    assertEqual(1000, (new LicenseManager($A))->issuedAt());
    assertEqual(2000, (new LicenseManager($A2))->issuedAt());
    assertEqual(null, (new LicenseManager('nope'))->issuedAt());
});

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
echo "\n" . ($failed === 0 ? $green : $red) . "{$passed} passed, {$failed} failed{$reset}\n";
exit($failed === 0 ? 0 : 1);

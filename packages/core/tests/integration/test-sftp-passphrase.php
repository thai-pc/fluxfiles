<?php

/**
 * SFTP private keys WITH a passphrase (RSA / ED25519). Proves the passphrase
 * plumbs through every layer and that a real passphrase-protected key actually
 * decrypts via the same phpseclib call the SFTP provider uses — no SFTP server
 * needed (the connection is built but never opened).
 *
 * Usage: php tests/integration/test-sftp-passphrase.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\CredentialEncryptor;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0; $skipped = 0;
$PASS = 'P@ssphrase-123';

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** Reflect the private static DiskManager::buildSftpProvider($cfg). */
function buildProvider(array $cfg) {
    $m = new \ReflectionMethod(DiskManager::class, 'buildSftpProvider');
    $m->setAccessible(true);
    return $m->invoke(null, $cfg);
}
/** Read a private property off the built provider. */
function prop($obj, string $name) {
    $r = new \ReflectionProperty(get_class($obj), $name);
    $r->setAccessible(true);
    return $r->getValue($obj);
}

echo "\n{$cyan}══ SFTP private-key passphrase (RSA / ED25519) ══{$reset}\n\n";

// A public documentation-range IP (TEST-NET-2) passes the SSRF guard with no DNS.
$HOST = '198.51.100.10';

test('buildSftpProvider forwards private_key_passphrase to the connection provider', function () use ($HOST, $PASS) {
    $p = buildProvider([
        'driver' => 'sftp', 'host' => $HOST, 'username' => 'deploy',
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n...\n-----END OPENSSH PRIVATE KEY-----",
        'private_key_passphrase' => $PASS,
    ]);
    assertEqual($PASS, prop($p, 'passphrase'), 'passphrase reaches the provider');
    assertTrue(prop($p, 'privateKey') !== null, 'privateKey set');
});

test('no passphrase → provider passphrase is null (unencrypted key still works)', function () use ($HOST) {
    $p = buildProvider(['driver' => 'sftp', 'host' => $HOST, 'username' => 'u', 'private_key' => 'KEYPEM']);
    assertEqual(null, prop($p, 'passphrase'));
});

test('BYOB: validate accepts a passphrase config + encrypt/decrypt round-trips it', function () use ($PASS) {
    $secret = str_repeat('s', 40);
    $cfg = [
        'driver' => 'sftp', 'host' => '198.51.100.10', 'username' => 'deploy',
        'private_key' => 'KEYPEM', 'private_key_passphrase' => $PASS, 'root' => '/srv',
    ];
    CredentialEncryptor::validate('my-vps', $cfg);            // must not throw
    $enc = CredentialEncryptor::encrypt($cfg, $secret);
    $dec = CredentialEncryptor::decrypt($enc, $secret);
    assertEqual($PASS, $dec['private_key_passphrase'], 'passphrase survives the encrypted JWT round-trip');
});

test('env SFTP_PRIVATE_KEY_PASSPHRASE maps to the disk config', function () use ($HOST, $PASS) {
    $saved = [$_ENV['SFTP_HOST'] ?? null, $_ENV['SFTP_USERNAME'] ?? null, $_ENV['SFTP_PRIVATE_KEY'] ?? null, $_ENV['SFTP_PRIVATE_KEY_PASSPHRASE'] ?? null];
    $_ENV['SFTP_HOST'] = $HOST; $_ENV['SFTP_USERNAME'] = 'u'; $_ENV['SFTP_PRIVATE_KEY'] = 'KEY'; $_ENV['SFTP_PRIVATE_KEY_PASSPHRASE'] = $PASS;
    try {
        $disks = require __DIR__ . '/../../config/disks.php';
        assertEqual($PASS, $disks['sftp']['private_key_passphrase'] ?? null);
    } finally {
        [$_ENV['SFTP_HOST'], $_ENV['SFTP_USERNAME'], $_ENV['SFTP_PRIVATE_KEY'], $_ENV['SFTP_PRIVATE_KEY_PASSPHRASE']] = $saved;
        foreach (['SFTP_HOST', 'SFTP_USERNAME', 'SFTP_PRIVATE_KEY', 'SFTP_PRIVATE_KEY_PASSPHRASE'] as $i => $k) {
            if ($saved[$i] === null) { unset($_ENV[$k]); }
        }
    }
});

// Real keys: generate ed25519 + rsa keys WITH a passphrase and decrypt them with
// the exact phpseclib call the SFTP provider uses (loadPrivateKey → PublicKeyLoader::load).
test('a real ED25519 + RSA passphrase key decrypts with the right passphrase (wrong one fails)', function () use ($PASS) {
    global $skipped;
    @exec('command -v ssh-keygen', $out, $code);
    if ($code !== 0) { $skipped++; echo "    (skipped — ssh-keygen not available)\n"; return; }

    $dir = sys_get_temp_dir() . '/ff-sftp-pp-' . uniqid();
    @mkdir($dir, 0700, true);
    try {
        foreach (['ed25519', 'rsa'] as $type) {
            $kf = "{$dir}/id_{$type}";
            $bits = $type === 'rsa' ? '-b 2048' : '';
            @exec("ssh-keygen -t {$type} {$bits} -N " . escapeshellarg($PASS) . " -f " . escapeshellarg($kf) . " -q -C demo 2>&1", $o, $c);
            assertTrue(is_file($kf), "{$type} key generated");
            $pem = (string) file_get_contents($kf);

            // Right passphrase → loads (the provider's exact path).
            $key = \phpseclib3\Crypt\PublicKeyLoader::load($pem, $PASS);
            assertTrue($key instanceof \phpseclib3\Crypt\Common\PrivateKey, "{$type}: loaded as a private key");

            // Wrong passphrase → must fail.
            $threw = false;
            try { \phpseclib3\Crypt\PublicKeyLoader::load($pem, 'wrong-pass'); }
            catch (\Throwable $e) { $threw = true; }
            assertTrue($threw, "{$type}: wrong passphrase is rejected");
        }
    } finally {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
    }
});

// ── Host-key pinning (anti-MITM) ──────────────────────────────────────────
$FP = '12:34:56:78:9a:bc:de:f0:12:34:56:78:9a:bc:de:f0';

test('buildSftpProvider forwards a single host_fingerprint as a string', function () use ($HOST, $FP) {
    $p = buildProvider(['driver' => 'sftp', 'host' => $HOST, 'username' => 'u', 'password' => 'pw', 'host_fingerprint' => $FP]);
    assertEqual($FP, prop($p, 'hostFingerprint'), 'fingerprint reaches the provider');
});

test('comma-separated fingerprints → array (key rotation)', function () use ($HOST, $FP) {
    $second = 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99';
    $p = buildProvider(['driver' => 'sftp', 'host' => $HOST, 'username' => 'u', 'password' => 'pw', 'host_fingerprint' => " {$FP} , {$second} "]);
    assertEqual([$FP, $second], prop($p, 'hostFingerprint'), 'both fingerprints, trimmed');
});

test('no host_fingerprint → null (backward-compatible; provider trusts any key)', function () use ($HOST) {
    $p = buildProvider(['driver' => 'sftp', 'host' => $HOST, 'username' => 'u', 'password' => 'pw']);
    assertEqual(null, prop($p, 'hostFingerprint'));
});

test('useAgent is forced off (never reach for a server-side ssh-agent)', function () use ($HOST) {
    $p = buildProvider(['driver' => 'sftp', 'host' => $HOST, 'username' => 'u', 'password' => 'pw']);
    assertEqual(false, prop($p, 'useAgent'));
});

test('env SFTP_HOST_FINGERPRINT maps to the disk config', function () use ($HOST, $FP) {
    $saved = [$_ENV['SFTP_HOST'] ?? null, $_ENV['SFTP_HOST_FINGERPRINT'] ?? null];
    $_ENV['SFTP_HOST'] = $HOST; $_ENV['SFTP_HOST_FINGERPRINT'] = $FP;
    try {
        $disks = require __DIR__ . '/../../config/disks.php';
        assertEqual($FP, $disks['sftp']['host_fingerprint'] ?? null);
    } finally {
        [$_ENV['SFTP_HOST'], $_ENV['SFTP_HOST_FINGERPRINT']] = $saved;
        foreach (['SFTP_HOST', 'SFTP_HOST_FINGERPRINT'] as $i => $k) {
            if ($saved[$i] === null) { unset($_ENV[$k]); }
        }
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}" . ($skipped ? "  (skipped: {$skipped})" : '') . "\n";
exit($failed > 0 ? 1 : 0);

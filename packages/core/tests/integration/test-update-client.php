<?php

/**
 * UpdateClient — verifies the self-hosted update trust chain: signed manifest
 * (Ed25519 release key), zip sha256, version compare, and safe install (Zip Slip
 * guarded). No network — manifests are signed in-process with a test keypair.
 *
 * Usage: php packages/core/tests/integration/test-update-client.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\UpdateClient;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$b64url = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

// Release signing keypair (vendor keeps the secret offline; the client embeds pub).
$kp  = sodium_crypto_sign_keypair();
$PUB = base64_encode(sodium_crypto_sign_publickey($kp));
$SEC = sodium_crypto_sign_secretkey($kp);
$KEYS = ['r1' => $PUB];

$mintManifest = function (array $payload, string $kid = 'r1') use ($b64url, $SEC): string {
    $h = $b64url((string) json_encode(['alg' => 'Ed25519', 'kid' => $kid]));
    $p = $b64url((string) json_encode($payload));
    $sig = sodium_crypto_sign_detached($h . '.' . $p, $SEC);
    return $h . '.' . $p . '.' . $b64url($sig);
};

echo "\n{$cyan}══ UpdateClient ══{$reset}\n\n";

test('valid signed manifest → payload returned', function () use ($mintManifest, $KEYS) {
    $c = new UpdateClient($KEYS);
    $m = $mintManifest(['module' => 'optimize', 'version' => '1.1.0', 'url' => 'https://cdn/x.zip', 'sha256' => 'abc']);
    $p = $c->verifyManifest($m);
    assertTrue(is_array($p), 'returns array');
    assertEqual('1.1.0', $p['version']);
    assertEqual('https://cdn/x.zip', $p['url']);
});

test('tampered payload → null', function () use ($mintManifest, $KEYS) {
    $c = new UpdateClient($KEYS);
    $m = $mintManifest(['module' => 'optimize', 'version' => '1.1.0', 'url' => 'https://cdn/x.zip', 'sha256' => 'abc']);
    [$h, , $s] = explode('.', $m);
    $evil = rtrim(strtr(base64_encode((string) json_encode(['module' => 'optimize', 'version' => '9.9.9', 'url' => 'https://evil/x.zip', 'sha256' => 'abc'])), '+/', '-_'), '=');
    assertEqual(null, $c->verifyManifest("$h.$evil.$s"), 'forged payload rejected');
});

test('wrong signing key → null', function () use ($mintManifest) {
    $other = sodium_crypto_sign_keypair();
    $c = new UpdateClient(['r1' => base64_encode(sodium_crypto_sign_publickey($other))]);
    $m = $mintManifest(['module' => 'optimize', 'version' => '1.0.0', 'url' => 'u', 'sha256' => 'h']);
    assertEqual(null, $c->verifyManifest($m), 'signed by a different key → rejected');
});

test('unknown kid / missing url|sha256 / expired → null', function () use ($mintManifest, $KEYS) {
    $c = new UpdateClient($KEYS);
    assertEqual(null, $c->verifyManifest($mintManifest(['version' => '1', 'url' => 'u', 'sha256' => 'h'], 'rX')), 'unknown kid');
    assertEqual(null, $c->verifyManifest($mintManifest(['module' => 'm', 'version' => '1'])), 'missing url+sha256');
    assertEqual(null, $c->verifyManifest($mintManifest(['module' => 'm', 'version' => '1', 'url' => 'u', 'sha256' => 'h', 'expires' => time() - 10])), 'expired manifest');
});

test('verifyZip: sha256 match / mismatch', function () {
    $bytes = 'the real zip bytes';
    assertTrue(UpdateClient::verifyZip($bytes, hash('sha256', $bytes)), 'match');
    assertTrue(!UpdateClient::verifyZip($bytes, hash('sha256', 'other')), 'mismatch rejected');
    assertTrue(!UpdateClient::verifyZip($bytes, ''), 'empty sha rejected');
});

test('isNewer: semver-ish compare (tolerates v prefix)', function () {
    assertTrue(UpdateClient::isNewer('1.1.0', '1.0.9'));
    assertTrue(UpdateClient::isNewer('v2.0.0', '1.9.9'));
    assertTrue(!UpdateClient::isNewer('1.0.0', '1.0.0'));
    assertTrue(!UpdateClient::isNewer('1.0.0', '1.2.0'));
});

test('install: extracts a real zip into dest (atomic swap)', function () {
    $c = new UpdateClient();
    $zipPath = tempnam(sys_get_temp_dir(), 'ffz') . '.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE);
    $z->addFromString('src/OptimizeModule.php', '<?php // module');
    $z->addFromString('composer.json', '{}');
    $z->close();
    $dest = sys_get_temp_dir() . '/ff-upd-dest-' . uniqid();
    @mkdir($dest, 0777, true);
    file_put_contents("$dest/OLD.txt", 'old'); // must be replaced

    $c->install((string) file_get_contents($zipPath), $dest);
    assertTrue(is_file("$dest/src/OptimizeModule.php"), 'new file installed');
    assertTrue(!is_file("$dest/OLD.txt"), 'old contents replaced');
    @unlink($zipPath);
});

test('install: Zip Slip (../) is refused', function () {
    $c = new UpdateClient();
    $zipPath = tempnam(sys_get_temp_dir(), 'ffz') . '.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE);
    $z->addFromString('../escape.php', 'pwned');
    $z->close();
    $dest = sys_get_temp_dir() . '/ff-upd-slip-' . uniqid();
    try {
        $c->install((string) file_get_contents($zipPath), $dest);
        throw new \RuntimeException('expected refusal');
    } catch (ApiException $e) {
        assertEqual('update_unsafe', $e->getErrorCode());
    } finally {
        @unlink($zipPath);
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

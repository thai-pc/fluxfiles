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

test('manifest is bound to the requested module', function () use ($mintManifest, $KEYS) {
    $c = new UpdateClient($KEYS);
    $m = $mintManifest(['module' => 'share', 'version' => '1.1.0', 'url' => 'https://cdn/share.zip', 'sha256' => 'abc']);
    assertEqual(null, $c->verifyManifest($m, null, 'intake'), 'signed manifest for another module is refused');
    assertTrue(is_array($c->verifyManifest($m, null, 'share')), 'matching module is accepted');
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

test('install: a top-level public/ (the module recipient pages) lands beside src/', function () {
    $c = new UpdateClient();
    $zipPath = tempnam(sys_get_temp_dir(), 'ffz') . '.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE);
    $z->addFromString('src/ShareModule.php', '<?php // module');
    $z->addFromString('public/share.html', '<html></html>');
    $z->close();
    $dest = sys_get_temp_dir() . '/ff-upd-pub-' . uniqid() . '/share';
    $c->install((string) file_get_contents($zipPath), $dest);
    assertTrue(is_file("$dest/src/ShareModule.php") && is_file("$dest/public/share.html"), 'src/ and public/ both installed at the module root');
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

// ── core-version gate ────────────────────────────────────────────────────────

test('CoreVersion::satisfies covers ^, ~, >=, ||, wildcard, AND', function () {
    $C = \FluxFiles\CoreVersion::class;
    assertTrue($C::satisfies('1.0.4', '^1.0.4'), '^1.0.4 accepts 1.0.4');
    assertTrue($C::satisfies('1.3.0', '^1.0.4'), '^1.0.4 accepts 1.3.0');
    assertTrue(!$C::satisfies('1.0.3', '^1.0.4'), '^1.0.4 refuses 1.0.3');
    assertTrue(!$C::satisfies('2.0.0', '^1.0.4'), '^1.0.4 refuses 2.0.0');
    assertTrue($C::satisfies('2.1.0', '^1.0.4 || ^2.0'), '|| alternative');
    assertTrue($C::satisfies('1.0.4', '>=1.0.3'), '>=');
    assertTrue($C::satisfies('1.0.4', '>= 1.0.3, <2.0'), 'AND with spaced op');
    assertTrue(!$C::satisfies('1.0.3', '~1.0.4'), '~1.0.4 refuses 1.0.3');
    assertTrue($C::satisfies('1.0.9', '~1.0.4'), '~1.0.4 accepts 1.0.9');
    assertTrue(!$C::satisfies('1.1.0', '~1.0.4'), '~1.0.4 refuses 1.1.0');
    assertTrue($C::satisfies('1.0.9', '1.0.*'), 'wildcard');
    assertTrue(!$C::satisfies('1.1.0', '1.0.*'), 'wildcard upper bound');
    assertTrue($C::satisfies('v1.0.4', '^1.0.4'), 'leading v tolerated');
    assertTrue(!$C::satisfies('1.0.4', 'banana'), 'unparseable constraint fails closed');
    assertTrue(!$C::satisfies('dev-master', '^1.0.4'), 'dev version never satisfies');
    assertTrue($C::satisfies('0.2.5', '^0.2.1') && !$C::satisfies('0.3.0', '^0.2.1'), '0.x caret');
});

test('CoreVersion::normalize rejects dev versions', function () {
    assertEqual('1.0.4', \FluxFiles\CoreVersion::normalize('v1.0.4'));
    assertEqual('1.0.4', \FluxFiles\CoreVersion::normalize('core-v1.0.4'));
    assertEqual(null, \FluxFiles\CoreVersion::normalize('dev-master'));
    assertEqual(null, \FluxFiles\CoreVersion::normalize('1.0.x-dev'));
    $cur = \FluxFiles\CoreVersion::current();
    assertTrue($cur === null || preg_match('/^\d/', $cur) === 1, 'current() is null or numeric');
});

$shareManifest = ['module' => 'share', 'version' => '1.4.0', 'requires_core' => '^1.0.4'];

test('assertCoreCompatible: refuses a core below the floor', function () use ($shareManifest) {
    try {
        UpdateClient::assertCoreCompatible($shareManifest, '1.0.3');
        throw new \RuntimeException('expected refusal');
    } catch (ApiException $e) {
        assertEqual('core_update_required', $e->getErrorCode());
        assertTrue(str_contains($e->getMessage(), 'Update core first'), 'message tells the operator what to do');
    }
    // --force must NOT override a known mismatch.
    try {
        UpdateClient::assertCoreCompatible($shareManifest, '1.0.3', true);
        throw new \RuntimeException('expected refusal even with force');
    } catch (ApiException $e) {
        assertEqual('core_update_required', $e->getErrorCode());
    }
});

test('assertCoreCompatible: accepts at or above the floor, and when no floor is declared', function () use ($shareManifest) {
    assertEqual(null, UpdateClient::assertCoreCompatible($shareManifest, '1.0.4'));
    assertEqual(null, UpdateClient::assertCoreCompatible($shareManifest, '1.2.0'));
    assertEqual(null, UpdateClient::assertCoreCompatible(['module' => 'share', 'version' => '1.0.0'], '0.0.1'), 'legacy catalogue entry');
});

test('assertCoreCompatible: unknown core version warns, and only --force proceeds', function () use ($shareManifest) {
    try {
        UpdateClient::assertCoreCompatible($shareManifest, null);
        throw new \RuntimeException('expected refusal');
    } catch (ApiException $e) {
        assertEqual('core_update_required', $e->getErrorCode());
    }
    $w = UpdateClient::assertCoreCompatible($shareManifest, null, true);
    assertTrue(is_string($w) && str_contains($w, '--force'), 'warning returned');
    assertTrue(is_string(UpdateClient::assertCoreCompatible($shareManifest, 'dev-master', true)), 'dev-* is unknown too');
});

test('requires_core survives the signed manifest round trip', function () use ($mintManifest, $KEYS) {
    $c = new UpdateClient($KEYS);
    $p = $c->verifyManifest($mintManifest(['module' => 'share', 'version' => '1.4.0', 'url' => 'u', 'sha256' => 'h', 'requires_core' => '^1.0.4']));
    assertEqual('^1.0.4', $p['requires_core'] ?? null);
});

// ── update server example: release selection ────────────────────────────────

define('FLUXFILES_UPDATE_SERVER_FUNCTIONS_ONLY', true);
require_once __DIR__ . '/../../../../docs/update-server.example.php';

$rel = static fn (string $v, ?string $core): array => array_filter(['version' => $v, 'zip' => "share-$v.zip", 'sha256' => str_repeat('a', 64), 'requires_core' => $core]);
$entry = $rel('1.4.0', '^1.0.4') + ['releases' => [$rel('1.3.0', '^1.0.3'), $rel('1.2.0', null)]];

test('server: old client (no core_version) is never offered a floor above ^1.0.3', function () use ($entry) {
    assertEqual('1.3.0', fluxfiles_pick_release($entry, null)['version'] ?? null);
    $onlyNew = ['version' => '1.4.0', 'sha256' => 'x', 'requires_core' => '^1.0.4'];
    assertEqual(null, fluxfiles_pick_release($onlyNew, null), 'nothing compatible -> null');
});

test('server: proven core gets the newest compatible release', function () use ($entry) {
    assertEqual('1.4.0', fluxfiles_pick_release($entry, '1.0.4')['version'] ?? null);
    assertEqual('1.3.0', fluxfiles_pick_release($entry, '1.0.3')['version'] ?? null);
    assertEqual('1.4.0', fluxfiles_pick_release($entry, '1.5.0')['version'] ?? null);
    assertEqual('1.3.0', fluxfiles_pick_release($entry, 'dev-master')['version'] ?? null, 'unparseable = unproven');
});

// ── pack-modules: the catalogue carries requires_core ───────────────────────

test('pack-modules reads require[fluxfiles/fluxfiles] from the tagged composer.json into the catalogue', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../../scripts/pack-modules.php');
    assertTrue(str_contains($src, '"{$tag}:composer.json"'), 'reads composer.json at the tag, not the worktree');
    assertTrue(str_contains($src, "['require']['fluxfiles/fluxfiles']"), 'reads the core constraint');
    assertTrue(str_contains($src, "\$entry['requires_core']"), 'writes requires_core into the entry');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

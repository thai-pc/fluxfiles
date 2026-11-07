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

test('CoreVersion::satisfies fails closed (review B3/N6 repros)', function () {
    $C = \FluxFiles\CoreVersion::class;
    assertTrue(!$C::satisfies('2.0.0-rc1', '^1.0.4'), 'next-major prerelease is outside ^1.0.4');
    assertTrue(!$C::satisfies('2.0.0-beta', '^1.0'), 'next-major prerelease is outside ^1.0');
    assertTrue($C::satisfies('1.9.9', '^1.0'), '1.9.9 stays inside ^1.0');
    assertTrue(!$C::satisfies('2.0.0-rc1', '<2.0'), '< bound excludes the prerelease too');
    assertTrue(!$C::satisfies('1.0.4', '1.*.3'), 'number after wildcard is malformed');
    assertTrue(!$C::satisfies('1.2.5', '<1.2.*'), 'wildcard with an operator is malformed');
    assertTrue(!$C::satisfies('1.2.5', '^1.*'), 'caret wildcard is malformed');
    assertTrue(!$C::satisfies('1.0.4', '^1.0.4 || garbage'), 'bad trailing alternative rejects the lot');
    assertTrue(!$C::satisfies('1.0.4', '^1.0.4 ||'), 'empty trailing alternative rejects the lot');
    assertTrue(!$C::satisfies('1.0.4', 'garbage || ^1.0.4'), 'bad leading alternative rejects the lot');
    assertTrue(!$C::satisfies('1.0.4', '^1.0.4 garbage'), 'bad AND term rejects the term list');
    assertTrue($C::satisfies('1.0', '^1.0.0') && $C::satisfies('1', '^1.0'), 'short versions are padded');
    assertTrue($C::satisfies('1.2.5', '1.2.*') && $C::satisfies('1.9.0', '1.*'), 'valid wildcards');
    assertTrue($C::isValid('^0.2.75 || ^1.0') && !$C::isValid('nope') && !$C::isValid('^1.0 ||'), 'isValid mirrors the parser');
});

test('CoreVersion::current honours the FLUXFILES_CORE_VERSION env override', function () {
    $old = $_ENV['FLUXFILES_CORE_VERSION'] ?? null;
    $_ENV['FLUXFILES_CORE_VERSION'] = 'v1.0.6';
    assertEqual('1.0.6', \FluxFiles\CoreVersion::current());
    $_ENV['FLUXFILES_CORE_VERSION'] = 'dev-master';
    $cur = \FluxFiles\CoreVersion::current();
    assertTrue($cur !== 'dev-master', 'invalid override is ignored');
    if ($old === null) { unset($_ENV['FLUXFILES_CORE_VERSION']); } else { $_ENV['FLUXFILES_CORE_VERSION'] = $old; }
});

$packLib = __DIR__ . '/../../../../scripts/pack-modules-lib.php';
if (is_file($packLib)) {
    require_once $packLib;

    test('pack-modules: requires_core must be present and parseable', function () {
        assertEqual('^1.0.4', fluxfiles_pack_requires_core('{"require":{"fluxfiles/fluxfiles":"^1.0.4"}}'));
        assertEqual(null, fluxfiles_pack_requires_core(null), 'unreadable');
        assertEqual(null, fluxfiles_pack_requires_core('not json'), 'invalid json');
        assertEqual(null, fluxfiles_pack_requires_core('{"require":{"php":"^8.1"}}'), 'missing key');
        assertEqual(null, fluxfiles_pack_requires_core('{"require":{"fluxfiles/fluxfiles":"banana"}}'), 'unparseable');
    });

    test('pack-modules: history backfills missing floors and drops what it cannot', function () {
        $entry = ['version' => '1.4.0', 'zip' => 'share-1.4.0.zip', 'sha256' => 'a', 'requires_core' => '^1.0.4'];
        $prior = ['version' => '1.3.1', 'zip' => 'share-1.3.1.zip', 'sha256' => 'b', 'releases' => [
            ['version' => '1.3.0', 'zip' => 'share-1.3.0.zip', 'sha256' => 'c', 'requires_core' => '^1.0'],
            ['version' => '1.2.0', 'zip' => 'share-1.2.0.zip', 'sha256' => 'd'],           // backfill fails -> dropped
            ['version' => '1.1.0', 'sha256' => 'e'],                                         // no zip -> dropped
            ['version' => '1.4.0', 'zip' => 'dup.zip', 'sha256' => 'f', 'requires_core' => '^1.0'], // same as entry -> dropped
        ]];
        $asked = [];
        $h = fluxfiles_pack_history($entry, $prior, function (string $v) use (&$asked): ?string {
            $asked[] = $v;
            return $v === '1.3.1' ? '{"require":{"fluxfiles/fluxfiles":"^0.2.75"}}' : null;
        });
        assertEqual(['1.3.1', '1.3.0'], array_column($h, 'version'), 'order + survivors');
        assertEqual('^0.2.75', $h[0]['requires_core'], 'backfilled from the release composer.json');
        assertEqual('^1.0', $h[1]['requires_core'], 'recorded floor kept');
        assertTrue(!in_array('1.3.0', $asked, true), 'no backfill needed when the floor is recorded');
        assertEqual([], fluxfiles_pack_history($entry, null, fn () => null), 'no prior -> no history');
    });
}

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

// ── plugin bundle entitlement (FluxFiles Pro for WordPress) ─────────────────

$bundleKp = sodium_crypto_sign_keypair();
$bundlePub = ['t1' => base64_encode(sodium_crypto_sign_publickey($bundleKp))];
$bundleLm = static function (array $modules) use ($bundleKp, $bundlePub): \FluxFiles\LicenseManager {
    $u = static fn (string $x): string => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $h = $u((string) json_encode(['alg' => 'Ed25519', 'kid' => 't1']));
    $p = $u((string) json_encode(['edition' => 'pro', 'modules' => $modules, 'jti' => str_repeat('a', 24), 'expires' => time() + 86400]));
    $sig = $u(sodium_crypto_sign_detached($h . '.' . $p, sodium_crypto_sign_secretkey($bundleKp)));
    return new \FluxFiles\LicenseManager("$h.$p.$sig", $bundlePub);
};
$bundles = ['fluxfiles-pro' => ['share', 'intake']];

test('bundle: entitled when the licence covers ANY bundled module', function () use ($bundleLm, $bundles) {
    assertTrue(fluxfiles_entitled('fluxfiles-pro', $bundleLm(['share', 'intake']), $bundles));
    assertTrue(fluxfiles_entitled('fluxfiles-pro', $bundleLm(['intake']), $bundles));
    assertTrue(fluxfiles_entitled('fluxfiles-pro', $bundleLm(['share', 'versioning', 'ai']), $bundles));
});

test('bundle: a licence with none of the bundled modules (support, versioning only, free) is refused', function () use ($bundleLm, $bundles) {
    assertEqual(false, fluxfiles_entitled('fluxfiles-pro', $bundleLm([]), $bundles), 'support plan: modules []');
    assertEqual(false, fluxfiles_entitled('fluxfiles-pro', $bundleLm(['versioning', 'webhooks']), $bundles));
    assertEqual(false, fluxfiles_entitled('fluxfiles-pro', new \FluxFiles\LicenseManager('garbage'), $bundles));
    assertEqual(false, fluxfiles_entitled('fluxfiles-pro', new \FluxFiles\LicenseManager(''), $bundles));
});

test('bundle: a plain module id behaves exactly as before, and the bundle id is not a module', function () use ($bundleLm, $bundles) {
    assertTrue(fluxfiles_entitled('share', $bundleLm(['share']), $bundles));
    assertEqual(false, fluxfiles_entitled('versioning', $bundleLm(['share']), $bundles));
    assertEqual(false, fluxfiles_entitled('fluxfiles-pro', $bundleLm(['share']), []), 'no bundle map -> plain licensed(), false for a bundle id');
});

test('bundle: install mode serves the newest release regardless of the (unreported) core', function () {
    $e = ['version' => '1.1.0', 'zip' => 'p-1.1.0.zip', 'sha256' => str_repeat('b', 64), 'requires_core' => '^1.1.3'];
    assertEqual(null, fluxfiles_pick_release($e, null), 'default: unreported core = 1.0.3, floor above it');
    assertEqual('1.1.0', fluxfiles_pick_release($e, null, true)['version'] ?? null);
});

// ── pack-modules: the catalogue carries requires_core ───────────────────────

test('pack-modules reads require[fluxfiles/fluxfiles] from the tagged composer.json into the catalogue', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../../scripts/pack-modules.php');
    assertTrue(str_contains($src, '"{$tag}:composer.json"'), 'reads composer.json at the tag, not the worktree');
    assertTrue(str_contains($src, 'fluxfiles_pack_requires_core('), 'parses the core constraint via the tested helper');
    assertTrue(str_contains($src, 'fluxfiles_pack_history('), 'history goes through the tested helper');
    assertTrue(str_contains($src, "\$entry['requires_core']"), 'writes requires_core into the entry');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

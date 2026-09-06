<?php

/**
 * Compliance Readiness Scorecard (docs/COMPLIANCE-SCORECARD-DESIGN.md). Free/core,
 * stateless: no storage, no new JWT claim. This tests `ComplianceScorecard::build()`
 * directly — the route-level 403/envelope behavior is covered in
 * tests/integration/test-compliance-scorecard.php.
 *
 * Usage: php packages/core/tests/unit/test-compliance-scorecard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\ComplianceScorecard;
use FluxFiles\LicenseManager;
use FluxFiles\ModuleInterface;
use FluxFiles\ModuleRegistry;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertFalse($c, string $m = ''): void { if ($c) throw new \RuntimeException($m ?: 'expected false'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** A fake paid module, registered under whichever id the test needs. */
final class FakeComplianceModule implements ModuleInterface
{
    public static function id(): string { return 'fake-compliance'; }
    public static function claim(): string { return ''; }
}

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mintLicense(string $sec, array $payload): string
{
    $h = b64url(json_encode(['alg' => 'Ed25519', 'kid' => 'test']));
    $p = b64url(json_encode($payload));
    return $h . '.' . $p . '.' . b64url(sodium_crypto_sign_detached("$h.$p", $sec));
}

$kp = sodium_crypto_sign_keypair();
$SEC = sodium_crypto_sign_secretkey($kp);
$KEYS = ['test' => base64_encode(sodium_crypto_sign_publickey($kp))];
$NOW = 1_800_000_000;

/** Free (unlicensed) LicenseManager. */
function freeLicense(): LicenseManager
{
    return new LicenseManager(null);
}
/** LicenseManager licensed for the given module ids. */
function licensedFor(array $modules, string $sec, array $keys, int $now): LicenseManager
{
    return new LicenseManager(mintLicense($sec, ['edition' => 'enterprise', 'modules' => $modules, 'expires' => $now + 86400]), $keys, $now);
}

$ALL_IDS = ['virus_scan', 'c2pa', 'audit_export', 'sso', 'dlp_scan', 'legal_hold'];
$ALL_MODULES = ['virus', 'c2pa', 'audit-export', 'sso', 'dlp', 'legal-hold'];

echo "\n{$cyan}══ Compliance Readiness Scorecard ══{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► Shape: six rows, stable order, no leaking a certification field{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('always returns exactly 6 items, ids match the static table, in stable order', function () use ($ALL_IDS) {
    $claims = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read', 'audit']]);
    $result = ComplianceScorecard::build($claims, freeLicense());
    assertEqual(6, count($result['items']), 'six rows');
    $ids = array_column($result['items'], 'id');
    assertEqual($ALL_IDS, $ids, 'ids + order match the design doc table');
});

test('categories array lists all 6 categories in row order', function () {
    $claims = Claims::fromJwtPayload((object) ['sub' => 'u']);
    $result = ComplianceScorecard::build($claims, freeLicense());
    assertEqual(
        ['content_security', 'content_provenance', 'audit_retention', 'identity_access', 'data_protection', 'legal_ediscovery'],
        $result['categories']
    );
});

/** Recursively assert a forbidden key name never appears anywhere in the response. */
function assertNoForbiddenKeys(array $arr, array $forbidden, string $path = 'root'): void
{
    foreach ($arr as $k => $v) {
        if (is_string($k)) {
            foreach ($forbidden as $bad) {
                if (strcasecmp($k, $bad) === 0) {
                    throw new \RuntimeException("forbidden key '{$k}' found at {$path}");
                }
            }
        }
        if (is_array($v)) {
            assertNoForbiddenKeys($v, $forbidden, $path . '.' . $k);
        }
    }
}

test('response never contains score/percent/compliant anywhere (liability guardrail, §3)', function () use ($SEC, $KEYS, $NOW, $ALL_MODULES) {
    ModuleRegistry::reset();
    foreach (['virus', 'c2pa', 'audit-export', 'dlp', 'legal-hold', 'sso'] as $mod) {
        ModuleRegistry::register($mod, FakeComplianceModule::class);
    }
    try {
        $claims = Claims::fromJwtPayload((object) [
            'sub' => 'u', 'allow_virus_scan' => true, 'allow_c2pa' => true, 'allow_dlp_scan' => true, 'allow_legal_hold' => true, 'allow_audit_export' => true,
        ]);
        $result = ComplianceScorecard::build($claims, licensedFor($ALL_MODULES, $SEC, $KEYS, $NOW));
        assertNoForbiddenKeys($result, ['score', 'percent', 'compliant']);
    } finally { ModuleRegistry::reset(); }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► available = installed AND licensed (real modules){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('available is false for virus/c2pa/audit-export on an unlicensed (free) server even if installed', function () {
    ModuleRegistry::reset();
    ModuleRegistry::register('virus', FakeComplianceModule::class);
    ModuleRegistry::register('c2pa', FakeComplianceModule::class);
    ModuleRegistry::register('audit-export', FakeComplianceModule::class);
    try {
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u']);
        $result = ComplianceScorecard::build($claims, freeLicense());
        $byId = [];
        foreach ($result['items'] as $item) { $byId[$item['id']] = $item; }
        assertFalse($byId['virus_scan']['available'], 'virus_scan not available on free license');
        assertFalse($byId['c2pa']['available'], 'c2pa not available on free license');
        assertFalse($byId['audit_export']['available'], 'audit_export not available on free license');
        assertEqual('not_licensed', $byId['virus_scan']['why_not']);
        assertEqual('locked', $byId['virus_scan']['status']);
    } finally { ModuleRegistry::reset(); }
});

test('available is true only when BOTH installed AND licensed', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::reset();
    try {
        // Licensed for 'virus' but NOT installed (no class registered) → still false.
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u']);
        $result = ComplianceScorecard::build($claims, licensedFor(['virus'], $SEC, $KEYS, $NOW));
        $byId = [];
        foreach ($result['items'] as $item) { $byId[$item['id']] = $item; }
        assertFalse($byId['virus_scan']['available'], 'licensed but not installed → still unavailable');
        assertEqual('not_installed', $byId['virus_scan']['why_not']);

        // Now install it too → available flips true.
        ModuleRegistry::register('virus', FakeComplianceModule::class);
        $result2 = ComplianceScorecard::build($claims, licensedFor(['virus'], $SEC, $KEYS, $NOW));
        $byId2 = [];
        foreach ($result2['items'] as $item) { $byId2[$item['id']] = $item; }
        assertTrue($byId2['virus_scan']['available'], 'installed + licensed → available');
    } finally { ModuleRegistry::reset(); }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► enabled tracks the Claims boolean, independent of available{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('enabled for virus_scan/c2pa/audit_export tracks the Claims boolean exactly', function () {
    $on = Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_virus_scan' => true, 'allow_c2pa' => true, 'allow_audit_export' => true]);
    $off = Claims::fromJwtPayload((object) ['sub' => 'u']);
    $resultOn = ComplianceScorecard::build($on, freeLicense());
    $resultOff = ComplianceScorecard::build($off, freeLicense());
    $byIdOn = []; foreach ($resultOn['items'] as $i) { $byIdOn[$i['id']] = $i; }
    $byIdOff = []; foreach ($resultOff['items'] as $i) { $byIdOff[$i['id']] = $i; }
    foreach (['virus_scan', 'c2pa', 'audit_export'] as $id) {
        assertTrue($byIdOn[$id]['enabled'], "{$id} enabled when claim on");
        assertFalse($byIdOff[$id]['enabled'], "{$id} disabled when claim off/absent");
    }
});

test('a claim can be true on an UNLICENSED server and still report enabled:true, available:false, status:locked', function () {
    ModuleRegistry::reset();
    try {
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_c2pa' => true]);
        $result = ComplianceScorecard::build($claims, freeLicense());
        $byId = []; foreach ($result['items'] as $i) { $byId[$i['id']] = $i; }
        assertTrue($byId['c2pa']['enabled'], 'claim is on');
        assertFalse($byId['c2pa']['available'], 'server has no license/module');
        assertEqual('locked', $byId['c2pa']['status'], 'claim-on-but-cannot-run is locked, not off');
        // No claim_snippet on a locked row — the snippet is for the "off" case only.
        assertEqual(null, $byId['c2pa']['claim_snippet']);
    } finally { ModuleRegistry::reset(); }
});

test('an "off" row (installed + licensed, claim off) carries a copy-paste claim_snippet', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::reset();
    ModuleRegistry::register('c2pa', FakeComplianceModule::class);
    try {
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u']); // allow_c2pa absent → false
        $result = ComplianceScorecard::build($claims, licensedFor(['c2pa'], $SEC, $KEYS, $NOW));
        $byId = []; foreach ($result['items'] as $i) { $byId[$i['id']] = $i; }
        assertEqual('off', $byId['c2pa']['status']);
        assertEqual('claim_off', $byId['c2pa']['why_not']);
        assertEqual("'claims' => ['allow_c2pa' => true]", $byId['c2pa']['claim_snippet']);
        assertEqual(null, $byId['c2pa']['docs_url'], 'docs_url only set for locked rows');
    } finally { ModuleRegistry::reset(); }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► SSO row: enabled reads FLUXFILES_SSO_ENABLED env, NOT a Claims field{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('sso.enabled is driven by the env var, independent of any claim on the token', function () {
    $claims = Claims::fromJwtPayload((object) ['sub' => 'u']); // no sso-related claim exists at all

    $prev = $_ENV['FLUXFILES_SSO_ENABLED'] ?? null;
    try {
        $_ENV['FLUXFILES_SSO_ENABLED'] = 'true';
        $resultOn = ComplianceScorecard::build($claims, freeLicense());
        $byIdOn = []; foreach ($resultOn['items'] as $i) { $byIdOn[$i['id']] = $i; }
        assertTrue($byIdOn['sso']['enabled'], 'env=true → enabled');

        $_ENV['FLUXFILES_SSO_ENABLED'] = 'false';
        $resultOff = ComplianceScorecard::build($claims, freeLicense());
        $byIdOff = []; foreach ($resultOff['items'] as $i) { $byIdOff[$i['id']] = $i; }
        assertFalse($byIdOff['sso']['enabled'], 'env=false → disabled');

        unset($_ENV['FLUXFILES_SSO_ENABLED']);
        $resultAbsent = ComplianceScorecard::build($claims, freeLicense());
        $byIdAbsent = []; foreach ($resultAbsent['items'] as $i) { $byIdAbsent[$i['id']] = $i; }
        assertFalse($byIdAbsent['sso']['enabled'], 'env absent → disabled (fail-closed default)');
    } finally {
        if ($prev === null) { unset($_ENV['FLUXFILES_SSO_ENABLED']); } else { $_ENV['FLUXFILES_SSO_ENABLED'] = $prev; }
    }
});

test('sso row has claim:null and never carries a claim_snippet even when off', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::reset();
    ModuleRegistry::register('sso', FakeComplianceModule::class);
    try {
        $prev = $_ENV['FLUXFILES_SSO_ENABLED'] ?? null;
        $_ENV['FLUXFILES_SSO_ENABLED'] = 'false';
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u']);
        $result = ComplianceScorecard::build($claims, licensedFor(['sso'], $SEC, $KEYS, $NOW));
        $byId = []; foreach ($result['items'] as $i) { $byId[$i['id']] = $i; }
        assertEqual(null, $byId['sso']['claim']);
        assertEqual('off', $byId['sso']['status']);
        assertEqual(null, $byId['sso']['claim_snippet'], 'no claim to snippet-ify for SSO');
        if ($prev === null) { unset($_ENV['FLUXFILES_SSO_ENABLED']); } else { $_ENV['FLUXFILES_SSO_ENABLED'] = $prev; }
    } finally { ModuleRegistry::reset(); }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► dlp_scan / legal_hold degrade gracefully ahead of those modules landing{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('with neither module registered, dlp_scan/legal_hold are always locked/not_installed', function () {
    ModuleRegistry::reset();
    // Deliberately not registering fake classes for 'dlp'/'legal-hold' — this locks
    // in the graceful-degradation behavior even though both are real claims already.
    $claims = Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_dlp_scan' => true, 'allow_legal_hold' => true]);
    $result = ComplianceScorecard::build($claims, freeLicense());
    $byId = []; foreach ($result['items'] as $i) { $byId[$i['id']] = $i; }
    foreach (['dlp_scan', 'legal_hold'] as $id) {
        assertFalse($byId[$id]['available'], "{$id} not available when module absent");
        assertEqual('not_installed', $byId[$id]['why_not'], "{$id} why_not");
        assertEqual('locked', $byId[$id]['status'], "{$id} status");
        // enabled still tracks the claim independently, per the design's own rule —
        // NOT collapsed to false just because the module is absent.
        assertTrue($byId[$id]['enabled'], "{$id} enabled still tracks the claim");
    }
});

test('registering the real dlp/legal-hold module classes flips available under a covering license', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::reset();
    ModuleRegistry::register('dlp', FakeComplianceModule::class);
    ModuleRegistry::register('legal-hold', FakeComplianceModule::class);
    try {
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_dlp_scan' => true, 'allow_legal_hold' => true]);
        $result = ComplianceScorecard::build($claims, licensedFor(['dlp', 'legal-hold'], $SEC, $KEYS, $NOW));
        $byId = []; foreach ($result['items'] as $i) { $byId[$i['id']] = $i; }
        assertTrue($byId['dlp_scan']['available']);
        assertTrue($byId['legal_hold']['available']);
        assertEqual('on', $byId['dlp_scan']['status']);
        assertEqual('on', $byId['legal_hold']['status']);
    } finally { ModuleRegistry::reset(); }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► summary arithmetic matches the items array exactly{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('summary.enabled_count/available_count/total_count match a hand count of items', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::reset();
    ModuleRegistry::register('virus', FakeComplianceModule::class);
    ModuleRegistry::register('c2pa', FakeComplianceModule::class);
    try {
        $claims = Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_virus_scan' => true, 'allow_c2pa' => false]);
        $result = ComplianceScorecard::build($claims, licensedFor(['virus'], $SEC, $KEYS, $NOW));
        $enabled = 0; $available = 0;
        foreach ($result['items'] as $i) {
            if ($i['enabled']) { $enabled++; }
            if ($i['available']) { $available++; }
        }
        assertEqual($enabled, $result['summary']['enabled_count'], 'enabled_count matches hand count');
        assertEqual($available, $result['summary']['available_count'], 'available_count matches hand count');
        assertEqual(count($result['items']), $result['summary']['total_count'], 'total_count matches count(items)');
        assertEqual(6, $result['summary']['total_count']);
    } finally { ModuleRegistry::reset(); }
});

test('generated_at is a fresh unix timestamp and disclaimer is always present/non-empty', function () {
    $before = time();
    $claims = Claims::fromJwtPayload((object) ['sub' => 'u']);
    $result = ComplianceScorecard::build($claims, freeLicense());
    $after = time();
    assertTrue($result['generated_at'] >= $before && $result['generated_at'] <= $after, 'generated_at is fresh');
    assertTrue(is_string($result['disclaimer']) && $result['disclaimer'] !== '', 'disclaimer present');
    // Liability framing (§3): never a certifying phrase.
    foreach (['compliant', 'certified', 'audit-passed', 'compliance score'] as $bad) {
        assertFalse(stripos($result['disclaimer'], $bad) !== false, "disclaimer must not contain '{$bad}'");
    }
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

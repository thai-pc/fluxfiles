<?php

/**
 * Module gate (Phase 2 M1). ModuleRegistry enforces the three-layer gate for paid
 * modules (capability `class_exists` · license · claim). The first-party modules
 * (e.g. optimize) ship in proprietary packages absent from this MIT checkout, so we
 * register a FAKE module to drive every gate path without depending on them.
 *
 * Usage: php tests/integration/test-modules.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\LicenseManager;
use FluxFiles\ModuleRegistry;
use FluxFiles\ModuleInterface;
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
/** @return ApiException */
function expectApi(callable $f, int $code, string $errCode) {
    try { $f(); throw new \RuntimeException("expected {$code}/{$errCode}, no throw"); }
    catch (ApiException $e) {
        assertEqual($code, $e->getHttpCode(), 'http code');
        assertEqual($errCode, $e->getErrorCode(), 'error code');
        return $e;
    }
}

/** A fake paid module with a claim, registered under id 'demo'. */
final class FakeModule implements ModuleInterface {
    public static function id(): string { return 'demo'; }
    public static function claim(): string { return 'allow_optimize'; } // reuse a real claim
    public function run(): string { return 'ran'; }
}

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mintLicense(string $sec, array $payload): string {
    $h = b64url(json_encode(['alg' => 'Ed25519', 'kid' => 'test']));
    $p = b64url(json_encode($payload));
    return $h . '.' . $p . '.' . b64url(sodium_crypto_sign_detached("$h.$p", $sec));
}

$kp = sodium_crypto_sign_keypair();
$SEC = sodium_crypto_sign_secretkey($kp);
$KEYS = ['test' => base64_encode(sodium_crypto_sign_publickey($kp))];
$NOW = 1_800_000_000;

/** A LicenseManager that licenses the given modules (or free when null). */
function lic(?array $modules, string $sec, array $keys, int $now): LicenseManager {
    if ($modules === null) {
        return new LicenseManager(null, $keys, $now); // free
    }
    return new LicenseManager(mintLicense($sec, ['edition' => 'pro', 'modules' => $modules, 'expires' => $now + 86400]), $keys, $now);
}
/** Claims with allow_optimize on/off. */
function claims(bool $allowOptimize): Claims {
    return Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_optimize' => $allowOptimize]);
}

echo "\n{$cyan}══ Module gate — ModuleRegistry (M1) ══{$reset}\n\n";

test('layer 3 (claim): allow_optimize parses; isAllowed maps it', function () {
    assertEqual(false, claims(false)->allowOptimize);
    assertEqual(true, claims(true)->allowOptimize);
    assertEqual(true, claims(true)->isAllowed('allow_optimize'));
    assertEqual(false, claims(true)->isAllowed('allow_nonexistent'), 'unknown claim → fail-closed');
});

test('layer 3: isAllowed maps EVERY paid-module claim (gate would 403 all otherwise)', function () {
    foreach (['allow_share', 'allow_intake', 'allow_ai_vision', 'allow_ocr', 'allow_virus_scan', 'allow_backup', 'allow_c2pa'] as $cl) {
        $on = Claims::fromJwtPayload((object) ['sub' => 'u', $cl => true]);
        $off = Claims::fromJwtPayload((object) ['sub' => 'u']);
        assertEqual(true, $on->isAllowed($cl), "{$cl} true → allowed");
        assertEqual(false, $off->isAllowed($cl), "{$cl} default → denied");
    }
});

test('layer 1: unknown / not-installed module → 501 module_not_installed', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::reset();
    // 'demo' isn't in the built-in map and no class is registered for it.
    expectApi(fn () => ModuleRegistry::require('demo', lic(['demo'], $SEC, $KEYS, $NOW), claims(true)), 501, 'module_not_installed');
    // A canonical paid id ('share') IS mapped but its class isn't installed here.
    assertEqual(false, ModuleRegistry::installed('share'), 'share not installed in MIT checkout');
    expectApi(fn () => ModuleRegistry::require('share', lic(['share'], $SEC, $KEYS, $NOW), claims(true)), 501, 'module_not_installed');
});

test('layer 2: installed but unlicensed → 402 license_required', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::register('demo', FakeModule::class);
    try {
        // free license (no modules)
        $e = expectApi(fn () => ModuleRegistry::require('demo', lic(null, $SEC, $KEYS, $NOW), claims(true)), 402, 'license_required');
        assertEqual('free', $e->getErrorParams()['status'] ?? null);
        // licensed for a DIFFERENT module
        expectApi(fn () => ModuleRegistry::require('demo', lic(['optimize'], $SEC, $KEYS, $NOW), claims(true)), 402, 'license_required');
    } finally { ModuleRegistry::reset(); }
});

test('layer 2: SUBSCRIPTION expired past grace → 402 license_expired', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::register('demo', FakeModule::class);
    try {
        // enforcement=subscription so expiry hard-disables (perpetual would keep running).
        $expired = new LicenseManager(mintLicense($SEC, ['edition' => 'pro', 'modules' => ['demo'], 'enforcement' => 'subscription', 'expires' => $NOW - 60 * 86400, 'grace' => 14 * 86400]), $KEYS, $NOW);
        expectApi(fn () => ModuleRegistry::require('demo', $expired, claims(true)), 402, 'license_expired');
    } finally { ModuleRegistry::reset(); }
});

test('layer 2: PERPETUAL expired past grace → still resolves (runs forever)', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::register('demo', FakeModule::class);
    try {
        $perp = new LicenseManager(mintLicense($SEC, ['edition' => 'pro', 'modules' => ['demo'], 'expires' => $NOW - 60 * 86400, 'grace' => 14 * 86400]), $KEYS, $NOW);
        $mod = ModuleRegistry::require('demo', $perp, claims(true));
        assertTrue($mod instanceof FakeModule, 'perpetual licence still resolves after expiry');
    } finally { ModuleRegistry::reset(); }
});

test('layer 3: installed + licensed but claim off → 403 allow_optimize_forbidden', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::register('demo', FakeModule::class);
    try {
        expectApi(fn () => ModuleRegistry::require('demo', lic(['demo'], $SEC, $KEYS, $NOW), claims(false)), 403, 'allow_optimize_forbidden');
    } finally { ModuleRegistry::reset(); }
});

test('all three layers pass → the module instance is returned', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::register('demo', FakeModule::class);
    try {
        assertTrue(ModuleRegistry::installed('demo'), 'installed');
        $m = ModuleRegistry::require('demo', lic(['demo'], $SEC, $KEYS, $NOW), claims(true));
        assertTrue($m instanceof FakeModule, 'returns the module');
        assertEqual('ran', $m->run());
    } finally { ModuleRegistry::reset(); }
});

test('license grace still serves the module (active during grace)', function () use ($SEC, $KEYS, $NOW) {
    ModuleRegistry::register('demo', FakeModule::class);
    try {
        $grace = new LicenseManager(mintLicense($SEC, ['edition' => 'pro', 'modules' => ['demo'], 'expires' => $NOW - 86400, 'grace' => 14 * 86400]), $KEYS, $NOW);
        $m = ModuleRegistry::require('demo', $grace, claims(true));
        assertTrue($m instanceof FakeModule, 'served during grace');
    } finally { ModuleRegistry::reset(); }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

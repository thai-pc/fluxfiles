<?php

/**
 * License verifier (M0). LicenseManager only ships a PUBLIC key and verifies; the
 * test plays the role of `license-gen` by generating an ephemeral Ed25519 keypair,
 * signing licenses with the secret half, and injecting the public half so every
 * path is exercised without the real production signing key.
 *
 * Usage: php tests/integration/test-license.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\LicenseManager;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertFalse($c, string $m = ''): void { if ($c) throw new \RuntimeException($m ?: 'expected false'); }

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Mint a signed license like `license-gen` will (kid 'test'). */
function mintLicense(string $secretKey, array $payload, string $kid = 'test', string $alg = 'Ed25519'): string {
    $h = b64url(json_encode(['alg' => $alg, 'kid' => $kid]));
    $p = b64url(json_encode($payload));
    $sig = sodium_crypto_sign_detached($h . '.' . $p, $secretKey);
    return $h . '.' . $p . '.' . b64url($sig);
}

// Ephemeral keypair → its public half is injected as the 'test' kid.
$kp  = sodium_crypto_sign_keypair();
$PUB = sodium_crypto_sign_publickey($kp);
$SEC = sodium_crypto_sign_secretkey($kp);
$KEYS = ['test' => base64_encode($PUB)];
$NOW = 1_800_000_000;

echo "\n{$cyan}══ License verifier (M0) ══{$reset}\n\n";

test('no key → free edition (core must run unlicensed)', function () use ($KEYS, $NOW) {
    $l = new LicenseManager(null, $KEYS, $NOW);
    assertEqual('free', $l->edition());
    assertEqual('free', $l->status());
    assertEqual([], $l->modules());
    assertFalse($l->licensed('optimize'));
});

test('valid Pro license → edition + modules + active status', function () use ($SEC, $KEYS, $NOW) {
    $key = mintLicense($SEC, [
        'customer' => 'acme', 'edition' => 'pro', 'modules' => ['optimize', 'share'],
        'limits' => ['sites' => 5], 'issued' => $NOW - 86400, 'expires' => $NOW + 30 * 86400,
    ]);
    $l = new LicenseManager($key, $KEYS, $NOW);
    assertEqual('pro', $l->edition());
    assertEqual('active', $l->status());
    assertTrue($l->licensed('optimize'), 'optimize licensed');
    assertTrue($l->licensed('share'), 'share licensed');
    assertFalse($l->licensed('ai'), 'ai not in modules');
    assertEqual(['sites' => 5], $l->limits());
    assertEqual(30, $l->daysLeft());
});

test('tampered payload → rejected (falls back to free)', function () use ($SEC, $KEYS, $NOW) {
    $key = mintLicense($SEC, ['edition' => 'pro', 'modules' => ['optimize'], 'expires' => $NOW + 86400]);
    // Flip the edition to "enterprise" in the payload segment without re-signing.
    [$h, $p, $s] = explode('.', $key);
    $forged = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    $forged['edition'] = 'enterprise'; $forged['modules'] = ['optimize', 'ai', 'enterprise'];
    $p2 = rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=');
    $l = new LicenseManager("$h.$p2.$s", $KEYS, $NOW);
    assertEqual('free', $l->edition(), 'forged license must not verify');
    assertFalse($l->licensed('ai'));
});

test('wrong signing key → rejected', function () use ($KEYS, $NOW) {
    $other = sodium_crypto_sign_keypair();
    $key = mintLicense(sodium_crypto_sign_secretkey($other), ['edition' => 'pro', 'modules' => ['optimize'], 'expires' => $NOW + 86400]);
    $l = new LicenseManager($key, $KEYS, $NOW); // verified against the real test pubkey
    assertEqual('free', $l->edition());
});

test('unknown kid → rejected', function () use ($SEC, $KEYS, $NOW) {
    $key = mintLicense($SEC, ['edition' => 'pro', 'modules' => ['optimize'], 'expires' => $NOW + 86400], 'k999');
    assertEqual('free', (new LicenseManager($key, $KEYS, $NOW))->edition());
});

test('malformed keys → free, never throws', function () use ($KEYS, $NOW) {
    foreach (['', 'garbage', 'a.b', 'a.b.c.d', 'not-base64.@@@.$$$'] as $bad) {
        assertEqual('free', (new LicenseManager($bad, $KEYS, $NOW))->edition(), "bad: {$bad}");
    }
});

test('expired but within grace → status grace, module still serviceable', function () use ($SEC, $KEYS, $NOW) {
    $key = mintLicense($SEC, [
        'edition' => 'pro', 'modules' => ['optimize'],
        'expires' => $NOW - 86400, 'grace' => 14 * 86400, // expired 1d ago, 14d grace
    ]);
    $l = new LicenseManager($key, $KEYS, $NOW);
    assertTrue($l->expired(), 'is expired');
    assertTrue($l->inGrace(), 'in grace');
    assertEqual('grace', $l->status());
    assertTrue($l->licensed('optimize'), 'still serviceable during grace');
    assertEqual(-1, $l->daysLeft());
});

test('expired beyond grace → hard expired, modules disabled', function () use ($SEC, $KEYS, $NOW) {
    $key = mintLicense($SEC, [
        'edition' => 'pro', 'modules' => ['optimize'],
        'expires' => $NOW - 30 * 86400, 'grace' => 14 * 86400,
    ]);
    $l = new LicenseManager($key, $KEYS, $NOW);
    assertTrue($l->hardExpired(), 'hard expired');
    assertEqual('expired', $l->status());
    assertFalse($l->licensed('optimize'), 'module disabled past grace');
});

test('default grace (14d) applies when grace is omitted', function () use ($SEC, $KEYS, $NOW) {
    $within = new LicenseManager(mintLicense($SEC, ['edition' => 'pro', 'modules' => ['x'], 'expires' => $NOW - 10 * 86400]), $KEYS, $NOW);
    assertEqual('grace', $within->status(), '10d past, default 14d grace');
    $beyond = new LicenseManager(mintLicense($SEC, ['edition' => 'pro', 'modules' => ['x'], 'expires' => $NOW - 20 * 86400]), $KEYS, $NOW);
    assertEqual('expired', $beyond->status(), '20d past default grace');
});

test('perpetual license (no expires) → active forever', function () use ($SEC, $KEYS, $NOW) {
    $l = new LicenseManager(mintLicense($SEC, ['edition' => 'enterprise', 'modules' => ['enterprise']]), $KEYS, $NOW + 10 * 365 * 86400);
    assertEqual('active', $l->status());
    assertEqual(null, $l->daysLeft());
    assertTrue($l->licensed('enterprise'));
});

test('info() returns a non-sensitive summary', function () use ($SEC, $KEYS, $NOW) {
    $l = new LicenseManager(mintLicense($SEC, ['customer' => 'acme', 'edition' => 'agency', 'modules' => ['optimize', 'share'], 'limits' => ['sites' => 10], 'expires' => $NOW + 5 * 86400]), $KEYS, $NOW);
    $i = $l->info();
    assertEqual(['edition', 'status', 'modules', 'limits', 'expires', 'days_left'], array_keys($i));
    assertEqual('agency', $i['edition']);
    assertEqual(5, $i['days_left']);
    assertTrue(!array_key_exists('customer', $i), 'customer name not leaked');
});

test('fromEnv reads FLUXFILES_LICENSE_KEY (real embedded public key path)', function () use ($NOW) {
    $saved = $_ENV['FLUXFILES_LICENSE_KEY'] ?? null;
    $_ENV['FLUXFILES_LICENSE_KEY'] = 'not-a-real-license';
    try {
        // Uses the EMBEDDED production key map; garbage → free, no throw.
        assertEqual('free', LicenseManager::fromEnv(null, $NOW)->edition());
    } finally {
        if ($saved === null) { unset($_ENV['FLUXFILES_LICENSE_KEY']); } else { $_ENV['FLUXFILES_LICENSE_KEY'] = $saved; }
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

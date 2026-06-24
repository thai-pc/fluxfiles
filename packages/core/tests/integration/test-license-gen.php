<?php

/**
 * license-gen (vendor tool) → LicenseManager (core verifier) round-trip. Proves a
 * key minted by scripts/license-gen.php verifies offline with the matching public
 * key, carrying edition/modules/enforcement/expiry intact.
 *
 * Usage: php packages/core/tests/integration/test-license-gen.php
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

$GEN = __DIR__ . '/../../../../scripts/license-gen.php';
if (!is_file($GEN)) {
    fwrite(STDERR, "skip: scripts/license-gen.php not found\n");
    exit(0);
}

/** Run license-gen with args (+ optional secret env), return trimmed stdout. */
function gen(array $args, ?string $secretB64 = null): string {
    global $GEN;
    // env=null inherits the parent env (keeps PATH so `php` resolves); only build a
    // full env array when we need to inject the secret.
    $env = null;
    if ($secretB64 !== null) {
        $env = array_merge(getenv(), ['FLUXFILES_LICENSE_PRIVATE_KEY' => $secretB64]);
    }
    $cmd = array_merge(['php', $GEN], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return trim((string) $out);
}

echo "\n{$cyan}══ license-gen ↔ LicenseManager round-trip ══{$reset}\n\n";

// One keypair for the whole suite.
$genkey = gen(['--genkey']);
preg_match('/PUBLIC[^\n]*\n\s*(\S+)/', $genkey, $pm);
preg_match('/SECRET[^\n]*\n\s*(\S+)/', $genkey, $sm);
$PUB = $pm[1] ?? ''; $SEC = $sm[1] ?? '';
$KEYS = ['k1' => $PUB];

test('genkey produces a usable Ed25519 keypair', function () use ($PUB, $SEC) {
    assertTrue($PUB !== '' && $SEC !== '', 'both keys printed');
    assertTrue(strlen(base64_decode($SEC, true) ?: '') === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret is 64 bytes');
});

test('minted Pro license verifies + carries fields', function () use ($SEC, $KEYS) {
    $token = gen(['--edition=pro', '--modules=optimize,share', '--enforcement=perpetual', '--expires=+365d', '--sites=5', '--customer=Acme'], $SEC);
    $l = new LicenseManager($token, $KEYS);
    assertEqual('pro', $l->edition());
    assertEqual(['optimize', 'share'], $l->modules());
    assertEqual('perpetual', $l->enforcement());
    assertTrue($l->licensed('optimize'), 'optimize licensed');
    assertEqual(5, $l->limits()['sites'] ?? null, 'sites limit');
    assertTrue($l->expiresAt() !== null, 'has expiry');
    assertTrue(!array_key_exists('customer', $l->info()), 'customer not leaked in info()');
});

test('lifetime (--expires=none) → no expiry, active forever', function () use ($SEC, $KEYS) {
    $token = gen(['--edition=agency', '--modules=optimize', '--expires=none'], $SEC);
    $l = new LicenseManager($token, $KEYS);
    assertEqual(null, $l->expiresAt(), 'no expiry');
    assertEqual('active', $l->status());
    assertTrue($l->licensed('optimize'));
});

test('subscription enforcement is carried through', function () use ($SEC, $KEYS) {
    $token = gen(['--edition=pro', '--modules=optimize', '--enforcement=subscription', '--expires=+30d'], $SEC);
    $l = new LicenseManager($token, $KEYS);
    assertEqual('subscription', $l->enforcement());
});

test('a token from a DIFFERENT key is rejected (free)', function () use ($KEYS) {
    // Mint with a fresh, unrelated keypair → must not verify against $KEYS.
    $other = gen(['--genkey']);
    preg_match('/SECRET[^\n]*\n\s*(\S+)/', $other, $m);
    $token = gen(['--edition=pro', '--modules=optimize'], $m[1]);
    $l = new LicenseManager($token, $KEYS);
    assertEqual('free', $l->edition(), 'wrong signing key → free');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

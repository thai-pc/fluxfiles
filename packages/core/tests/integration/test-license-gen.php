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

/**
 * Like gen(), but hands back stderr and the exit code too — the licence id is
 * printed there (stdout stays the bare token so `… > key.txt` keeps working) and
 * a rejected argument is only visible as a non-zero exit.
 *
 * @return array{out:string,err:string,code:int}
 */
function genFull(array $args, ?string $secretB64 = null): array {
    global $GEN;
    $env = $secretB64 === null ? null : array_merge(getenv(), ['FLUXFILES_LICENSE_PRIVATE_KEY' => $secretB64]);
    $p = proc_open(array_merge(['php', $GEN], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['out' => trim((string) $out), 'err' => trim((string) $err), 'code' => proc_close($p)];
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

// The update channel identifies a licence by its `jti`, not by the key itself:
// docs/update-server.example.php refuses (503) to serve a build when
// LicenseManager::id() is null, because it then has nothing to ask the licence
// server about for the refund/revoke check. license-gen once minted no id at all,
// so every key it produced verified perfectly and could never pull an update.
test('a minted key always carries a licence id the update channel can use', function () use ($SEC, $KEYS) {
    $r = genFull(['--edition=pro', '--modules=share'], $SEC);
    $l = new LicenseManager($r['out'], $KEYS);
    assertTrue($l->id() !== null, 'LicenseManager::id() is not null');
    assertTrue(preg_match('/^[a-f0-9]{24}$/', (string) $l->id()) === 1, 'id is 24 lowercase hex');
    assertTrue(strpos($r['err'], (string) $l->id()) !== false, 'the id is reported so it can be recorded');
    assertTrue(!array_key_exists('jti', $l->info()), 'the id is not leaked through info()');
});

test('two mints get different ids', function () use ($SEC, $KEYS) {
    $a = new LicenseManager(gen(['--modules=share'], $SEC), $KEYS);
    $b = new LicenseManager(gen(['--modules=share'], $SEC), $KEYS);
    assertTrue($a->id() !== null && $a->id() !== $b->id(), 'ids are unique per mint');
});

// Re-issuing a replacement key for an existing customer record has to keep the id,
// or the licence server stops recognising the install it already has on file.
test('--jti pins the id (and is normalised)', function () use ($SEC, $KEYS) {
    $l = new LicenseManager(gen(['--modules=share', '--jti=AABBCCDDEEFF001122334455'], $SEC), $KEYS);
    assertEqual('aabbccddeeff001122334455', $l->id(), 'pinned id, lowercased');
});

test('a malformed --jti is refused rather than silently replaced', function () use ($SEC) {
    $r = genFull(['--modules=share', '--jti=nothex'], $SEC);
    assertEqual(1, $r['code'], 'exits non-zero');
    assertEqual('', $r['out'], 'prints no token');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

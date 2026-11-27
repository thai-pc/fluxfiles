<?php

/**
 * fluxfiles_token() fails loudly instead of minting a wrong token:
 *   - no FLUXFILES_SECRET (env or `secret` option) → clear RuntimeException, not
 *     firebase/php-jwt's bare "Provided key is too short";
 *   - a secret shorter than 32 bytes → clear RuntimeException;
 *   - an unknown option key (typo such as `maxUplaodMb`) → InvalidArgumentException,
 *     not a silently-defaulted claim;
 *   - an empty `user` → InvalidArgumentException (no `sub: ""` tokens);
 *   - the `secret` option signs with that key (parity with @fluxfiles/node and
 *     fluxfiles-token for Python).
 * The legacy positional signature keeps working.
 *
 * Usage: php tests/unit/test-embed-token-options.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\JwtCompat;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
/** @return \Throwable the exception $fn threw (fails the test if it threw nothing or another class) */
function expectThrow(string $class, callable $fn): \Throwable
{
    try { $fn(); } catch (\Throwable $e) {
        assertTrue($e instanceof $class, 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        return $e;
    }
    throw new \RuntimeException("expected {$class}, nothing thrown");
}

$envSecret = str_repeat('e', 40);
$optSecret = str_repeat('o', 40);

/** Run $fn with FLUXFILES_SECRET removed from $_ENV and the real environment. */
function withoutEnvSecret(callable $fn)
{
    $saved = [$_ENV['FLUXFILES_SECRET'] ?? null, getenv('FLUXFILES_SECRET')];
    unset($_ENV['FLUXFILES_SECRET']);
    putenv('FLUXFILES_SECRET');
    try { return $fn(); } finally {
        if ($saved[0] !== null) { $_ENV['FLUXFILES_SECRET'] = $saved[0]; }
        if ($saved[1] !== false) { putenv('FLUXFILES_SECRET=' . $saved[1]); }
    }
}

echo "\nfluxfiles_token() option checks\n";

test('no secret anywhere → clear RuntimeException naming FLUXFILES_SECRET', function () {
    withoutEnvSecret(function () {
        $e = expectThrow(\RuntimeException::class, fn () => fluxfiles_token(['user' => 'u']));
        assertTrue(str_contains($e->getMessage(), 'FLUXFILES_SECRET'), $e->getMessage());
        assertTrue(!str_contains($e->getMessage(), 'too short'), 'leaked the raw php-jwt message');
        $e = expectThrow(\RuntimeException::class, fn () => fluxfiles_token('u'));
        assertTrue(str_contains($e->getMessage(), 'FLUXFILES_SECRET'), 'positional: ' . $e->getMessage());
    });
});

test('short secret (env or option) → RuntimeException naming the 32-byte floor', function () use ($optSecret) {
    withoutEnvSecret(function () use ($optSecret) {
        $_ENV['FLUXFILES_SECRET'] = 'short';
        try {
            $e = expectThrow(\RuntimeException::class, fn () => fluxfiles_token(['user' => 'u']));
            assertTrue(str_contains($e->getMessage(), '32 bytes'), $e->getMessage());
        } finally {
            unset($_ENV['FLUXFILES_SECRET']);
        }
        $e = expectThrow(\RuntimeException::class, fn () => fluxfiles_token(['user' => 'u', 'secret' => 'tooshort']));
        assertTrue(str_contains($e->getMessage(), '32 bytes'), $e->getMessage());
    });
});

test('`secret` option signs with that key when no env secret exists', function () use ($optSecret) {
    withoutEnvSecret(function () use ($optSecret) {
        $p = JwtCompat::decode(fluxfiles_token(['user' => 'u', 'secret' => $optSecret]), $optSecret);
        assertTrue($p->sub === 'u', 'sub');
    });
});

test('`secret` option wins over FLUXFILES_SECRET; the claim is not in the payload', function () use ($envSecret, $optSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    $jwt = fluxfiles_token(['user' => 'u', 'secret' => $optSecret]);
    $p = JwtCompat::decode($jwt, $optSecret);
    assertTrue(!isset($p->secret), 'secret leaked into the payload');
    expectThrow(\Throwable::class, fn () => JwtCompat::decode($jwt, $envSecret));
});

test('non-string `secret` → InvalidArgumentException', function () use ($envSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    expectThrow(\InvalidArgumentException::class, fn () => fluxfiles_token(['user' => 'u', 'secret' => 123]));
});

test('unknown option key (typo) → InvalidArgumentException naming it', function () use ($envSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    $e = expectThrow(\InvalidArgumentException::class, fn () => fluxfiles_token(['user' => 'u', 'maxUplaodMb' => 50]));
    assertTrue(str_contains($e->getMessage(), 'maxUplaodMb'), $e->getMessage());
    // A raw claim name belongs under `claims` — at top level it is unknown too.
    expectThrow(\InvalidArgumentException::class, fn () => fluxfiles_token(['user' => 'u', 'pro_hints' => false]));
});

test('every documented alias still mints', function () use ($envSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    $p = JwtCompat::decode(fluxfiles_token([
        'userId' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => 'p', 'ttl' => 60,
        'max_upload' => 5, 'allowed_ext' => ['jpg'], 'owner_only' => true, 'max_storage' => 9, 'max_files' => 3,
        'ai_auto_tag' => true, 'rate_read' => 7, 'rate_write' => 8, 'variants' => null,
        'import' => [], 'media' => [], 'webp' => [], 'usage' => [], 'edition' => null, 'role' => null,
        'extra' => ['pro_hints' => false],
    ]), $envSecret);
    assertTrue($p->sub === 'u' && $p->max_upload === 5 && $p->owner_only === true && $p->pro_hints === false, json_encode($p));
    $p = JwtCompat::decode(fluxfiles_token(['sub' => 'v', 'maxUploadMb' => 6, 'claims' => ['pro_hints' => false]]), $envSecret);
    assertTrue($p->sub === 'v' && $p->max_upload === 6, json_encode($p));
});

test('empty or missing user → InvalidArgumentException', function () use ($envSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    foreach ([[], ['user' => ''], ['user' => '   '], ['user' => null], ['user' => ['x']]] as $o) {
        expectThrow(\InvalidArgumentException::class, fn () => fluxfiles_token($o));
    }
    expectThrow(\InvalidArgumentException::class, fn () => fluxfiles_token(''));
});

test('integer user id is accepted and stringified', function () use ($envSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    $p = JwtCompat::decode(fluxfiles_token(['user' => 42]), $envSecret);
    assertTrue($p->sub === '42', json_encode($p->sub));
});

test('legacy positional form still works', function () use ($envSecret) {
    $_ENV['FLUXFILES_SECRET'] = $envSecret;
    $p = JwtCompat::decode(fluxfiles_token('u9', ['read', 'write'], ['local'], 'users/9', 25, ['png'], 120, true), $envSecret);
    assertTrue($p->sub === 'u9' && $p->max_upload === 25 && $p->prefix === 'users/9' && $p->owner_only === true, json_encode($p));
    assertTrue($p->exp - $p->iat === 120, 'ttl');
});

test('BYOB/mixed helpers also refuse a missing secret clearly', function () {
    withoutEnvSecret(function () {
        $e = expectThrow(\RuntimeException::class, fn () => fluxfiles_byob_token('u', []));
        assertTrue(str_contains($e->getMessage(), 'FLUXFILES_SECRET'), $e->getMessage());
        $e = expectThrow(\RuntimeException::class, fn () => fluxfiles_mixed_token('u', ['local'], []));
        assertTrue(str_contains($e->getMessage(), 'FLUXFILES_SECRET'), $e->getMessage());
    });
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

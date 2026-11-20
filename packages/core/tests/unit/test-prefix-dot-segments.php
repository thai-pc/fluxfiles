<?php

/**
 * The `prefix` claim may not contain `.`/`..` segments, a backslash or a NUL.
 *
 * Regression (audit 2026-11-20, R3-2/R1-9): Claims took the prefix verbatim and
 * Flysystem resolved `users/..` to the disk root, so a host that built the prefix
 * from user text ("users/{$name}", name "..") exposed every tenant. Core now
 * refuses such a token (401 token_invalid) and embed.php refuses to mint one.
 *
 * Usage: php tests/unit/test-prefix-dot-segments.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\JwtCompat;
use FluxFiles\JwtMiddleware;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

$secret = str_repeat('k', 40);
$_ENV['FLUXFILES_SECRET'] = $secret;

$bad  = ['users/..', '..', 'users/../admin', './users', 'users/./a', 'users/alice/..', "users\\..\\admin", "users/a\0b", 'a/../../b'];
$good = ['users/42', 'users/42/', '/users/42', 'tenant1', 'a//b', 'users/al.ice', 'users/...', 'users/..hidden', ''];

echo "\nprefix claim validation\n";

foreach ($bad as $p) {
    test('JwtMiddleware refuses prefix ' . json_encode($p), function () use ($p, $secret) {
        $jwt = JwtCompat::encode(['sub' => 'u', 'exp' => time() + 60, 'perms' => ['read'], 'disks' => ['local'], 'prefix' => $p], $secret);
        try { JwtMiddleware::handle($jwt, $secret); } catch (ApiException $e) {
            assertTrue($e->getCode() === 401, 'expected 401, got ' . $e->getCode());
            return;
        }
        throw new \RuntimeException('token accepted');
    });
    test('fluxfiles_token refuses prefix ' . json_encode($p), function () use ($p) {
        try { fluxfiles_token(['user' => 'u', 'prefix' => $p]); } catch (\InvalidArgumentException $e) { return; }
        throw new \RuntimeException('minted');
    });
}

foreach ($good as $p) {
    test('accepts prefix ' . json_encode($p), function () use ($p, $secret) {
        $c = JwtMiddleware::handle(fluxfiles_token(['user' => 'u', 'prefix' => $p]), $secret);
        assertTrue($c instanceof Claims, 'expected Claims');
    });
}

test('fluxfiles_byob_token and fluxfiles_mixed_token refuse a dot prefix', function () {
    $calls = [
        'fluxfiles_byob_token'  => fn () => fluxfiles_byob_token('u', [], ['read'], 'users/..'),
        'fluxfiles_mixed_token' => fn () => fluxfiles_mixed_token('u', ['local'], [], ['read'], 'users/..'),
    ];
    foreach ($calls as $fn => $call) {
        try { $call(); } catch (\InvalidArgumentException $e) { continue; }
        throw new \RuntimeException("$fn minted");
    }
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

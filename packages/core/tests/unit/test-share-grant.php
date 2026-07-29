<?php

/**
 * Unit tests for ShareGrant — the short-lived proof that a share's password was
 * entered (POST /api/fm/share/unlock → the bytes GET carries the grant, never the
 * password). Mint/verify round-trip, TTL clamp, jti binding, type isolation from
 * the stream/img tokens, tampering.
 *
 * Usage: php tests/unit/test-share-grant.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\ImageToken;
use FluxFiles\JwtCompat;
use FluxFiles\ShareGrant;
use FluxFiles\StreamToken;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectInvalid(callable $fn): void
{
    try { $fn(); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('share_grant_invalid', $e->getErrorCode()); assertEqual(403, $e->getHttpCode()); }
}

echo "\n{$cyan}══ ShareGrant (share unlock token) ══{$reset}\n\n";

$secret = str_repeat('s', 40);
$jti = 'a1b2c3d4e5f60718293a4b5c';

test('mint → verify round-trips the share id', function () use ($secret, $jti) {
    $g = ShareGrant::mint($jti, 0, $secret);
    $out = ShareGrant::verify($g, $jti, $secret);
    assertEqual($jti, $out['jti']);
    assertTrue($out['exp'] > time(), 'exp in the future');
});

test('default TTL is applied when none is requested', function () use ($secret, $jti) {
    $p = JwtCompat::decode(ShareGrant::mint($jti, 0, $secret), $secret);
    assertEqual(ShareGrant::DEFAULT_TTL, $p->exp - $p->iat);
});

test('TTL is clamped to MAX_TTL', function () use ($secret, $jti) {
    $p = JwtCompat::decode(ShareGrant::mint($jti, 999999, $secret), $secret);
    assertTrue(($p->exp - $p->iat) <= ShareGrant::MAX_TTL, 'ttl clamped');
});

test('a grant for share A is rejected on share B', function () use ($secret, $jti) {
    $g = ShareGrant::mint($jti, 0, $secret);
    expectInvalid(fn () => ShareGrant::verify($g, 'ffffffffffffffffffffffff', $secret));
});

test('wrong secret / tampered signature → rejected', function () use ($secret, $jti) {
    $g = ShareGrant::mint($jti, 0, $secret);
    expectInvalid(fn () => ShareGrant::verify($g, $jti, str_repeat('x', 40)));
    expectInvalid(fn () => ShareGrant::verify(substr($g, 0, -2) . 'xy', $jti, $secret));
});

test('expired grant → rejected', function () use ($secret, $jti) {
    $stale = JwtCompat::encode(['t' => 'share_grant', 'jti' => $jti, 'iat' => time() - 100, 'exp' => time() - 10], $secret);
    expectInvalid(fn () => ShareGrant::verify($stale, $jti, $secret));
});

test('empty grant / empty jti → rejected (no accidental open door)', function () use ($secret, $jti) {
    expectInvalid(fn () => ShareGrant::verify('', $jti, $secret));
    expectInvalid(fn () => ShareGrant::verify(ShareGrant::mint($jti, 0, $secret), '', $secret));
});

test('a stream or img token is NOT a grant', function () use ($secret, $jti) {
    expectInvalid(fn () => ShareGrant::verify(StreamToken::mint('local', 'a.mp4', 'u1', 600, $secret), $jti, $secret));
    expectInvalid(fn () => ShareGrant::verify(ImageToken::mint('local', 'a.png', 'u1', 600, $secret, 1600), $jti, $secret));
});

test('a grant is NOT usable as a stream or img token', function () use ($secret, $jti) {
    $g = ShareGrant::mint($jti, 0, $secret);
    try { StreamToken::verify($g, $secret); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('stream_token_invalid', $e->getErrorCode()); }
    try { ImageToken::verify($g, $secret); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('img_token_invalid', $e->getErrorCode()); }
});

test('a main access JWT is not a grant', function () use ($secret, $jti) {
    $access = JwtCompat::encode(['sub' => 'u1', 'perms' => ['read'], 'disks' => ['local'], 'jti' => $jti, 'exp' => time() + 600], $secret);
    expectInvalid(fn () => ShareGrant::verify($access, $jti, $secret));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

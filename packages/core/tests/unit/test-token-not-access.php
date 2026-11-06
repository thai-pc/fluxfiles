<?php

/**
 * Core auth guard: tokens minted by the (private) Share/Intake modules and the
 * stream/img/grant tokens must never authenticate as a main access token.
 * A share/portal token is signed with FLUXFILES_SECRET and carries {perms, disks,
 * prefix}, so it LOOKS like an access JWT; if JwtMiddleware accepted it, a recipient
 * could replay it as `Authorization: Bearer` on /content, /zip or /presign with no
 * password, no download cap and no working revocation.
 * Needs no module installed (tokens are forged in the test).
 *
 * Usage: php tests/unit/test-token-not-access.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\ImageToken;
use FluxFiles\ShareGrant;

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectApi(callable $f, string $code, ?int $http = null): void
{
    try { $f(); throw new \RuntimeException("expected {$code}"); }
    catch (ApiException $e) {
        assertEqual($code, $e->getErrorCode());
        if ($http !== null) { assertEqual($http, $e->getHttpCode()); }
    }
}

/** A token in the exact shape ShareModule::createShare mints. */
function shareShapedToken(string $secret, array $over = []): string
{
    return \FluxFiles\JwtCompat::encode(array_merge([
        't' => 'share', 'sub' => 'share:u42', 'iat' => time(), 'exp' => time() + 600,
        'jti' => bin2hex(random_bytes(12)), 'perms' => ['read'], 'disks' => ['local'],
        'prefix' => 'users/42/reports/q3.pdf', 'share' => true, 'store' => 'users/42/',
        'allow_download' => false, 'allow_zip' => false, 'allow_code_edit' => false,
    ], $over), $secret);
}

test('REPLAY: a share token is refused by the main auth path (403 token_not_access)', function () {
    $secret = str_repeat('k', 40);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle(shareShapedToken($secret), $secret), 'token_not_access', 403);
});

test('REPLAY: the refusal survives dropping the `t` marker or the `share` flag', function () {
    $secret = str_repeat('k', 40);
    // Tokens minted before the type marker existed carry only `share:true`…
    $legacy = shareShapedToken($secret);
    $legacy = \FluxFiles\JwtCompat::encode(array_diff_key(
        (array) \FluxFiles\JwtCompat::decode($legacy, $secret),
        ['t' => 1]
    ), $secret);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle($legacy, $secret), 'token_not_access', 403);
    // …and a forger who strips `share` still can't pass, because `t` is checked too
    // (and stripping either one breaks the signature on a REAL token anyway).
    $noFlag = shareShapedToken($secret, ['share' => false]);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle($noFlag, $secret), 'token_not_access', 403);
});

test('REPLAY: the intake portal token and the stream/img/grant tokens are refused too', function () {
    $secret = str_repeat('k', 40);
    $portal = \FluxFiles\JwtCompat::encode([
        't' => 'intake', 'sub' => 'u42', 'exp' => time() + 600, 'jti' => bin2hex(random_bytes(12)),
        'perms' => ['write'], 'disks' => ['local'], 'prefix' => 'users/42/drop', 'intake' => true, 'store' => 'users/42/',
    ], $secret);
    expectApi(fn () => \FluxFiles\JwtMiddleware::handle($portal, $secret), 'token_not_access', 403);
    foreach ([
        \FluxFiles\StreamToken::mint('local', 'users/42/clip.mp4', 'u42', 600, $secret),
        ImageToken::mint('local', 'users/42/pic.png', 'u42', 600, $secret, 1600),
        ShareGrant::mint(bin2hex(random_bytes(12)), 600, $secret),
    ] as $t) {
        expectApi(fn () => \FluxFiles\JwtMiddleware::handle($t, $secret), 'token_not_access', 403);
    }
});

test('a normal access token still authenticates (the guard is not a blanket denial)', function () {
    $secret = str_repeat('k', 40);
    $access = \FluxFiles\JwtCompat::encode([
        'sub' => 'u42', 'exp' => time() + 600, 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => 'users/42/',
    ], $secret);
    $c = \FluxFiles\JwtMiddleware::handle($access, $secret);
    assertEqual('u42', $c->userId);
    assertTrue($c->hasPerm('write'));
});
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);

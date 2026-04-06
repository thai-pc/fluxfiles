<?php

/**
 * Test script for Claims value object.
 *
 * Usage:
 *   php tests/test-claims.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles Claims Test Suite                 ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► fromJwtPayload{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('fromJwtPayload with minimal payload uses defaults', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);

    assertEqual('0', $claims->userId);
    assertEqual(['read'], $claims->permissions);
    assertEqual(['local'], $claims->allowedDisks);
    assertEqual('', $claims->pathPrefix);
    assertEqual(10, $claims->maxUploadMb);
    assertEqual(null, $claims->allowedExt);
    assertEqual(0, $claims->maxStorageMb);
    assertEqual([], $claims->byobDisks);
});

test('fromJwtPayload with full payload', function () {
    $payload = (object) [
        'sub'         => 'user-42',
        'perms'       => ['read', 'write', 'delete'],
        'disks'       => ['local', 's3'],
        'prefix'      => 'uploads/user42',
        'max_upload'  => 50,
        'allowed_ext' => ['jpg', 'png', 'pdf'],
        'max_storage' => 500,
    ];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);

    assertEqual('user-42', $claims->userId);
    assertEqual(['read', 'write', 'delete'], $claims->permissions);
    assertEqual(['local', 's3'], $claims->allowedDisks);
    assertEqual('uploads/user42', $claims->pathPrefix);
    assertEqual(50, $claims->maxUploadMb);
    assertEqual(['jpg', 'png', 'pdf'], $claims->allowedExt);
    assertEqual(500, $claims->maxStorageMb);
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► hasPerm{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('hasPerm returns true for granted permission', function () {
    $claims = new FluxFiles\Claims('u1', ['read', 'write'], ['local'], '', 10, null, 0, false);
    assertEqual(true, $claims->hasPerm('read'));
    assertEqual(true, $claims->hasPerm('write'));
});

test('hasPerm returns false for missing permission', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(false, $claims->hasPerm('write'));
    assertEqual(false, $claims->hasPerm('delete'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► hasDisk{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('hasDisk returns true for allowed disk', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local', 's3'], '', 10, null, 0, false);
    assertEqual(true, $claims->hasDisk('local'));
    assertEqual(true, $claims->hasDisk('s3'));
});

test('hasDisk returns false for disallowed disk', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(false, $claims->hasDisk('s3'));
    assertEqual(false, $claims->hasDisk('r2'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► isPathInScope{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('isPathInScope: empty prefix always returns true', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(true, $claims->isPathInScope('anything/goes/here'));
    assertEqual(true, $claims->isPathInScope(''));
    assertEqual(true, $claims->isPathInScope('/'));
});

test('isPathInScope: exact prefix match', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual(true, $claims->isPathInScope('uploads/user1'));
});

test('isPathInScope: subdirectory of prefix is in scope', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual(true, $claims->isPathInScope('uploads/user1/photos/pic.jpg'));
});

test('isPathInScope: prefix mismatch', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual(false, $claims->isPathInScope('uploads/user2'));
    assertEqual(false, $claims->isPathInScope('downloads/user1'));
    assertEqual(false, $claims->isPathInScope('other'));
});

test('isPathInScope: path traversal attempt with .. (raw path checked as-is)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    // The raw string still starts with "uploads/user1/" so isPathInScope sees it as in scope.
    // Security relies on scopePath() stripping ".." before filesystem access.
    assertEqual(true, $claims->isPathInScope('uploads/user1/../../etc/passwd'));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► scopePath{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('scopePath: simple path without prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual('photos/pic.jpg', $claims->scopePath('photos/pic.jpg'));
});

test('scopePath: simple path with prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('uploads/user1/photos/pic.jpg', $claims->scopePath('photos/pic.jpg'));
});

test('scopePath: strips .. segments (does not resolve, just removes)', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    // ".." segments are dropped entirely, not resolved — so "foo" stays
    assertEqual('foo/etc/passwd', $claims->scopePath('foo/../../etc/passwd'));
});

test('scopePath: strips . segments', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual('foo/bar', $claims->scopePath('./foo/./bar'));
});

test('scopePath: empty path without prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual('', $claims->scopePath(''));
});

test('scopePath: empty path with prefix', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], 'uploads/user1', 10, null, 0);
    assertEqual('uploads/user1/', $claims->scopePath(''));
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► Defaults and allowed extensions{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('maxUploadMb defaults to 10', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(10, $claims->maxUploadMb);
});

test('maxStorageMb defaults to 0', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(0, $claims->maxStorageMb);
});

test('allowedExt is null when not set in payload', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(null, $claims->allowedExt);
});

test('allowedExt is array when set in payload', function () {
    $payload = (object) ['allowed_ext' => ['jpg', 'png']];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(['jpg', 'png'], $claims->allowedExt);
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► ownerOnly{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('ownerOnly defaults to false', function () {
    $payload = (object) [];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(false, $claims->ownerOnly);
});

test('ownerOnly parsed from JWT payload', function () {
    $payload = (object) ['owner_only' => true];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(true, $claims->ownerOnly);
});

test('ownerOnly false when JWT has owner_only=false', function () {
    $payload = (object) ['owner_only' => false];
    $claims = FluxFiles\Claims::fromJwtPayload($payload);
    assertEqual(false, $claims->ownerOnly);
});

test('ownerOnly set via constructor', function () {
    $claims = new FluxFiles\Claims('u1', ['read'], ['local'], '', 10, null, 0, true);
    assertEqual(true, $claims->ownerOnly);
});

test('embed.php fluxfiles_token with ownerOnly includes owner_only claim', function () {
    $token = fluxfiles_token('user-99', ['read', 'write', 'delete'], ['local'], '', 10, null, 3600, true);
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    assertEqual(true, $payload['owner_only'] ?? null, 'owner_only should be true');
});

test('embed.php fluxfiles_token without ownerOnly omits owner_only claim', function () {
    $token = fluxfiles_token('user-99', ['read'], ['local'], '', 10, null, 3600, false);
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    assertEqual(false, isset($payload['owner_only']), 'owner_only should not be set');
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  ";
echo "{$green}Passed: {$passed}{$reset}  ";
echo "{$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

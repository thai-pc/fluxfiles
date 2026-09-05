<?php

/**
 * Test script for the `role` mint-time preset (docs/ACL-ROLE-PRESETS-DESIGN.md).
 *
 * Usage:
 *   php tests/unit/test-role-preset.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Load .env from packages/core/ if present, otherwise fall back to repo root.
foreach ([__DIR__ . '/../..', __DIR__ . '/../../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

require_once __DIR__ . '/../../embed.php';

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

$secret = $_ENV['FLUXFILES_SECRET'] ?? '';
if ($secret === '') {
    echo "{$red}ERROR: FLUXFILES_SECRET not set in .env{$reset}\n";
    exit(1);
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles Role Preset Test Suite            ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

function decode(string $token, string $secret)
{
    return \FluxFiles\JwtCompat::decode($token, $secret);
}

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► perms early-resolution regression{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('role with no explicit perms decodes to the role default, not the global default', function () use ($secret) {
    $token = fluxfiles_token(['user' => 'u1', 'claims' => [], 'role' => 'editor']);
    $payload = decode($token, $secret);
    assertEqual(['read', 'write'], (array) $payload->perms);
});

test('viewer role with no explicit perms is read-only', function () use ($secret) {
    $token = fluxfiles_token(['user' => 'u1', 'role' => 'viewer']);
    $payload = decode($token, $secret);
    assertEqual(['read'], (array) $payload->perms);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► per-role claim bundle{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('viewer: read-only, owner-scoped', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'viewer']), $secret);
    assertEqual(['read'], (array) $payload->perms);
    assertEqual(true, $payload->owner_only);
    assertEqual(false, isset($payload->allow_code_edit));
    assertEqual(false, isset($payload->show_hidden));
    // Regression guard (B1): allow_extract/allow_chmod default to TRUE when
    // absent from the JWT (Claims::fromJwtPayload), unlike allow_code_edit/
    // show_hidden which default false — so viewer/editor must set them
    // EXPLICITLY false, never rely on omission. Assert against the decoded,
    // effective Claims value (not just raw JWT presence), since that's the
    // gap that let this bug through undetected before.
    assertEqual(false, $payload->allow_extract);
    assertEqual(false, $payload->allow_chmod);
    $claims = \FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual(false, $claims->allowExtract);
    assertEqual(false, $claims->allowChmod);
});

test('editor: read+write, owner-scoped', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'editor']), $secret);
    assertEqual(['read', 'write'], (array) $payload->perms);
    assertEqual(true, $payload->owner_only);
    // editor gets allow_extract (power-user affordance for a contributor) but
    // never allow_chmod (matches the ACL-ROLE-PRESETS-DESIGN.md claim matrix).
    assertEqual(true, $payload->allow_extract);
    assertEqual(false, $payload->allow_chmod);
    $claims = \FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual(true, $claims->allowExtract);
    assertEqual(false, $claims->allowChmod);
});

test('admin: full perms, not owner-scoped, power-user toggles on', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'admin']), $secret);
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $payload->perms);
    assertEqual(false, $payload->owner_only ?? false);
    assertEqual(true, $payload->allow_extract);
    assertEqual(true, $payload->allow_chmod);
    assertEqual(true, $payload->allow_code_edit);
    assertEqual(true, $payload->show_hidden);
});

test('superadmin: identical raw claim bundle to admin', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'superadmin']), $secret);
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $payload->perms);
    assertEqual(false, $payload->owner_only ?? false);
    assertEqual(true, $payload->allow_extract);
    assertEqual(true, $payload->allow_chmod);
    assertEqual(true, $payload->allow_code_edit);
    assertEqual(true, $payload->show_hidden);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► explicit overrides win{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('explicit perms overrides the role default', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'viewer', 'perms' => ['read', 'write', 'delete']]), $secret);
    assertEqual(['read', 'write', 'delete'], (array) $payload->perms);
});

test('explicit ownerOnly=false overrides an editor role default of true', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'editor', 'ownerOnly' => false]), $secret);
    assertEqual(false, $payload->owner_only ?? false);
});

test('explicit owner_only=true overrides an admin role default of false', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'admin', 'owner_only' => true]), $secret);
    assertEqual(true, $payload->owner_only);
});

test('explicit claims escape hatch overrides a role power-user toggle', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'admin', 'claims' => ['allow_chmod' => false]]), $secret);
    assertEqual(false, $payload->allow_chmod);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► edition + role composition{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('edition and role compose without clobbering each other', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'edition' => 'pro', 'role' => 'admin']), $secret);
    // edition claims present
    assertEqual(true, $payload->allow_optimize);
    assertEqual(true, $payload->allow_share);
    assertEqual(true, $payload->allow_intake);
    // role claims present
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $payload->perms);
    assertEqual(true, $payload->allow_chmod);
    assertEqual(false, $payload->owner_only ?? false);
});

test('studio edition preset grants Pro + versioning/webhooks + AI/OCR throw-ins, matching Plans.php\'s studio module list', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'edition' => 'studio']), $secret);
    assertEqual(true, $payload->allow_optimize);
    assertEqual(true, $payload->allow_share);
    assertEqual(true, $payload->allow_intake);
    assertEqual(true, $payload->allow_versioning);
    assertEqual(true, $payload->allow_webhooks);
    assertEqual(true, $payload->allow_ai_vision);
    assertEqual(true, $payload->allow_ocr);
    // Enterprise-only claims must NOT leak into studio.
    assertEqual(false, isset($payload->allow_virus_scan));
    assertEqual(false, isset($payload->allow_c2pa));
    assertEqual(false, isset($payload->allow_backup));
    assertEqual(false, isset($payload->allow_audit_export));
});

test('enterprise edition preset grants every module claim (matches the docstring\'s "enterprise -> all")', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'edition' => 'enterprise']), $secret);
    foreach ([
        'allow_optimize', 'allow_share', 'allow_intake', 'allow_versioning', 'allow_webhooks',
        'allow_ai_vision', 'allow_ocr', 'allow_virus_scan', 'allow_c2pa', 'allow_backup', 'allow_audit_export',
    ] as $claim) {
        assertEqual(true, $payload->{$claim} ?? null, "enterprise preset missing {$claim}");
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► role never touches scoping/limit claims{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('role does not set prefix/disks/sub/max_upload/max_storage/max_files', function () use ($secret) {
    $payload = decode(fluxfiles_token([
        'user' => 'scoped-user',
        'role' => 'admin',
        'disks' => ['local'],
        'prefix' => 'users/42',
        'maxUploadMb' => 5,
        'maxStorageMb' => 100,
        'maxFiles' => 10,
    ]), $secret);
    assertEqual('scoped-user', $payload->sub);
    assertEqual(['local'], (array) $payload->disks);
    assertEqual('users/42', $payload->prefix);
    assertEqual(5, $payload->max_upload);
    assertEqual(100, $payload->max_storage);
    assertEqual(10, $payload->max_files);
});

test('superadmin with empty prefix mints an unscoped token', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'root', 'role' => 'superadmin', 'prefix' => '']), $secret);
    assertEqual('', $payload->prefix);
    assertEqual(false, $payload->owner_only ?? false);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► unknown/absent role is a no-op{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('no role set falls back to the global perms default', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u']), $secret);
    assertEqual(['read'], (array) $payload->perms);
    assertEqual(false, isset($payload->owner_only) && $payload->owner_only === true);
});

test('unknown role string is silently ignored (empty preset)', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'not-a-real-role']), $secret);
    assertEqual(['read'], (array) $payload->perms);
});

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

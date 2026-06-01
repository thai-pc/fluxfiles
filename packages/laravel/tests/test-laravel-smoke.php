<?php

/**
 * Laravel adapter smoke test — exercises FluxFilesManager's token generation,
 * BYOB token, and endpoint resolution with stubbed Laravel helpers, so it runs
 * without a full Laravel app. Covers TEST-PLAN section 7 (Laravel).
 *
 * Usage (after `composer install -d packages/core`):
 *   php packages/laravel/tests/test-laravel-smoke.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

// ── Minimal Laravel shims ──────────────────────────────────────────────────
$GLOBALS['LARAVEL_CONFIG'] = [
    'fluxfiles.secret'   => 'laravel-smoke-secret-key-0123456789ab',   // ≥32 bytes for jwt v7
    'fluxfiles.mode'     => 'direct',
    'fluxfiles.endpoint' => 'https://files.example.com/',
    'app.url'            => 'https://app.example.com',
    'fluxfiles.defaults' => [
        'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => '',
        'max_upload' => 10, 'allowed_ext' => null, 'max_storage' => 0, 'ttl' => 3600,
    ],
];
if (!function_exists('config')) {
    function config($key, $default = null) {
        $cfg = $GLOBALS['LARAVEL_CONFIG'];
        return $cfg[$key] ?? $default;
    }
}
if (!function_exists('env')) {
    function env($key, $default = null) { return $default; }
}
if (!function_exists('storage_path')) {
    function storage_path($path = '') { return '/app/storage' . ($path !== '' ? '/' . $path : ''); }
}

require_once __DIR__ . '/../../core/vendor/autoload.php';   // FluxFiles\JwtCompat, CredentialEncryptor
require_once __DIR__ . '/../src/FluxFilesManager.php';

use FluxFiles\Laravel\FluxFilesManager;

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Laravel Adapter Smoke Test\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

$secret = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret'];

test('token() → decodable JWT with default claims', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token('user-42');
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual('user-42', $claims->sub, 'sub = userId');
    assertTrue(in_array('read', $claims->perms, true), 'default perms');
    assertTrue(in_array('local', $claims->disks, true), 'default disk');
    assertTrue($claims->exp > time(), 'not expired');
});

test('token() honours overrides (perms/prefix/owner_only)', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token(7, ['perms' => ['read'], 'prefix' => 'u7/', 'owner_only' => true]);
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(['read'], $claims->perms, 'perms overridden');
    assertEqual('u7/', $claims->prefix, 'prefix overridden');
    assertEqual(true, $claims->owner_only ?? false, 'owner_only set');
});

test('token() without a secret → throws', function () {
    $prev = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret'];
    $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret'] = '';
    try {
        (new FluxFilesManager())->token('u');
        throw new \RuntimeException('should throw');
    } catch (\RuntimeException $e) {
        assertTrue(stripos($e->getMessage(), 'secret') !== false, 'mentions secret');
    } finally {
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret'] = $prev;
    }
});

test('tokenWithByob() → encrypted byob disk round-trips', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->tokenWithByob('u9', [
        'my-s3' => ['driver' => 's3', 'bucket' => 'cust', 'key' => 'AK', 'secret' => 'SK', 'region' => 'us-east-1'],
    ]);
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertTrue(isset($claims->byob_disks->{'my-s3'}), 'byob disk present');
    $cfg = \FluxFiles\CredentialEncryptor::decrypt((string) $claims->byob_disks->{'my-s3'}, $secret);
    assertEqual('cust', $cfg['bucket'], 'decrypted bucket');
});

test('endpoint() resolves by mode (standalone → fluxfiles.endpoint, else app.url)', function () {
    $mgr = new FluxFilesManager();
    $prev = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'];
    try {
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = 'standalone';
        assertEqual('https://files.example.com', $mgr->endpoint(), 'standalone → fluxfiles.endpoint (trimmed)');
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = 'proxy';
        assertEqual('https://app.example.com', $mgr->endpoint(), 'proxy → app.url');
    } finally {
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = $prev;
    }
});


test("default local disk root matches public storage URL", function () {
    $cfg = require __DIR__ . "/../config/fluxfiles.php";
    assertEqual("/app/storage/app/public/fluxfiles/uploads", $cfg["disks"]["local"]["root"], "local root");
    assertEqual("/storage/fluxfiles/uploads", $cfg["disks"]["local"]["url"], "local url");
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

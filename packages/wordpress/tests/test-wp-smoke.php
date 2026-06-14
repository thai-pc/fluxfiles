<?php

/**
 * WordPress plugin smoke test — exercises FluxFilesPlugin's pure-PHP logic
 * (token generation, BYOB token, disk-config mapping) with stubbed WordPress
 * functions, so it runs without a full WP runtime. Covers TEST-PLAN section 7 (WP).
 *
 * Usage (from repo root, after `composer install -d packages/core`):
 *   php packages/wordpress/tests/test-wp-smoke.php
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

// ── Minimal WordPress shims ────────────────────────────────────────────────
$GLOBALS['WP_OPTIONS'] = [
    'fluxfiles_secret' => 'wp-smoke-secret-key-0123456789abcdef',   // ≥32 bytes for jwt v7
];
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');   // plugin file guards with `defined('ABSPATH') || exit`
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-' . uniqid());
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $GLOBALS['WP_OPTIONS'][$key] ?? $default; }
}
if (!function_exists('content_url')) {
    function content_url($path = '') { return 'http://wp.test/wp-content/' . ltrim($path, '/'); }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true) { return substr(bin2hex(random_bytes($len)), 0, $len); }
}
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('add_filter')) { function add_filter(...$a) {} }

// See test-laravel-smoke.php: CI's floor check overrides FLUXFILES_CORE_AUTOLOAD
// to run against core at this adapter's declared composer floor.
$coreAutoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
require_once $coreAutoload;   // FluxFiles\JwtCompat, CredentialEncryptor
require_once __DIR__ . '/../includes/FluxFilesPlugin.php';

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles WordPress Plugin Smoke Test\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

$secret = $GLOBALS['WP_OPTIONS']['fluxfiles_secret'];

test('generateToken → decodable JWT with correct claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(42);
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual('42', $claims->sub, 'sub = userId');
    assertTrue(in_array('read', $claims->perms, true), 'default perms include read');
    assertTrue(in_array('local', $claims->disks, true), 'default disk local');
    assertTrue($claims->exp > time(), 'not expired');
});

test('generateToken honours overrides (perms/prefix/owner_only)', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(7, [
        'perms' => ['read'], 'prefix' => 'u7/', 'owner_only' => true,
    ]);
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(['read'], $claims->perms, 'perms overridden');
    assertEqual('u7/', $claims->prefix, 'prefix overridden');
    assertEqual(true, $claims->owner_only ?? false, 'owner_only set');
});

test('generateToken without a secret → throws', function () {
    $prev = $GLOBALS['WP_OPTIONS']['fluxfiles_secret'];
    $GLOBALS['WP_OPTIONS']['fluxfiles_secret'] = '';
    try {
        FluxFilesPlugin::generateToken(1);
        throw new \RuntimeException('should throw');
    } catch (\RuntimeException $e) {
        assertTrue(stripos($e->getMessage(), 'secret') !== false, 'mentions secret');
    } finally {
        $GLOBALS['WP_OPTIONS']['fluxfiles_secret'] = $prev;
    }
});

test('generateByobToken → encrypted byob disk round-trips', function () use ($secret) {
    $token = FluxFilesPlugin::generateByobToken(9, [
        'my-s3' => ['driver' => 's3', 'bucket' => 'cust', 'key' => 'AK', 'secret' => 'SK', 'region' => 'us-east-1'],
    ]);
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertTrue(isset($claims->byob_disks->{'my-s3'}), 'byob disk present in token');
    $cfg = \FluxFiles\CredentialEncryptor::decrypt((string) $claims->byob_disks->{'my-s3'}, $secret);
    assertEqual('cust', $cfg['bucket'], 'decrypted bucket matches');
    assertEqual('SK', $cfg['secret'], 'decrypted secret matches');
});

test('diskConfigs → local only by default', function () {
    $disks = FluxFilesPlugin::diskConfigs();
    assertTrue(isset($disks['local']), 'local disk present');
    assertEqual('local', $disks['local']['driver'], 'local driver');
    assertTrue(!isset($disks['s3']) && !isset($disks['r2']), 's3/r2 absent without config');
});

test('diskConfigs → adds s3 + r2 when options are set', function () {
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket'] = 'my-bucket';
    $GLOBALS['WP_OPTIONS']['fluxfiles_r2_bucket'] = 'r2-bucket';
    $GLOBALS['WP_OPTIONS']['fluxfiles_r2_account_id'] = 'acc123';
    try {
        $disks = FluxFilesPlugin::diskConfigs();
        assertEqual('my-bucket', $disks['s3']['bucket'] ?? '', 's3 mapped');
        assertEqual('s3', $disks['r2']['driver'] ?? '', 'r2 uses s3 driver');
        assertEqual('https://acc123.r2.cloudflarestorage.com', $disks['r2']['endpoint'] ?? '', 'r2 endpoint built');
    } finally {
        unset($GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket'], $GLOBALS['WP_OPTIONS']['fluxfiles_r2_bucket'], $GLOBALS['WP_OPTIONS']['fluxfiles_r2_account_id']);
    }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

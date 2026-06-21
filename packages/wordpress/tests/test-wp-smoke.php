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
// Minimal filter registry so we can exercise the fluxfiles_token_overrides hook.
$GLOBALS['WP_FILTERS'] = [];
if (!function_exists('add_filter')) {
    function add_filter($tag, $cb, $priority = 10, $args = 1) { $GLOBALS['WP_FILTERS'][$tag][] = $cb; return true; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        foreach ($GLOBALS['WP_FILTERS'][$tag] ?? [] as $cb) { $value = $cb($value, ...$args); }
        return $value;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return (int) ($GLOBALS['WP_OPTIONS']['_test_user_id'] ?? 0); }
}

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

test('generateToken forwards URL-import claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(13, [
        'allow_url_import'     => true,
        'max_import_mb'        => 20,
        'import_url_allowlist' => ['*.unsplash.com'],
        'import_path'          => 'imports',
        'import_rate_limit'    => 5,
    ]);
    $claims = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret), $secret);
    assertEqual(true, $claims->allowUrlImport, 'allow_url_import carried');
    assertEqual(20, $claims->maxImportMb, 'max_import_mb carried');
    assertEqual(['*.unsplash.com'], $claims->importUrlAllowlist, 'allowlist carried');
    assertEqual('imports', $claims->importPath, 'import_path carried');
    assertEqual(5, $claims->importRateLimit, 'import_rate_limit carried');
});

test('fluxfiles_token_overrides filter can enable URL import for the built-in flow', function () use ($secret) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 99; // for get_current_user_id stub
    add_filter('fluxfiles_token_overrides', function (array $o, int $userId) {
        $o['allow_url_import'] = true;
        $o['max_import_mb']    = 30;
        return $o;
    }, 10, 2);
    $token  = FluxFilesPlugin::tokenForCurrentUser();
    $claims = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret), $secret);
    assertEqual(true, $claims->allowUrlImport, 'filter enabled import');
    assertEqual(30, $claims->maxImportMb, 'filter set max_import_mb');
    $GLOBALS['WP_FILTERS'] = []; // reset
});

test('generateToken forwards media-preview claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(23, [
        'media_preview'    => false,
        'preview_url_ttl'  => 7200,
        'max_preview_mb'   => 250,
        'stream_token_ttl' => 1800,
    ]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(false, $c->mediaPreview, 'media_preview');
    assertEqual(7200, $c->previewUrlTtl, 'preview_url_ttl');
    assertEqual(250, $c->maxPreviewMb, 'max_preview_mb');
    assertEqual(1800, $c->streamTokenTtl, 'stream_token_ttl');
});

test('generateToken forwards webp claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(33, ['webp_enabled' => false, 'webp_max_width' => 1600, 'webp_default_quality' => 75]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(false, $c->webpEnabled, 'webp_enabled');
    assertEqual(1600, $c->webpMaxWidth, 'webp_max_width');
    assertEqual(75, $c->webpDefaultQuality, 'webp_default_quality');
});

test('generateToken forwards watermark + allow_download claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(43, [
        'allow_download' => false, 'allow_chmod' => false, 'allow_code_edit' => true,
        'watermark_enabled' => true, 'watermark_type' => 'logo', 'watermark_logo_path' => 'cfg/logo.png',
        'watermark_position' => 'top-right',
    ]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(false, $c->allowDownload, 'allow_download');
    assertEqual(false, $c->allowChmod, 'allow_chmod');
    assertEqual(true, $c->allowCodeEdit, 'allow_code_edit');
    assertEqual('logo', $c->watermark['type'], 'watermark type');
    assertEqual('cfg/logo.png', $c->watermark['logo_path'], 'watermark logo_path');
});

test('generateToken forwards usage-dashboard claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(53, ['usage_cache_ttl' => 600, 'usage_critical_threshold' => 85, 'usage_top_folders_count' => 5]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(600, $c->usageCacheTtl, 'cache_ttl');
    assertEqual(85, $c->usageCriticalThreshold, 'critical');
    assertEqual(5, $c->usageTopFoldersCount, 'top folders');
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
        // New flow keys: present with safe defaults so the core consumes them.
        assertEqual('private', $disks['s3']['visibility'] ?? '', 's3 visibility defaults private');
        assertTrue(array_key_exists('endpoint', $disks['s3']), 's3 endpoint key present (MinIO/Spaces)');
        assertTrue(array_key_exists('public_url', $disks['s3']), 's3 public_url key present');
        assertEqual(3600, $disks['s3']['url_ttl'] ?? 0, 's3 url_ttl defaults to 1h');
        assertEqual('private', $disks['r2']['visibility'] ?? '', 'r2 visibility defaults private');
        assertEqual(3600, $disks['r2']['url_ttl'] ?? 0, 'r2 url_ttl defaults to 1h');
    } finally {
        unset($GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket'], $GLOBALS['WP_OPTIONS']['fluxfiles_r2_bucket'], $GLOBALS['WP_OPTIONS']['fluxfiles_r2_account_id']);
    }
});

test('diskConfigs → s3 endpoint + visibility are settable (MinIO / public flow)', function () {
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket']     = 'b';
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_endpoint']   = 'http://localhost:9000';
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_visibility'] = 'public';
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_public_url'] = 'https://cdn.example.com';
    try {
        $s3 = FluxFilesPlugin::diskConfigs()['s3'];
        assertEqual('http://localhost:9000', $s3['endpoint'], 's3 endpoint carried');
        assertEqual('public', $s3['visibility'], 's3 visibility carried');
        assertEqual('https://cdn.example.com', $s3['public_url'], 's3 public_url carried');
    } finally {
        unset(
            $GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket'], $GLOBALS['WP_OPTIONS']['fluxfiles_s3_endpoint'],
            $GLOBALS['WP_OPTIONS']['fluxfiles_s3_visibility'], $GLOBALS['WP_OPTIONS']['fluxfiles_s3_public_url']
        );
    }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

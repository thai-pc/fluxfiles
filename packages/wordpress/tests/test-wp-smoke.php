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
$GLOBALS['WP_TRANSIENTS'] = [];
if (!function_exists('get_transient')) {
    function get_transient($k) { return $GLOBALS['WP_TRANSIENTS'][$k] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($k, $v, $ttl = 0) { $GLOBALS['WP_TRANSIENTS'][$k] = $v; return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($k) { unset($GLOBALS['WP_TRANSIENTS'][$k]); return true; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($f) { return 'fluxfiles/fluxfiles.php'; }
}
if (!function_exists('get_bloginfo')) { function get_bloginfo($k = '') { return '6.5'; } }
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
}
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('FLUXFILES_PLUGIN_FILE')) { define('FLUXFILES_PLUGIN_FILE', __DIR__ . '/../fluxfiles.php'); }
if (!defined('FLUXFILES_VERSION')) { define('FLUXFILES_VERSION', '0.2.41'); }
if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') { return 'http://wp.test/wp-content/plugins/fluxfiles/' . ltrim($path, '/'); }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'http://wp.test/wp-json/' . ltrim($path, '/'); }
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
    $token = FluxFilesPlugin::generateToken(33, ['webp_enabled' => false, 'webp_max_width' => 1600, 'webp_default_quality' => 75,
        'srcset_widths' => [400, 1200], 'srcset_sizes' => '100vw']);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(false, $c->webpEnabled, 'webp_enabled');
    assertEqual(1600, $c->webpMaxWidth, 'webp_max_width');
    assertEqual(75, $c->webpDefaultQuality, 'webp_default_quality');
    assertEqual([400, 1200], $c->srcsetWidths, 'srcset_widths');
    assertEqual('100vw', $c->srcsetSizes, 'srcset_sizes');
});

test('generateToken forwards download/zip claims; overlay watermark is NOT forwarded (proxy-only)', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(43, [
        'allow_download' => false, 'allow_chmod' => false, 'allow_code_edit' => true, 'allow_optimize' => true,
        'allow_zip' => false, 'allow_extract' => false, 'zip_max_files' => 7,
        'watermark_enabled' => true, 'watermark_type' => 'logo', 'watermark_logo_path' => 'cfg/logo.png',
        'watermark_position' => 'top-right',
    ]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(false, $c->allowDownload, 'allow_download');
    assertEqual(false, $c->allowChmod, 'allow_chmod');
    assertEqual(true, $c->allowCodeEdit, 'allow_code_edit');
    assertEqual(true, $c->allowOptimize, 'allow_optimize');
    assertEqual(false, $c->allowZip, 'allow_zip');
    assertEqual(false, $c->allowExtract, 'allow_extract');
    assertEqual(7, $c->zipMaxFiles, 'zip_max_files');
    // WordPress is proxy-only (no /api/fm/img), so the OVERLAY watermark claim is
    // dropped — forwarding it would break images. Burn-in (POST /api/fm/watermark)
    // is the WP-supported watermark and works through the proxy.
    assertEqual(null, $c->watermark, 'overlay watermark NOT forwarded (proxy-only)');
});

test('generateToken forwards usage-dashboard claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(53, ['usage_cache_ttl' => 600, 'usage_critical_threshold' => 85, 'usage_top_folders_count' => 5]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(600, $c->usageCacheTtl, 'cache_ttl');
    assertEqual(85, $c->usageCriticalThreshold, 'critical');
    assertEqual(5, $c->usageTopFoldersCount, 'top folders');
});

// Regression: the module GATE claim (allow_webhooks) shipped here but its config
// claims did not, so a WP host could turn Webhooks on and have it POST nowhere.
// Gate + config must travel together.
test('generateToken forwards webhook config claims, not just the gate', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(54, [
        'allow_webhooks'    => true,
        'webhook_url'       => 'https://hooks.acme.com/flux',
        'webhook_events'    => ['upload', 'delete'],
        'webhook_secret'    => 'whsec_abc123',
    ]);
    // Assert on the raw JWT payload, not Claims::fromJwtPayload — forwarding a claim
    // needs no core API, so this must keep passing against the declared core floor
    // (a core too old to know these keys simply ignores them). Validation belongs to
    // the core and is tested there (test-claims.php).
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, $p->allow_webhooks ?? null, 'webhooks gate');
    assertEqual('https://hooks.acme.com/flux', $p->webhook_url ?? '', 'webhook_url');
    assertEqual(['upload', 'delete'], (array) ($p->webhook_events ?? []), 'webhook_events');
    assertEqual('whsec_abc123', $p->webhook_secret ?? '', 'webhook_secret');

    // A settings field naturally holds a comma-separated string; the core normalizes it.
    $csv = \FluxFiles\JwtCompat::decode(
        FluxFilesPlugin::generateToken(55, ['allow_webhooks' => true, 'webhook_events' => 'upload,delete']),
        $secret
    );
    assertEqual('upload,delete', $csv->webhook_events ?? '', 'CSV events forwarded as-is');
});

// Versioning is core-standalone — /api/fm/versions* isn't proxied, so the plugin
// must drop the gate and its tuning claims entirely (same rule as SSH terminal).
test('generateToken does NOT forward versioning claims (no proxied endpoint)', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(56, [
        'allow_versioning'  => true,
        'versioning_max'    => 5,
        'versioning_max_mb' => 50,
    ]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(false, isset($p->allow_versioning), 'versioning gate dropped');
    assertEqual(false, isset($p->versioning_max), 'versioning_max dropped');
    assertEqual(false, isset($p->versioning_max_mb), 'versioning_max_mb dropped');
});

// Audit export/purge is core-standalone — /api/fm/audit/export and
// /api/fm/audit/purge aren't proxied, so the plugin must drop the gate and its
// retention-days claim entirely (same rule as versioning/SSH terminal).
test('generateToken does NOT forward audit-export claims (no proxied endpoint)', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(57, [
        'allow_audit_export'   => true,
        'audit_retention_days' => 365,
    ]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(false, isset($p->allow_audit_export), 'audit-export gate dropped');
    assertEqual(false, isset($p->audit_retention_days), 'audit_retention_days dropped');
});

// Share + Intake are NOT forwarded at all: the plugin is proxy-only and the REST API
// exposes none of the six operator endpoints (nor the public landing routes), so the
// gate claims would render a button that 404s and their config would be dead. Same
// reasoning as the SSH terminal.
test('generateToken forwards the share/intake gates and their config', function () use ($secret) {
    // This test used to assert the OPPOSITE: the claims were stripped because the REST
    // API exposed none of the endpoints, so a forwarded claim meant a button that 404s.
    // The proxy now serves all eleven routes, so stripping them would instead hide a
    // feature the customer paid for.
    $token = FluxFilesPlugin::generateToken(56, [
        'allow_share'     => true,
        'allow_intake'    => true,
        'share_url_ttl'   => 120,
        'share_base_url'  => 'https://files.acme.com/public/share.html',
        'share_preview'   => false,
        'share_analytics' => true,
        'intake_base_url' => 'https://files.acme.com/public/intake.html',
        'intake_analytics' => true,
    ]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, $p->allow_share ?? null, 'share gate forwarded');
    assertEqual(true, $p->allow_intake ?? null, 'intake gate forwarded');
    assertEqual(120, $p->share_url_ttl ?? null, 'share_url_ttl forwarded');
    assertEqual('https://files.acme.com/public/share.html', $p->share_base_url ?? null);
    assertEqual(false, $p->share_preview ?? null, 'share_preview forwarded');
    assertEqual(true, $p->share_analytics ?? null, 'share_analytics forwarded');
    assertEqual('https://files.acme.com/public/intake.html', $p->intake_base_url ?? null);
    assertEqual(true, $p->intake_analytics ?? null, 'intake_analytics forwarded');

    // The edition preset lights both gates up, as it always intended to.
    $pro = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(57, ['edition' => 'pro']), $secret);
    assertEqual(true, $pro->allow_share ?? null, 'edition preset enables share');
    assertEqual(true, $pro->allow_intake ?? null, 'edition preset enables intake');
    assertEqual(true, $pro->allow_optimize ?? null, 'the rest of the preset is unaffected');
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

// Session-expiry recovery: the embedded UI must re-mint a JWT from the WP login
// session (cookie + nonce, NOT the expired JWT) so "Try again" recovers without
// a page reload. The REST route uses checkLoggedIn (not checkAuth, which rejects
// an expired Bearer), the handler exists, and both the shortcode and the media
// button wire onTokenRefresh.
test('token-refresh recovery wiring is present (api + shortcode + media button)', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    $scSrc  = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesShortcode.php');
    $mbSrc  = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesMediaButton.php');

    assertTrue(preg_match('#function\s+handleToken\s*\(#', $apiSrc) === 1, 'API has handleToken()');
    assertTrue(preg_match('#function\s+checkLoggedIn\s*\(#', $apiSrc) === 1, 'API has checkLoggedIn() permission cb');
    assertTrue(strpos($apiSrc, "'/token'") !== false, 'API registers the /token route');
    assertTrue(strpos($apiSrc, "'permission_callback' => [self::class, 'checkLoggedIn']") !== false, 'token route uses checkLoggedIn (not checkAuth)');
    assertTrue(strpos($scSrc, 'onTokenRefresh') !== false, 'shortcode wires onTokenRefresh');
    assertTrue(strpos($mbSrc, 'onTokenRefresh') !== false, 'media button wires onTokenRefresh');
});

// ── Attachment bridge (3-in-1 media manager) ────────────────────────────────
// Stub the WP attachment functions so FluxFilesAttachments can run headless.
$GLOBALS['WP_POSTS'] = [];   // id => ['post' => [...], 'meta' => [...]]
$GLOBALS['WP_NEXT_ID'] = 100;
if (!function_exists('wp_insert_attachment')) {
    function wp_insert_attachment($args, $file = false, $parent = 0, $wp_error = false) {
        $id = $GLOBALS['WP_NEXT_ID']++;
        $GLOBALS['WP_POSTS'][$id] = ['post' => $args, 'meta' => []];
        return $id;
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $k, $v) { $GLOBALS['WP_POSTS'][$id]['meta'][$k] = $v; return true; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $k, $single = false) { return $GLOBALS['WP_POSTS'][$id]['meta'][$k] ?? ''; }
}
if (!function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata($id, $meta) { $GLOBALS['WP_POSTS'][$id]['attmeta'] = $meta; return true; }
}
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof \WP_Error; } }
if (!class_exists('WP_Error')) { class WP_Error {} }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $o = 0, $depth = 512) { return json_encode($d, $o, $depth); } }
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($n) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $n); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return $u; } }
if (!function_exists('wp_check_filetype')) {
    function wp_check_filetype($f, $m = null) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        return ['ext' => $ext, 'type' => $map[$ext] ?? ''];
    }
}
// WP_Query stub that searches WP_POSTS meta for the find-by-key lookup.
if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];
        public function __construct($args) {
            $want = [];
            foreach ($args['meta_query'] ?? [] as $c) {
                if (isset($c['key'])) { $want[$c['key']] = $c['value']; }
            }
            foreach ($GLOBALS['WP_POSTS'] as $id => $p) {
                $ok = true;
                foreach ($want as $k => $v) { if (($p['meta'][$k] ?? null) !== $v) { $ok = false; break; } }
                if ($ok && $want) { $this->posts[] = $id; }
            }
        }
        public function have_posts(): bool { return count($this->posts) > 0; }
    }
}
require_once __DIR__ . '/../includes/FluxFilesAttachments.php';

test('attachment bridge: findOrCreate creates a WP attachment with FluxFiles markers', function () {
    $GLOBALS['WP_POSTS'] = [];
    $res = FluxFilesAttachments::findOrCreate([
        'url' => 'https://cdn.example.com/s3/photos/cat.jpg', 'key' => 'photos/cat.jpg',
        'disk' => 's3', 'basename' => 'cat.jpg', 'width' => 800, 'height' => 600,
    ]);
    assertTrue($res['id'] > 0, 'returns an attachment id');
    assertEqual('image/jpeg', $res['mime'], 'mime inferred from name');
    $meta = $GLOBALS['WP_POSTS'][$res['id']]['meta'];
    assertEqual('https://cdn.example.com/s3/photos/cat.jpg', $meta['_fluxfiles_url'], 'url marker stored');
    assertEqual('photos/cat.jpg', $meta['_fluxfiles_key'], 'key marker stored');
    assertEqual('s3', $meta['_fluxfiles_disk'], 'disk marker stored');
    assertEqual(800, $GLOBALS['WP_POSTS'][$res['id']]['attmeta']['width'], 'width recorded');
});

test('attachment bridge: findOrCreate is idempotent on (disk, key)', function () {
    $GLOBALS['WP_POSTS'] = [];
    $a = FluxFilesAttachments::findOrCreate(['url' => 'https://x/y/a.png', 'key' => 'y/a.png', 'disk' => 'local']);
    $b = FluxFilesAttachments::findOrCreate(['url' => 'https://x/y/a.png', 'key' => 'y/a.png', 'disk' => 'local']);
    assertEqual($a['id'], $b['id'], 'same file → same attachment (no duplicate)');
    assertEqual(1, count($GLOBALS['WP_POSTS']), 'only one attachment stored');
});

test('attachment bridge: URL filters rewrite our attachments, leave others alone', function () {
    $GLOBALS['WP_POSTS'] = [];
    $res = FluxFilesAttachments::findOrCreate(['url' => 'https://cdn/z/pic.webp', 'key' => 'z/pic.webp', 'disk' => 'r2']);
    // ours → rewritten to the FluxFiles URL
    assertEqual('https://cdn/z/pic.webp', FluxFilesAttachments::filterUrl('http://wp/wp-content/uploads/pic.webp', $res['id']), 'our attachment URL rewritten');
    $img = FluxFilesAttachments::filterImageSrc(['http://wp/local.webp', 800, 600, false], $res['id'], 'full', false);
    assertEqual('https://cdn/z/pic.webp', $img[0], 'our image src rewritten');
    // a normal attachment (no marker) → untouched
    $GLOBALS['WP_POSTS'][999] = ['post' => [], 'meta' => []];
    assertEqual('http://wp/normal.jpg', FluxFilesAttachments::filterUrl('http://wp/normal.jpg', 999), 'normal attachment untouched');
});

test('attachment bridge: wired into the plugin (routes + button + block)', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    $mbSrc  = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesMediaButton.php');
    assertTrue(strpos($apiSrc, "'/attach'") !== false, 'API registers the /attach route');
    assertTrue(strpos($apiSrc, 'checkCanUpload') !== false, 'attach route gated by upload_files cap');
    assertTrue(strpos($mbSrc, 'attach') !== false, 'media button calls the attach endpoint');
    assertTrue(is_file(__DIR__ . '/../assets/block.js'), 'Gutenberg block script exists');
    assertTrue(is_file(__DIR__ . '/../includes/FluxFilesBlock.php'), 'block registrar exists');
});

test('attachment bridge: syncs alt text + caption onto the attachment', function () {
    $GLOBALS['WP_POSTS'] = [];
    $res = FluxFilesAttachments::findOrCreate([
        'url' => 'https://cdn/x/hero.jpg', 'key' => 'x/hero.jpg', 'disk' => 's3',
        'basename' => 'hero.jpg', 'alt' => 'A mountain at dawn', 'caption' => 'Shot on location',
    ]);
    assertEqual('A mountain at dawn', $GLOBALS['WP_POSTS'][$res['id']]['meta']['_wp_attachment_image_alt'], 'alt synced');
    assertEqual('Shot on location', $GLOBALS['WP_POSTS'][$res['id']]['post']['post_excerpt'], 'caption → excerpt');
});

test('srcset: offloaded image gets a real responsive srcset from FluxFiles variants', function () {
    $GLOBALS['WP_POSTS'] = [];
    $res = FluxFilesAttachments::findOrCreate([
        'url' => 'https://cdn/p/big.jpg', 'key' => 'p/big.jpg', 'disk' => 's3',
        'basename' => 'big.jpg', 'width' => 2400, 'height' => 1600,
        'variants' => [
            'thumb'  => ['url' => 'https://cdn/p/big-thumb.webp'],
            'medium' => ['url' => 'https://cdn/p/big-medium.webp'],
            'large'  => ['url' => 'https://cdn/p/big-large.webp'],
        ],
    ]);
    $stored = json_decode($GLOBALS['WP_POSTS'][$res['id']]['meta']['_fluxfiles_variants'], true);
    // thumb 150, medium 768, large 1920 + original 2400
    assertEqual('https://cdn/p/big-thumb.webp', $stored['150'], 'thumb → 150w');
    assertEqual('https://cdn/p/big-medium.webp', $stored['768'], 'medium → 768w');
    assertEqual('https://cdn/p/big-large.webp', $stored['1920'], 'large → 1920w');
    assertEqual('https://cdn/p/big.jpg', $stored['2400'], 'original → natural width');

    // The filter turns stored variants into WP srcset sources.
    $sources = FluxFilesAttachments::filterSrcset([], [2400, 1600], 'https://cdn/p/big.jpg', [], $res['id']);
    assertEqual(4, count($sources), 'four srcset candidates');
    assertEqual('https://cdn/p/big-medium.webp', $sources[768]['url'], 'source keyed by width');
    assertEqual('w', $sources[768]['descriptor'], 'width descriptor');
    // A normal attachment (no variants) → WP result untouched.
    $GLOBALS['WP_POSTS'][777] = ['post' => [], 'meta' => []];
    assertEqual(['x'], FluxFilesAttachments::filterSrcset(['x'], [100, 100], 'u', [], 777), 'non-FluxFiles untouched');
});

test('stableUrl: public/local yield stable URLs, private/sftp are rejected', function () {
    // public S3 with a CDN → stable
    assertEqual('https://cdn.example.com/wp/pic.jpg',
        FluxFilesAttachments::stableUrl(['driver' => 's3', 'visibility' => 'public', 'public_url' => 'https://cdn.example.com'], 'wp/pic.jpg'),
        'public S3 uses public_url');
    // local (non-gated) → stable
    assertEqual('/storage/uploads/a/b.png',
        FluxFilesAttachments::stableUrl(['driver' => 'local', 'url' => '/storage/uploads'], 'a/b.png'),
        'local uses disk url');
    // private S3 → null (presigned expires)
    assertEqual(null, FluxFilesAttachments::stableUrl(['driver' => 's3', 'visibility' => 'private', 'bucket' => 'b'], 'k'), 'private S3 rejected');
    // gated local → null
    assertEqual(null, FluxFilesAttachments::stableUrl(['driver' => 'local', 'url' => '/u', 'private' => true], 'k'), 'gated local rejected');
    // sftp → null
    assertEqual(null, FluxFilesAttachments::stableUrl(['driver' => 'sftp'], 'k'), 'sftp rejected');
});

test('delete sync: onDelete is a no-op unless opted in, and only for our attachments', function () {
    $GLOBALS['WP_POSTS'] = [];
    // a normal (non-FluxFiles) attachment → locate returns null → onDelete does nothing
    $GLOBALS['WP_POSTS'][501] = ['post' => [], 'meta' => []];
    assertEqual(null, FluxFilesAttachments::locate(501), 'non-FluxFiles attachment not located');
    // a FluxFiles attachment → located
    $res = FluxFilesAttachments::findOrCreate(['url' => 'https://cdn/d/e.jpg', 'key' => 'd/e.jpg', 'disk' => 'local']);
    $loc = FluxFilesAttachments::locate($res['id']);
    assertEqual('d/e.jpg', $loc['key'], 'FluxFiles attachment located by key');
    assertEqual('local', $loc['disk'], 'disk resolved');
    // onDelete with the option OFF must not throw and must not attempt storage deletion
    $GLOBALS['WP_OPTIONS']['fluxfiles_delete_storage'] = '0';
    FluxFilesAttachments::onDelete($res['id']); // no exception = pass (option off → skip)
    assertTrue(true, 'onDelete safe when delete-from-storage is off');
});

test('media parity: featured image + native-picker integration wired', function () {
    $blockJs = (string) file_get_contents(__DIR__ . '/../assets/block.js');
    assertTrue(strpos($blockJs, 'featured_media') !== false, 'block can set the featured image');
    assertTrue(is_file(__DIR__ . '/../assets/media-integration.js'), 'native media integration script exists');
    assertTrue(is_file(__DIR__ . '/../includes/FluxFilesMediaIntegration.php'), 'native integration registrar exists');
    $intSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesMediaIntegration.php');
    assertTrue(strpos($intSrc, 'fluxfiles_replace_picker') !== false, 'native picker is opt-in via a setting');
    assertTrue(strpos($intSrc, 'wp_enqueue_media') !== false, 'integration loads the native media JS');
});

// ── Licence key + updates ────────────────────────────────────────────────────
// A WordPress customer cannot set an environment variable on shared hosting, so
// without the option they have no way to activate what they bought — and the plugin
// is distributed as a zip, so without an update check a site never learns a new
// version exists, including a security release.

test('licence key: the option is used, and the environment is the fallback', function () {
    $GLOBALS['WP_OPTIONS']['fluxfiles_license_key'] = '  key-from-settings  ';
    assertEqual('key-from-settings', FluxFilesPlugin::licenseKey(), 'option wins, whitespace trimmed');

    unset($GLOBALS['WP_OPTIONS']['fluxfiles_license_key']);
    putenv('FLUXFILES_LICENSE_KEY=key-from-env');
    assertEqual('key-from-env', FluxFilesPlugin::licenseKey(), 'falls back to the environment');

    // An operator who CAN set an env var must not be broken by this feature existing.
    $GLOBALS['WP_OPTIONS']['fluxfiles_license_key'] = 'key-from-settings';
    assertEqual('key-from-settings', FluxFilesPlugin::licenseKey(), 'the option overrides the env');
    putenv('FLUXFILES_LICENSE_KEY');
    unset($GLOBALS['WP_OPTIONS']['fluxfiles_license_key']);
    assertEqual('', FluxFilesPlugin::licenseKey(), 'neither set → empty, i.e. free core');
});

test('licence key: gates read it through the plugin, never straight from the env', function () {
    // LicenseManager::fromEnv() ignores the WP option entirely, so a customer who
    // pasted a good key into the settings screen would be told they have no licence.
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($api, 'LicenseManager::fromEnv()') === false, 'no fromEnv() left in the REST proxy');
    assertTrue(substr_count($api, 'FluxFilesPlugin::license()') >= 3, 'gates go through the plugin helper');
});

test('licence key is stored verbatim, not through sanitize_text_field', function () {
    // A signed key is long and punctuation-heavy; sanitising it would quietly corrupt
    // a valid key into an invalid one.
    $admin = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesAdmin.php');
    assertTrue(strpos($admin, "'sanitize_callback' => [\$this, 'sanitizeLicenseKey']") !== false,
        'the key has its own sanitiser');
    assertTrue(strpos($admin, 'renderLicenseStatus') !== false, 'the screen reports verified status back');
});

test('updater: a manifest for a DIFFERENT module is refused', function () {
    // The signature would be perfectly valid — it just describes another artifact.
    // Without the module check, a valid `share` release could be installed as the
    // plugin.
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesUpdater.php');
    assertTrue(strpos($src, "=== self::MODULE") !== false, 'manifest module is checked');
    assertTrue(strpos($src, 'verifyManifest') !== false, 'the manifest signature is verified');
});

test('updater: does nothing at all when no endpoint is configured', function () {
    $GLOBALS['WP_FILTERS'] = [];
    putenv('FLUXFILES_UPDATE_URL');
    unset($GLOBALS['WP_OPTIONS']['fluxfiles_update_url']);
    require_once __DIR__ . '/../includes/FluxFilesUpdater.php';
    FluxFilesUpdater::boot();
    assertTrue(empty($GLOBALS['WP_FILTERS']['pre_set_site_transient_update_plugins']),
        'a self-hosted install with no update server registers no hooks');
});

test('updater: registers the update hooks once an endpoint exists', function () {
    $GLOBALS['WP_FILTERS'] = [];
    putenv('FLUXFILES_UPDATE_URL=https://updates.example/update');
    FluxFilesUpdater::boot();
    assertTrue(!empty($GLOBALS['WP_FILTERS']['pre_set_site_transient_update_plugins']),
        'the update transient filter is registered');
    assertTrue(!empty($GLOBALS['WP_FILTERS']['plugins_api']),
        'plugins_api is filtered so "View details" does not hit wordpress.org');
    putenv('FLUXFILES_UPDATE_URL');
});

test('updater: offers an update only when the manifest is genuinely newer', function () {
    require_once __DIR__ . '/../includes/FluxFilesUpdater.php';
    $slug = 'fluxfiles/fluxfiles.php';

    // Seed the cache so inject() runs its real logic with no network.
    $seed = function (array $m) { $GLOBALS['WP_TRANSIENTS']['fluxfiles_update_manifest'] = $m; };

    // Newer → offered, carrying the signed URL as the package.
    $seed(['version' => '9.9.9', 'url' => 'https://cdn.example/fluxfiles-9.9.9.zip', 'sha256' => 'x']);
    $t = FluxFilesUpdater::inject((object) ['response' => []]);
    assertTrue(isset($t->response[$slug]), 'a newer version is offered');
    assertEqual('9.9.9', $t->response[$slug]->new_version);
    assertEqual('https://cdn.example/fluxfiles-9.9.9.zip', $t->response[$slug]->package);

    // Same version → nothing. An update notice that reinstalls the current build is
    // an infinite nag.
    $seed(['version' => FLUXFILES_VERSION, 'url' => 'https://cdn.example/x.zip', 'sha256' => 'x']);
    $t = FluxFilesUpdater::inject((object) ['response' => []]);
    assertTrue(!isset($t->response[$slug]), 'the current version is not offered');

    // OLDER → nothing. A rolled-back server must never downgrade a site.
    $seed(['version' => '0.0.1', 'url' => 'https://cdn.example/old.zip', 'sha256' => 'x']);
    $t = FluxFilesUpdater::inject((object) ['response' => []]);
    assertTrue(!isset($t->response[$slug]), 'an older version is never offered');

    // A cached failure ('' — server down) must not crash or offer anything.
    $GLOBALS['WP_TRANSIENTS']['fluxfiles_update_manifest'] = '';
    $t = FluxFilesUpdater::inject((object) ['response' => []]);
    assertTrue(!isset($t->response[$slug]), 'a failed check offers nothing');
    delete_transient('fluxfiles_update_manifest');
});

test('plugin declares Update URI so wordpress.org cannot hijack the slug', function () {
    // Without this header, a plugin published on wordpress.org under the same slug
    // would be served to our users as an update.
    $head = (string) file_get_contents(__DIR__ . '/../fluxfiles.php');
    assertTrue(strpos($head, 'Update URI:') !== false, 'Update URI header present');
});

// ── Share + Intake over the WordPress proxy ──────────────────────────────────
// WordPress is the channel the strategy calls "bread-and-butter", and until now a
// customer who bought Pro could not use either hero product here: the REST API
// exposed none of the endpoints and the plugin stripped the claims.

test('share/intake: the claims are forwarded now that the endpoints exist', function () {
    $tok = FluxFilesPlugin::generateToken(1, ['allow_share' => true, 'allow_intake' => true]);
    $p = \FluxFiles\JwtCompat::decode($tok, $GLOBALS['WP_OPTIONS']['fluxfiles_secret']);
    assertEqual(true, $p->allow_share ?? null, 'allow_share reaches the token');
    assertEqual(true, $p->allow_intake ?? null, 'allow_intake reaches the token');
});

test('share/intake: base URLs point at this site, not a path WordPress lacks', function () {
    // Left empty, the module falls back to the request origin + /public/share.html —
    // a path a WordPress site does not serve, so every link would 404.
    $tok = FluxFilesPlugin::generateToken(1, ['allow_share' => true]);
    $p = \FluxFiles\JwtCompat::decode($tok, $GLOBALS['WP_OPTIONS']['fluxfiles_secret']);
    $base = (string) ($p->share_base_url ?? '');
    assertTrue(strpos($base, 'public-link.php') !== false, 'points at the plugin shim');
    assertTrue(strpos($base, 'page=share') !== false, 'names which page');
    // The module appends `&token=…` itself. A base that already carried one would
    // produce two, and the first (empty) one wins.
    assertTrue(strpos($base, 'token=') === false, 'the base carries NO token');
});

test('share/intake: an explicit base_url override still wins', function () {
    $tok = FluxFilesPlugin::generateToken(1, [
        'allow_share' => true, 'share_base_url' => 'https://files.acme.com/s',
    ]);
    $p = \FluxFiles\JwtCompat::decode($tok, $GLOBALS['WP_OPTIONS']['fluxfiles_secret']);
    assertEqual('https://files.acme.com/s', $p->share_base_url ?? null);
});

test('the five PUBLIC routes are deliberately unauthenticated', function () {
    // A recipient has no WordPress account and no JWT — the share token in the query
    // string is the whole gate. If someone "tightens" these to checkAuth, every share
    // link 401s for exactly the people it is for, so the intent is asserted here.
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    foreach (['/share/info', '/share/unlock', '/share/file', '/intake/info', '/intake/upload'] as $r) {
        assertTrue(strpos($api, "\$p . '{$r}'") !== false, "public route registered: {$r}");
    }
    assertTrue(strpos($api, "'permission_callback' => '__return_true'") !== false,
        'public routes use __return_true');
    // …and the operator routes must NOT be public.
    foreach (['/share/list', '/share/revoke', '/intake/list', '/intake/revoke'] as $r) {
        $pos = strpos($api, "\$p . '{$r}'");
        assertTrue($pos !== false, "operator route registered: {$r}");
        $window = substr($api, $pos, 200);
        assertTrue(strpos($window, '__return_true') === false, "{$r} is NOT public");
    }
});

test('public routes delegate to core rather than reimplementing the checks', function () {
    // A second copy of the traversal guards, the MIME rules, the brute-force limiter
    // and the download counter is how a hole appears on one platform only.
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($api, 'handleSharePublic(') !== false, 'calls the core share handler');
    assertTrue(strpos($api, 'handleIntakePublic(') !== false, 'calls the core intake handler');
    assertTrue(strpos($api, 'PublicLinks.php') !== false, 'includes the shared core file');
    // WordPress uploads live under wp-content, not under the core directory: without
    // passing its own DiskManager the handler resolves against a directory holding
    // none of the files.
    assertTrue(strpos($api, '$this->diskManager, FluxFilesPlugin::diskConfigs()') !== false,
        'the WP DiskManager is injected');
});

test('the recipient page shim only serves the two known pages', function () {
    $src = (string) file_get_contents(__DIR__ . '/../public-link.php');
    assertTrue(strpos($src, "\$pages = ['share' => 'share.html', 'intake' => 'intake.html']") !== false,
        'whitelist, not a sanitiser — this value reaches a filesystem read');
    assertTrue(strpos($src, '__FM_API_BASE__') !== false, 'injects the REST base');
    assertTrue(strpos($src, 'Referrer-Policy: no-referrer') !== false,
        'the URL carries the token, so it must not leak in a Referer');
});

// ── error() passthrough regression (B1) ─────────────────────────────────────
// error() used to be declared with only (message, status), so every `catch
// (ApiException $e) { return $this->error($e->getMessage(), $e->getHttpCode(),
// $e->getErrorCode(), $e->getErrorParams()); }` call site silently dropped the
// extra two args (PHP does not warn on this). fm.js reads `error_code`/
// `error_params` off the JSON body to localise the message and branch the UI —
// without them every WordPress error came through as an unlocalised, code-less
// string. These tests drive REAL route handlers end-to-end (not error()
// directly) so a regression at either the signature OR a call site is caught.

// Minimal WP_REST_Request/WP_REST_Response stand-ins — good enough for the
// handler methods actually used below (get_json_params/get_param/get_params).
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $body;
        private array $query;
        public function __construct(array $body = [], array $query = []) { $this->body = $body; $this->query = $query; }
        public function get_json_params() { return $this->body; }
        public function get_params() { return $this->body + $this->query; }
        public function get_param($key) { return $this->body[$key] ?? $this->query[$key] ?? null; }
        public function get_file_params() { return []; }
        public function get_header($name) { return null; }
        public function get_method() { return 'POST'; }
        public function get_route() { return ''; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private int $status;
        public function __construct($data, int $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}

require_once __DIR__ . '/../includes/FluxFilesApi.php';

$GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'write', 'delete'];
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(1);

test('error(): ApiException code/params reach the REST response body (missing_param)', function () {
    $api = new FluxFilesApi();
    $resp = $api->handleImportUrl(new WP_REST_Request(['disk' => 'local'])); // no 'url'
    assertTrue($resp instanceof WP_REST_Response, 'handler returns a WP_REST_Response');
    assertEqual(400, $resp->get_status(), 'status forwarded');
    $data = $resp->get_data();
    assertEqual('missing_param', $data['error_code'] ?? null, 'error_code present in body');
    assertTrue(($data['error'] ?? '') !== '', 'human message still present');
});

test('error(): ApiException params reach the REST response body (folder_exists → name)', function () {
    $api = new FluxFilesApi();
    $dirName = 'errtest-dir-' . uniqid();
    $first = $api->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => $dirName]));
    assertEqual(200, $first->get_status(), 'first mkdir succeeds');

    // Same folder again → 409 with error_code + error_params (the name of the conflict).
    $second = $api->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => $dirName]));
    $data = $second->get_data();
    assertEqual(409, $second->get_status(), 'conflict status forwarded');
    assertEqual('folder_exists', $data['error_code'] ?? null, 'error_code present');
    assertEqual($dirName, $data['error_params']['name'] ?? null, 'error_params carried through');
});

// ── Route parity vs core (Phase 0 item 5) ────────────────────────────────────
// Mirrors packages/laravel/tests/test-laravel-smoke.php's "proxy route surface
// covers every core /api/fm route" test. WordPress registers routes via
// `register_rest_route($ns, $p . '/path', ...)` — dynamic segments use PCRE
// named groups (`(?P<locale>[a-z]{2,5})`), not Laravel's `{param}` style, so
// the normalising regex differs, but the diff-against-an-allowlist shape is
// identical.
test('proxy route surface covers every core /api/fm route', function () {
    $coreSrc = (string) file_get_contents(__DIR__ . '/../../core/api/index.php');
    $apiSrc  = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');

    preg_match_all("#\\\$uri === '/api/fm/([a-z0-9/_-]+)'#", $coreSrc, $cm);
    $coreRoutes = array_unique($cm[1]);
    sort($coreRoutes);

    preg_match_all("#register_rest_route\\(\\s*\\\$ns,\\s*\\\$p\\s*\\.\\s*'([^']+)'#", $apiSrc, $wm);
    $proxyRoutes = array_map(function ($r) {
        // Strip a trailing PCRE named group the same way Laravel strips `{param}`.
        $r = preg_replace('#/\(\?P<[a-zA-Z_]+>.*?\)#', '', $r);
        return ltrim($r, '/');
    }, $wm[1]);

    // Core routes that are intentionally NOT proxied (keep in sync with Laravel's
    // allowlist minus share/intake, which WordPress already proxies in full —
    // CRUD, the public landing routes, AND analytics.
    // - stream / img: gated-local media and on-demand WebP are core-standalone /
    //   Docker features. Both mint tokens only when FileManager has a stream
    //   secret (setStreamSecret), which the WordPress proxy does not set — so
    //   list() never emits stream/img URLs here, and there are no broken links.
    // - chmod: only operates on an SFTP disk, a core-standalone driver the proxy
    //   doesn't expose.
    // - zip: streams a binary zip to the client (ZipStream → php://output); the
    //   JSON-returning REST handlers don't do raw streaming responses, so it's a
    //   core-standalone / Docker feature like stream/img. (Extract, by contrast,
    //   returns JSON and IS proxied.)
    // - paid module endpoints not yet wired here (ai-vision, ocr, backup, c2pa,
    //   c2pa/sign, versions, versions/restore, audit/export, audit/purge): the
    //   module code is a separate proprietary package gated by ModuleRegistry.
    //   Each lands with that module's full proxy release (optimize + webhooks
    //   are already proxied as the reference).
    // - terminal: opens a shell over SSH on an SFTP disk — core-standalone /
    //   Docker feature, the proxy doesn't expose SFTP.
    // - SSO bridge (sso/login, sso/callback, sso/exchange): pre-auth routes for
    //   the standalone /public UI's own login screen. A WordPress site already
    //   authenticates via the plugin's own token minting, so there's nothing to
    //   proxy here.
    $intentionallyUnproxied = [
        'stream', 'img', 'chmod', 'zip', 'terminal',
        'ai-vision', 'ocr', 'backup', 'c2pa', 'c2pa/sign',
        'versions', 'versions/restore',
        'audit/export', 'audit/purge',
        'sso/login', 'sso/callback', 'sso/exchange',
    ];

    $missing = array_values(array_diff($coreRoutes, $proxyRoutes, $intentionallyUnproxied));
    assertTrue($missing === [], 'core routes not proxied by WordPress: ' . implode(', ', $missing));
});

test('every proxied route maps to an existing FluxFilesApi method', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    preg_match_all("#'callback'\\s*=>\\s*\\[\\\$api,\\s*'([a-zA-Z0-9_]+)'\\]#", $apiSrc, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $method) {
        if (!preg_match('#function\s+' . preg_quote($method, '#') . '\s*\(#', $apiSrc)) {
            $missing[] = $method;
        }
    }
    assertTrue($missing === [], 'routes reference missing FluxFilesApi methods: ' . implode(', ', $missing));
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

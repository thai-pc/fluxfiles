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
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);   // FluxFilesPlugin::debugLog() only writes under WP_DEBUG
}
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
// Action registry for the extension-API tests: $GLOBALS['WP_ACTIONS'][tag][] = callable.
$GLOBALS['WP_ACTIONS'] = [];
if (!function_exists('do_action')) {
    function do_action($tag, ...$args) { foreach ($GLOBALS['WP_ACTIONS'][$tag] ?? [] as $cb) { $cb(...$args); } }
}
// WP_DEBUG on so the "unknown edition" _doing_it_wrong() notice is observable.
if (!defined('WP_DEBUG')) { define('WP_DEBUG', true); }
$GLOBALS['WP_DOING_IT_WRONG'] = [];
if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong($fn, $msg, $ver) { $GLOBALS['WP_DOING_IT_WRONG'][] = [$fn, $msg, $ver]; }
}
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
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim(strip_tags((string) $v)); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('wp_delete_file')) { function wp_delete_file($f) { @unlink($f); } }
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . implode('&', array_map(fn($k, $v) => $k . '=' . $v, array_keys($args), $args)); }
}
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('FLUXFILES_PLUGIN_FILE')) { define('FLUXFILES_PLUGIN_FILE', __DIR__ . '/../fluxfiles.php'); }
if (!defined('FLUXFILES_VERSION')) { define('FLUXFILES_VERSION', '1.0.0'); }
if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') { return 'http://wp.test/wp-content/plugins/fluxfiles/' . ltrim($path, '/'); }
}
if (!function_exists('rest_url')) {
    // $GLOBALS['WP_PLAIN_PERMALINKS'] switches to the ?rest_route= form WordPress uses without pretty permalinks.
    function rest_url($path = '') {
        return empty($GLOBALS['WP_PLAIN_PERMALINKS'])
            ? 'http://wp.test/wp-json/' . ltrim($path, '/')
            : 'http://wp.test/?rest_route=/' . ltrim($path, '/');
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return (int) ($GLOBALS['WP_OPTIONS']['_test_user_id'] ?? 0); }
}

// Multisite stubs — $GLOBALS['WP_MULTISITE'] / ['WP_BLOG_ID'] drive them; single-site by default.
$GLOBALS['WP_MULTISITE'] = false;
$GLOBALS['WP_BLOG_ID'] = 1;
if (!function_exists('is_multisite')) { function is_multisite() { return (bool) $GLOBALS['WP_MULTISITE']; } }
if (!function_exists('get_current_blog_id')) { function get_current_blog_id() { return (int) $GLOBALS['WP_BLOG_ID']; } }
// Site registry for the multisite page-redirect tests: blog id => [url, flags].
$GLOBALS['WP_SITES'] = [
    1 => ['url' => 'http://wp.test/'],
    2 => ['url' => 'http://wp.test/two/'],
    3 => ['url' => 'http://wp.test/three/', 'archived' => true],
];
if (!function_exists('get_site')) {
    function get_site($id) {
        $d = $GLOBALS['WP_SITES'][(int) $id] ?? null;
        return $d === null ? null : (object) ($d + ['blog_id' => (string) (int) $id, 'deleted' => 0, 'archived' => 0, 'spam' => 0]);
    }
}
if (!function_exists('get_rest_url')) {
    function get_rest_url($id, $path = '') { return $GLOBALS['WP_SITES'][(int) $id]['url'] . 'wp-json/' . ltrim($path, '/'); }
}

// Capability stub — $GLOBALS['WP_CAPS'] is the current user's capability set.
// Defaults to a full-capability administrator so every pre-existing test keeps
// the behaviour it was written against; the H-6 tests set it explicitly.
$GLOBALS['WP_CAPS'] = ['manage_options' => true, 'delete_posts' => true, 'upload_files' => true, 'read' => true];
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['WP_CAPS'][$cap]); }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return (int) ($GLOBALS['WP_OPTIONS']['_test_user_id'] ?? 0) > 0; }
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

test('generateToken forwards download/zip claims and the overlay watermark config', function () use ($secret) {
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
    // The proxy's /img is core's MediaEndpoint, which composites the overlay, so the
    // watermark_* claims forward (Claims clamps them on decode).
    assertTrue(is_array($c->watermark) && !empty($c->watermark['enabled']), 'overlay watermark forwarded');
    assertEqual('logo', $c->watermark['type'] ?? null, 'watermark_type');
    assertEqual('cfg/logo.png', $c->watermark['logo_path'] ?? null, 'watermark_logo_path');
    assertEqual('top-right', $c->watermark['position'] ?? null, 'watermark_position');
});

test('watermark_* sub-claims without the gate are not forwarded', function () use ($secret) {
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(44, ['watermark_text' => 'X', 'watermark_opacity' => 0.4]), $secret);
    assertTrue(!isset($p->watermark_enabled) && !isset($p->watermark_text) && !isset($p->watermark_opacity), 'only the enabled gate carries the config');
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(44, [
        'watermark_enabled' => true, 'watermark_text' => 'CONFIDENTIAL', 'watermark_opacity' => 0.4, 'watermark_font_size' => 30,
    ]), $secret);
    assertEqual(true, $p->watermark_enabled ?? null, 'gate');
    assertEqual('CONFIDENTIAL', $p->watermark_text ?? null, 'text');
    assertEqual(0.4, $p->watermark_opacity ?? null, 'opacity');
    assertEqual(30, $p->watermark_font_size ?? null, 'font size');
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

// Versioning now has proxy routes (/api/fm/versions + /versions/restore, see
// FluxFilesApi's handleVersions()/handleVersionsRestore()), so the gate and its
// tuning claims forward unconditionally — same as allow_share/allow_intake.
test('generateToken forwards versioning claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(56, [
        'allow_versioning'  => true,
        'versioning_max'    => 5,
        'versioning_max_mb' => 50,
    ]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, ($p->allow_versioning ?? false), 'versioning gate forwarded');
    assertEqual(5, $p->versioning_max ?? 0, 'versioning_max forwarded');
    assertEqual(50, $p->versioning_max_mb ?? 0, 'versioning_max_mb forwarded');
});

// Audit export/purge now has proxy routes (/api/fm/audit/export + /audit/purge,
// see FluxFilesApi's handleAuditExport()/handleAuditPurge()), so the gate and
// its retention-days tuning claim forward unconditionally — same as versioning.
test('generateToken forwards audit-export claims', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(57, [
        'allow_audit_export'   => true,
        'audit_retention_days' => 365,
    ]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, ($p->allow_audit_export ?? false), 'audit-export gate forwarded');
    assertEqual(365, $p->audit_retention_days ?? 0, 'audit_retention_days forwarded');
});

// AI Vision now has a proxy route (/api/fm/ai-vision, see FluxFilesApi's
// handleAiVision()), so the gate forwards unconditionally — it used to be a
// comment-only "intentionally NOT forwarded" note with no code at all.
test('generateToken forwards ai-vision gate', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(58, ['allow_ai_vision' => true]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, ($p->allow_ai_vision ?? false), 'ai-vision gate forwarded');
});

test('terminal_pty_url still forwards (the UI embeds the operator\'s own ttyd)', function () use ($secret) {
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(61, ['terminal_pty_url' => 'https://ttyd.example.com/']), $secret);
    assertEqual('https://ttyd.example.com/', $p->terminal_pty_url ?? '', 'terminal_pty_url forwarded');
});

test('about / brand_name / brand_logo_url / accent_color are forwarded in the raw payload', function () use ($secret) {
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(62, [
        'about' => false, 'brand_name' => 'Tệp Acme', 'brand_logo_url' => 'https://acme.example/l.png', 'accent_color' => '#0a7f5a',
    ]), $secret);
    assertEqual(false, $p->about ?? null, 'about forwarded (false kept)');
    assertEqual('Tệp Acme', $p->brand_name ?? '', 'brand_name forwarded');
    assertEqual('https://acme.example/l.png', $p->brand_logo_url ?? '', 'brand_logo_url forwarded');
    assertEqual('#0a7f5a', $p->accent_color ?? '', 'accent_color forwarded');
});

test('BYOB disks register for every route; the SSRF allow-list is applied', function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    preg_match('/private function claims\(.*?\n    }\n/s', $src, $claims);
    assertTrue(str_contains($claims[0] ?? '', 'registerByobDisk'), 'claims() registers BYOB disks (search, quota, usage never build a FileManager)');
    $plugin = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesPlugin.php');
    assertTrue(str_contains($plugin, 'self::seedSsrfAllowHosts();'), 'applied at plugin boot, before any token is decoded');
    assertTrue(str_contains($plugin, "defined('FLUXFILES_SSRF_ALLOW_HOSTS')"), 'wp-config constant honoured');
    preg_match('/public function handleAttach\(.*?\n    }\n/s', $src, $attach);
    assertTrue(str_contains($attach[0] ?? '', 'registerByobDisks'), 'handleAttach mints its own claims, so it registers too');
    // Actually called, so the adapter-core floor guard fails if composer.json allows a core without it.
    putenv('FLUXFILES_SSRF_ALLOW_HOSTS=Sftp.Example:22');
    FluxFilesPlugin::seedSsrfAllowHosts();
    assertEqual(['sftp.example:22'], \FluxFiles\SsrfGuard::$allowHosts, 'env var applied');
    putenv('FLUXFILES_SSRF_ALLOW_HOSTS');
    FluxFilesPlugin::seedSsrfAllowHosts();
    assertEqual([], \FluxFiles\SsrfGuard::$allowHosts, 'cleared');
});

// DLP/PII redaction (docs/design/DLP-PII-REDACTION-DESIGN.md): the gate + its 4 tuning
// claims forward the same way as versioning/audit-export above (unconditional
// gate, !empty() tuning claims) — this was a genuine gap (no assertion existed
// for either virus OR dlp forwarding on this adapter before now).
test('allow_dlp_scan (+ its 4 tuning claims) forwards in proxy mode', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(61, [
        'allow_dlp_scan'      => true,
        'dlp_entity_types'    => ['US_SSN', 'EMAIL_ADDRESS'],
        'dlp_scan_extensions' => ['txt', 'csv'],
        'dlp_max_scan_kb'     => 4096,
        'dlp_min_score'       => 0.8,
    ]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, ($p->allow_dlp_scan ?? false), 'dlp gate forwarded');
    assertEqual(['US_SSN', 'EMAIL_ADDRESS'], (array) ($p->dlp_entity_types ?? []), 'dlp_entity_types forwarded');
    assertEqual(['txt', 'csv'], (array) ($p->dlp_scan_extensions ?? []), 'dlp_scan_extensions forwarded');
    assertEqual(4096, $p->dlp_max_scan_kb ?? 0, 'dlp_max_scan_kb forwarded');
    assertEqual(0.8, $p->dlp_min_score ?? 0, 'dlp_min_score forwarded');
});

// The inert-claims regression (see webhooks/auto_optimize/ai_auto_tag precedent in
// CLAUDE.md): a claim can decode fine in the JWT while fileManager() never actually
// wires the hook. Assert the REAL wiring, not just that the claim round-trips.
test('fileManager() actually WIRES setDlpScanner() (not just a decodable claim) — the inert-claims regression', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    // Wired by core's FileManagerFactory (the module gate lives INSIDE its callbacks, so
    // it stays fail-closed); the behavioural twin is "fileManager() wires …" below.
    assertTrue(strpos($apiSrc, 'FileManagerFactory::wire(') !== false, 'fileManager() delegates the hook wiring to core\'s FileManagerFactory');
    assertTrue(strpos($apiSrc, 'setDlpScanner(') === false && strpos($apiSrc, 'setVirusScanner(') === false, 'no hand-wired scanner copy left in the proxy');
    // 5 sites, not 4: the 4 chunk handlers plus presign(), where a
    // `method:"PUT"` mints a browser→S3 upload URL — the same unscannable side
    // door by another name, so it is refused with the same code.
    $count = substr_count($apiSrc, "'dlp_unscannable'");
    assertEqual(5, $count, 'the 4 chunk handlers AND presign(PUT) each check dlp_unscannable independently');
    assertEqual(5, substr_count($apiSrc, "'virus_unscannable'"), 'and the same 5 sites for virus_unscannable');
});

// Legal hold (docs/design/RETENTION-LEGAL-HOLD-DESIGN.md): the gate forwards like any
// other paid-module claim. Enforcement itself needs no claim at all — it's
// wired inside core's FileManager and inherited automatically since this
// proxy builds the same class, unlike every other hook on this list.
test('allow_legal_hold forwards in proxy mode', function () use ($secret) {
    $token = FluxFilesPlugin::generateToken(62, ['allow_legal_hold' => true]);
    $p = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, ($p->allow_legal_hold ?? false), 'legal-hold gate forwarded');
});

test('the 4 legal-hold routes exist and only place/release/list are module-gated (status is free/core)', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($apiSrc, "ModuleRegistry::require('legal-hold'") !== false, 'handleHold()/handleHoldRelease()/handleHoldList() resolve the legal-hold module gate');
    assertTrue(strpos($apiSrc, 'function handleHoldStatus(') !== false, 'handleHoldStatus() exists');
    assertTrue(strpos($apiSrc, 'function handleHold(') !== false, 'handleHold() exists');
    assertTrue(strpos($apiSrc, 'function handleHoldRelease(') !== false, 'handleHoldRelease() exists');
    assertTrue(strpos($apiSrc, 'function handleHoldList(') !== false, 'handleHoldList() exists');
    assertTrue(strpos($apiSrc, "/hold/status'") !== false, 'GET hold/status route registered');
    assertTrue(strpos($apiSrc, "/hold'") !== false, 'POST hold route registered');
    assertTrue(strpos($apiSrc, "/hold/release'") !== false, 'POST hold/release route registered');
    assertTrue(strpos($apiSrc, "/hold/list'") !== false, 'GET hold/list route registered');
});

test('compliance/scorecard route exists, maps to handleComplianceScorecard(), and is gated by audit perm only (free/core, no module/license gate)', function () {
    // The scorecard is intentionally the OPPOSITE gating shape from every paid
    // module above (handleHold*/handleAuditExport all call ModuleRegistry::require())
    // — it's free/core, so a copy-paste of that pattern onto this route would be
    // a real regression (locking a free dashboard behind a license check it was
    // explicitly designed not to need).
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($apiSrc, 'function handleComplianceScorecard(') !== false, 'handleComplianceScorecard() exists');
    $body = substr($apiSrc, strpos($apiSrc, 'function handleComplianceScorecard('));
    $body = substr($body, 0, strpos($body, "\n    }\n") ?: strlen($body));
    assertTrue(strpos($body, "hasPerm('audit')") !== false, 'handleComplianceScorecard() gates on the audit perm');
    assertTrue(strpos($body, 'ComplianceScorecard::build(') !== false, 'handleComplianceScorecard() calls the shared ComplianceScorecard::build()');
    assertTrue(strpos($body, 'ModuleRegistry::require(') === false, 'handleComplianceScorecard() must NOT have a module/license gate — it is free/core');
    assertTrue(strpos($apiSrc, "/compliance/scorecard'") !== false, 'GET compliance/scorecard route registered');
});

test('gated media stream/img is wired (setStreamSecret + local disk private key)', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($apiSrc, 'FileManagerFactory::wire(') !== false, 'fileManager() wires setStreamSecret() via core\'s FileManagerFactory');
    assertTrue(strpos($apiSrc, '->mediaEndpoint()->stream(') !== false, 'handleStream() runs core\'s MediaEndpoint::stream()');
    assertTrue(strpos($apiSrc, '->mediaEndpoint()->image(') !== false, 'handleImg() runs core\'s MediaEndpoint::image()');
    assertTrue(strpos($apiSrc, 'StreamToken::verify(') === false && strpos($apiSrc, 'ImageToken::verify(') === false, 'no hand-ported token handling left');

    $disks = FluxFilesPlugin::diskConfigs();
    assertTrue(array_key_exists('private', $disks['local']), "local disk config has a 'private' key");
});

test('gated media stream/img URLs are prefixed with the REST namespace, not a bare root path', function () {
    // FileManager::gatedLocalUrl()/imgBaseUrl() default to a bare `/api/fm/...` path,
    // which is only reachable at the iframe's own origin for standalone/Laravel. WP's
    // REST API never resolves at a bare root — only under /wp-json/{namespace}/... —
    // so without setApiBasePath() every <img>/<video> pointed at gated media 404s in
    // a real browser (found via the real-adapter e2e: the emitted src was literally
    // "/api/fm/stream?token=..." with no wp-json/fluxfiles/v1 prefix at all).
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($apiSrc, 'setApiBasePath(') !== false, 'fileManager() wires setApiBasePath()');
    assertTrue(
        (bool) preg_match("/setApiBasePath\(\s*rest_url\(\s*'fluxfiles\/v1'\s*\)\s*\.\s*'\/api\/fm'\s*\)/", $apiSrc),
        'setApiBasePath() is given the REST-namespaced base (rest_url(\'fluxfiles/v1\') . \'/api/fm\'), matching registerRoutes()\'s $ns/$p'
    );
});

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

    // The edition preset (registered by an add-on via fluxfiles_edition_presets) enables both gates.
    $GLOBALS['WP_FILTERS']['fluxfiles_edition_presets'] = [fn () => require __DIR__ . '/fixtures/edition-presets.php'];
    $pro = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(57, ['edition' => 'pro']), $secret);
    assertEqual(true, $pro->allow_share ?? null, 'edition preset enables share');
    assertEqual(true, $pro->allow_intake ?? null, 'edition preset enables intake');
    assertEqual(true, $pro->allow_optimize ?? null, 'the rest of the preset is unaffected');
    unset($GLOBALS['WP_FILTERS']['fluxfiles_edition_presets']);
});

test('role preset (docs/design/ACL-ROLE-PRESETS-DESIGN.md) sets the exact claim bundle', function () use ($secret) {
    $admin = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(60, ['role' => 'admin']), $secret);
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $admin->perms, 'admin perms');
    assertEqual(false, $admin->owner_only ?? false, 'admin is not owner-scoped');
    assertEqual(true, $admin->allow_extract ?? null, 'admin allow_extract');
    assertEqual(true, $admin->allow_chmod ?? null, 'admin allow_chmod');
    assertEqual(true, $admin->allow_code_edit ?? null, 'admin allow_code_edit');
    assertEqual(true, $admin->show_hidden ?? null, 'admin show_hidden');

    $viewer = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(61, ['role' => 'viewer']), $secret);
    assertEqual(['read'], (array) $viewer->perms, 'viewer perms');
    assertEqual(true, $viewer->owner_only ?? false, 'viewer is owner-scoped');
    // Regression (B1): allow_extract/allow_chmod default TRUE when absent from
    // the JWT (Claims::fromJwtPayload) — viewer/editor must set them explicitly
    // false, not rely on omission, or a proxy-minted "editor" token silently
    // gets chmod on SFTP disks.
    assertEqual(false, $viewer->allow_extract ?? null, 'viewer allow_extract');
    assertEqual(false, $viewer->allow_chmod ?? null, 'viewer allow_chmod');

    // Regression: perms is resolved BEFORE the base payload array (which already
    // has an unconditional default) — a plain post-hoc guard would never fire.
    $editor = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(62, ['role' => 'editor']), $secret);
    assertEqual(['read', 'write'], (array) $editor->perms, 'editor perms-early-resolution');
    assertEqual(true, $editor->allow_extract ?? null, 'editor allow_extract');
    assertEqual(false, $editor->allow_chmod ?? null, 'editor allow_chmod');

    // Explicit overrides win over the role default.
    $overridden = \FluxFiles\JwtCompat::decode(
        FluxFilesPlugin::generateToken(63, ['role' => 'viewer', 'perms' => ['read', 'write', 'delete'], 'owner_only' => false]),
        $secret
    );
    assertEqual(['read', 'write', 'delete'], (array) $overridden->perms, 'explicit perms overrides role');
    assertEqual(false, $overridden->owner_only ?? false, 'explicit owner_only=false overrides role default of true');

    // edition + role compose without clobbering each other.
    $GLOBALS['WP_FILTERS']['fluxfiles_edition_presets'] = [fn () => require __DIR__ . '/fixtures/edition-presets.php'];
    $both = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(64, ['edition' => 'pro', 'role' => 'admin']), $secret);
    unset($GLOBALS['WP_FILTERS']['fluxfiles_edition_presets']);
    assertEqual(true, $both->allow_share ?? null, 'edition claim present alongside role');
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $both->perms, 'role claim present alongside edition');

    // role reaches BYOB tokens too in this file (edition already does; a deliberate
    // consistency choice — see FluxFilesPlugin::applyTenantOverrides()'s comment).
    $byob = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateByobToken(65, [
        'my-s3' => ['driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's'],
    ], ['role' => 'admin']), $secret);
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $byob->perms, 'role reaches BYOB perms');
    assertEqual(true, $byob->allow_chmod ?? null, 'role reaches BYOB power-user toggles');
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
    $mbSrc  = (string) file_get_contents(__DIR__ . '/../assets/media-button.js');

    assertTrue(preg_match('#function\s+handleToken\s*\(#', $apiSrc) === 1, 'API has handleToken()');
    assertTrue(preg_match('#function\s+checkLoggedIn\s*\(#', $apiSrc) === 1, 'API has checkLoggedIn() permission cb');
    assertTrue(strpos($apiSrc, "'/token'") !== false, 'API registers the /token route');
    assertTrue(strpos($apiSrc, "'permission_callback' => [self::class, 'checkLoggedIn']") !== false, 'token route uses checkLoggedIn (not checkAuth)');
    assertTrue(strpos($scSrc, 'onTokenRefresh') !== false, 'shortcode wires onTokenRefresh');
    assertTrue(strpos($scSrc, '<script') === false && strpos($scSrc, 'wp_add_inline_script') !== false, 'shortcode attaches its loader to the SDK handle, no raw <script> in the page');
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
// HTTP stubs. $GLOBALS['WP_HTTP_LAST'] records the request; the canned reply is a plain failure.
$GLOBALS['WP_HTTP_LAST'] = null;
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        $GLOBALS['WP_HTTP_LAST'] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 500], 'body' => ''];
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
}
if (!class_exists('WP_Error')) {
    // Enough of WP_Error for the REST-auth filter to inspect codes.
    class WP_Error {
        private array $codes = [];
        private string $message;
        public function __construct($code = '', $message = '', $data = '') { $this->message = (string) $message; if ($code !== '') { $this->codes[] = (string) $code; } }
        public function get_error_message(): string { return $this->message; }
        public function get_error_codes(): array { return $this->codes; }
        public function get_error_code() { return $this->codes[0] ?? ''; }
    }
}
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
    $mbSrc  = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesMediaButton.php')
        . (string) file_get_contents(__DIR__ . '/../assets/media-button.js');
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

// ── No paid plumbing in the free plugin ──────────────────────────────────────
// The licence key, the module-engine settings and the self-updater moved to the
// separate FluxFiles Pro plugin; the free (WordPress.org) build must carry none of it.

test('gates read the licence through the plugin, never straight from the env', function () {
    // LicenseManager::fromEnv() ignores the extension filter entirely.
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($api, 'LicenseManager::fromEnv()') === false, 'no fromEnv() left in the REST proxy');
    assertTrue(substr_count($api, 'FluxFilesPlugin::license()') >= 3, 'gates go through the plugin helper');
});

test('no licence / updater / module-engine plumbing remains in the plugin', function () {
    $root = realpath(__DIR__ . '/..');
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (strpos($path, '/vendor/') !== false || strpos($path, '/tests/') !== false) { continue; }
        if (preg_match('/\.(php|js)$/', $path) === 1) { $files[] = $path; }
    }
    assertTrue(count($files) > 5, 'scanned the plugin sources');
    $banned = ['fluxfiles_license_key', 'upgrader_pre_download', 'plugins_api', 'pre_set_site_transient_update_plugins',
               'fluxfiles_module_env', 'seedModuleEnv', 'FluxFilesUpdater', 'LicenseManager',
               'site_transient_update_plugins', 'upgrader_post_install', 'http_request_args'];
    foreach ($files as $f) {
        $src = (string) file_get_contents($f);
        foreach ($banned as $needle) {
            assertTrue(strpos($src, $needle) === false, basename($f) . ' must not contain ' . $needle);
        }
    }
    assertTrue(!is_file(__DIR__ . '/../includes/FluxFilesUpdater.php'), 'the updater file is gone');
});

test('the reference update server reads the licence from the header, both spellings', function () {
    // Apache+CGI/FastCGI drops `Authorization` unless explicitly passed through, and
    // re-exposes it as REDIRECT_HTTP_AUTHORIZATION. Reading only the plain spelling
    // makes every client on such a host look unlicensed, with no error to explain it.
    $src = (string) file_get_contents(__DIR__ . '/../../../docs/update-server.example.php');
    assertTrue(strpos($src, "REDIRECT_HTTP_AUTHORIZATION") !== false,
        'the CGI spelling is accepted too');
    assertTrue(strpos($src, "\$_GET['license']") === false,
        'the server never accepts the licence from the query string');
});

test('plugin no longer declares Update URI (updates come from wordpress.org)', function () {
    $head = (string) file_get_contents(__DIR__ . '/../fluxfiles.php');
    assertTrue(strpos($head, 'Update URI:') === false, 'Update URI header absent');
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
    assertEqual('http://wp.test/wp-json/fluxfiles/v1/api/fm/share/page', $base, 'points at this site\'s REST page route');
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

test('the seven PUBLIC share/intake routes are deliberately unauthenticated', function () {
    // A recipient has no WordPress account and no JWT — the share token in the query
    // string is the whole gate. If someone "tightens" these to checkAuth, every share
    // link 401s for exactly the people it is for, so the intent is asserted here.
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    foreach (['/share/info', '/share/unlock', '/share/file', '/share/page', '/intake/info', '/intake/upload', '/intake/page'] as $r) {
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

test('public routes delegate to the module dispatcher rather than reimplementing the checks', function () {
    // A second copy of the traversal guards, the MIME rules, the brute-force limiter
    // and the download counter is how a hole appears on one platform only.
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($api, 'PublicRoutes::dispatch(') !== false, 'hands the request to core\'s PublicRoutes');
    assertTrue(strpos($api, 'new \\FluxFiles\\PublicContext(') !== false, 'builds a PublicContext');
    assertTrue(strpos($api, 'handleSharePublic(') === false, 'no call into the legacy in-core share handler');
    assertTrue(strpos($api, 'handleIntakePublic(') === false, 'no call into the legacy in-core intake handler');
    assertTrue(strpos($api, 'PublicLinks.php') === false, 'does not include the legacy core file');
    // WordPress uploads live under wp-content, not under the core directory: without
    // passing its own DiskManager the module resolves against a directory holding
    // none of the files.
    assertTrue(strpos($api, '$this->diskManager,') !== false && strpos($api, 'FluxFilesPlugin::diskConfigs(),') !== false,
        'the WP DiskManager is injected');
    assertTrue(strpos($api, "FluxFilesPlugin::license(),\n            true\n") !== false,
        'the option-aware licence is passed and the context is host-injected');
});

test('WpHttpTransport maps core requests onto wp_remote_request and never throws', function () {
    require_once __DIR__ . '/../includes/WpHttpTransport.php';
    $GLOBALS['WP_REMOTE'] = null;
    $GLOBALS['WP_REMOTE_CALL'] = null;
    if (!function_exists('wp_remote_request')) {
        function wp_remote_request($url, $args = []) {
            $GLOBALS['WP_REMOTE_CALL'] = [$url, $args];
            $r = $GLOBALS['WP_REMOTE'];
            if ($r instanceof Throwable) { throw $r; }
            return $r;
        }
    }
    if (!function_exists('is_wp_error')) { function is_wp_error($v) { return $v instanceof WP_Error; } }
    if (!function_exists('wp_remote_retrieve_headers')) { function wp_remote_retrieve_headers($r) { return $r['headers'] ?? []; } }
    if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? ''; } }
    if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; } }

    $t = new WpHttpTransport();
    assertTrue($t instanceof \FluxFiles\HttpTransportInterface, 'implements core\'s interface');

    $GLOBALS['WP_REMOTE'] = ['response' => ['code' => 201], 'body' => 'ok', 'headers' => ['Content-Type' => 'text/x', 'X-Multi' => ['a', 'b']]];
    $r = $t->request('post', 'https://example.test/x', ['Authorization: Bearer k', 'Content-Type' => 'application/json'], '{}', 7);
    [$url, $args] = $GLOBALS['WP_REMOTE_CALL'];
    assertEqual('https://example.test/x', $url, 'url passed through');
    assertTrue($args['redirection'] === 0, 'redirects are never followed');
    assertEqual('POST', $args['method'], 'method upper-cased');
    assertEqual(7, $args['timeout'], 'timeout');
    assertEqual('{}', $args['body'], 'body');
    assertEqual(['Authorization' => 'Bearer k', 'Content-Type' => 'application/json', 'user-agent' => 'FluxFiles'], $args['headers'], 'both header shapes accepted');
    assertEqual(201, $r['status'], 'status');
    assertEqual('ok', $r['body'], 'body returned');
    assertEqual(['content-type' => 'text/x', 'x-multi' => 'a, b'], $r['headers'], 'response headers lower-cased');

    $GLOBALS['WP_REMOTE'] = new WP_Error('http_request_failed', 'cURL error 28');
    $r = $t->request('GET', 'https://example.test/x');
    assertEqual(0, $r['status'], 'WP_Error -> status 0');
    assertEqual('', $r['body'], 'empty body');
    assertTrue(strpos((string) ($r['error'] ?? ''), 'cURL error 28') !== false, 'error message carried');
    assertTrue(!array_key_exists('body', $GLOBALS['WP_REMOTE_CALL'][1]), 'no body key without a body');

    $GLOBALS['WP_REMOTE'] = ['response' => ['code' => ''], 'body' => 'x', 'headers' => []];
    $r = $t->request('GET', 'https://example.test/x');
    assertTrue($r['status'] === 0 && ($r['error'] ?? '') !== '', 'status 0 always carries an error message');

    $GLOBALS['WP_REMOTE'] = ['response' => ['code' => 200], 'body' => '', 'headers' => new class { public function getAll() { return ['Content-Type' => 'a/b', 'X-Two' => ['1', '2']]; } }];
    $r = $t->request('GET', 'https://example.test/x');
    assertEqual(['content-type' => 'a/b', 'x-two' => '1, 2'], $r['headers'], 'a getAll() header object is flattened');
    assertEqual('FluxFiles', $GLOBALS['WP_REMOTE_CALL'][1]['headers']['user-agent'], 'default user-agent is sent');
    $t->request('GET', 'https://example.test/x', ['User-Agent' => 'Custom/1']);
    assertEqual(['User-Agent' => 'Custom/1'], $GLOBALS['WP_REMOTE_CALL'][1]['headers'], 'a caller-set user-agent is kept');

    $GLOBALS['WP_REMOTE'] = new RuntimeException('boom');
    $r = $t->request('GET', 'https://example.test/x');
    assertEqual(0, $r['status'], 'a throwing HTTP API is contained');
});

test('debugLog is silent without WP_DEBUG and writes with it', function () {
    $run = static function (string $define): string {
        $f = tempnam(sys_get_temp_dir(), 'ffdl') . '.php';
        $log = $f . '.log';
        file_put_contents($f, '<?php define("ABSPATH", "/"); ' . $define . ' ini_set("error_log", $argv[2]); ini_set("log_errors", "1");'
            . 'require $argv[1]; FluxFilesPlugin::debugLog("fx-debug-marker");');
        $p = proc_open([PHP_BINARY, $f, __DIR__ . '/../includes/FluxFilesPlugin.php', $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
        $out = (string) @file_get_contents($log);
        @unlink($f);
        @unlink($log);
        return $out;
    };
    assertTrue(strpos($run(''), 'fx-debug-marker') === false, 'silent without WP_DEBUG');
    assertTrue(strpos($run('define("WP_DEBUG", false);'), 'fx-debug-marker') === false, 'silent with WP_DEBUG false');
    assertTrue(strpos($run('define("WP_DEBUG", true);'), 'fx-debug-marker') !== false, 'logs with WP_DEBUG');
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php')
        . (string) file_get_contents(__DIR__ . '/../includes/WpRateLimiterStorage.php');
    assertTrue(strpos($api, 'error_log(') === false, 'no raw error_log in the API / limiter');
});

test('REST share/page + intake/page are registered as public routes', function () {
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    foreach (['/share/page', '/intake/page'] as $r) {
        $pos = strpos($api, "\$p . '{$r}'");
        assertTrue($pos !== false, "route registered: {$r}");
        assertTrue(strpos(substr($api, $pos, 200), '$public') !== false, "{$r} uses the public args");
    }
});

test('retention / fixity / verify prefer the module seam and 501 module_update_required without one', function () {
    $api = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    foreach (['RetentionProviderInterface', 'FixityProviderInterface', 'AuditVerifierInterface'] as $i) {
        assertTrue(strpos($api, 'instanceof \\FluxFiles\\' . $i) !== false, "dispatches to {$i}");
    }
    // Floor is core ^1.1.0, which carries only the module seam: no pre-seam fallbacks.
    assertTrue(strpos($api, 'method_exists(') === false, 'no method_exists() fallbacks to a pre-seam core');
    assertTrue(strpos($api, 'class_exists(\\FluxFiles\\PublicRoutes::class)') === false, 'no PublicRoutes existence guard');
    assertTrue(substr_count($api, "'module_update_required'") >= 3, 'otherwise 501 module_update_required');
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

require_once __DIR__ . '/../includes/FluxFilesDbSchema.php';
require_once __DIR__ . '/../includes/WpRateLimiterStorage.php';
require_once __DIR__ . '/../includes/WpHttpTransport.php';
require_once __DIR__ . '/../includes/FluxFilesMediaEndpoint.php';
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

test('audit: a refused (403) request is logged as access_denied', function () {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    $keepGet = $_GET;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(7701, ['perms' => ['read']]);
    $_GET = ['disk' => 'local', 'path' => 'probe-' . uniqid() . '.txt'];
    try {
        $resp = (new FluxFilesApi())->handleDelete(new WP_REST_Request(['disk' => 'local', 'path' => $_GET['path']]));
        assertEqual(403, $resp->get_status(), 'read-only token cannot delete');
        $log = (string) @file_get_contents(FluxFilesPlugin::storagePath() . '/uploads/_fluxfiles/audit.jsonl');
        $hit = null;
        foreach (array_filter(explode("\n", $log)) as $line) {
            $row = json_decode($line, true);
            if (($row['action'] ?? '') === 'access_denied' && ($row['context']['user_id'] ?? '') === '7701') {
                $hit = $row;
            }
        }
        assertTrue($hit !== null, 'access_denied written');
        assertEqual($_GET['path'], $hit['context']['file_key'] ?? null, 'probed key recorded');
        assertTrue(strpos((string) ($hit['context']['detail'] ?? ''), 'permission_denied') === 0, 'error code in detail');
    } finally {
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
        $_GET = $keepGet;
    }
});

test('audit: refused writes leave virus_blocked / dlp_blocked / legal_hold_blocked lines (core parity)', function () {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    $keepPost = $_POST;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(7704, ['perms' => ['read', 'write'], 'prefix' => 'refused']);
    $api = new FluxFilesApi();
    try {
        $api->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => 'rf-' . uniqid()])); // authenticates → lastClaims
        $error = new ReflectionMethod(FluxFilesApi::class, 'error');
        $_POST = ['disk' => 'local', 'path' => 'docs/in'];
        $error->invoke($api, 'virus', 422, 'virus_detected', ['name' => 'eicar.txt', 'threat' => 'Eicar-Signature']);
        $_POST = [];
        $error->invoke($api, 'pii', 422, 'pii_detected', ['name' => 'card.txt', 'entities' => ['CREDIT_CARD', 'IBAN']]);
        $resp = $error->invoke($api, 'held', 403, 'legal_hold_active', ['hold_id' => 'h-1', 'path' => 'held.txt']);
        assertEqual('legal_hold_active', $resp->get_data()['error_code'] ?? null, 'response unchanged');
        $by = [];
        $read = function () use (&$by) {
            $by = [];
            $log = (string) @file_get_contents(FluxFilesPlugin::storagePath() . '/uploads/_fluxfiles/audit.jsonl');
            foreach (array_filter(explode("\n", $log)) as $line) {
                $row = json_decode($line, true);
                if (($row['context']['user_id'] ?? '') === '7704') {
                    $by[$row['action']][] = $row['context'];
                }
            }
        };
        $read();
        assertEqual('refused/docs/in/eicar.txt', $by['virus_blocked'][0]['file_key'] ?? null, 'virus_blocked: scoped key incl. upload folder');
        assertEqual('refused/card.txt', $by['dlp_blocked'][0]['file_key'] ?? null, 'dlp_blocked: scoped key');
        assertEqual('CREDIT_CARD,IBAN', $by['dlp_blocked'][0]['detail'] ?? null, 'entity TYPES only');
        assertEqual('refused/held.txt', $by['legal_hold_blocked'][0]['file_key'] ?? null, 'legal_hold_blocked: scoped key');
        assertEqual('h-1', $by['legal_hold_blocked'][0]['detail'] ?? null, 'hold id as detail');
        assertTrue(!isset($by['access_denied']), 'a hold refusal is not ALSO logged as access_denied');
        $error->invoke($api, 'nope', 403, 'permission_denied', []);
        $read();
        assertEqual(1, count($by['access_denied'] ?? []), 'other 403s still audited as access_denied');
    } finally {
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
        $_POST = $keepPost;
    }
});

test('audit: a typed token refused at auth is never logged under a previous caller', function () use ($secret) {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    $api = new FluxFilesApi();
    try {
        // 1st request authenticates as user 7703 (sets lastClaims)…
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(7703);
        $api->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => 'lc-' . uniqid()]));
        // …2nd presents a stream token as a Bearer → 403 token_not_access inside claims().
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \FluxFiles\StreamToken::mint('local', 'x', 'evil', 60, $secret);
        $resp = $api->handleDelete(new WP_REST_Request(['disk' => 'local', 'path' => 'x']));
        assertEqual(403, $resp->get_status());
        $log = (string) @file_get_contents(FluxFilesPlugin::storagePath() . '/uploads/_fluxfiles/audit.jsonl');
        foreach (array_filter(explode("\n", $log)) as $line) {
            $row = json_decode($line, true);
            assertTrue(!(($row['action'] ?? '') === 'access_denied' && ($row['context']['user_id'] ?? '') === '7703'),
                'no access_denied attributed to the previous caller');
        }
    } finally {
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
    }
});

test('audit: presign/zip/share/c2pa/webhook/stream/content are covered in the proxy', function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    foreach (["'POST' => 'presign_post'", "'zip_download'", "\$module . '_create'", "\$module . '_revoke'",
              "'c2pa_verify'", "'webhook_test'", "'content_read'"] as $needle) {
        assertTrue(strpos($src, $needle) !== false, "proxy logs {$needle}");
    }
    // stream_read (audit_reads) is MediaEndpoint's job now; the proxy must hand it the metadata repo.
    assertTrue((bool) preg_match('/new FluxFilesMediaEndpoint\([^;]*\$this->metaRepo\s*\)/s', $src), 'the media endpoint is given the metadata repo for stream_read');
});

test('audit_reads forwards through the token builder', function () use ($secret) {
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(7702, ['audit_reads' => true]), $secret);
    assertEqual(true, $p->audit_reads ?? null, 'audit_reads forwarded');
});

// ── DB storage backend (WpDbMetadataHandler) ─────────────────────────────────
// Fast/no-DB coverage of the SQL WpDbMetadataHandler builds, using a spy
// `wpdb` that records every query() string and lets a test seed canned
// get_row()/get_results()/get_var() responses. Mirrors the shape of
// test-laravel-smoke.php's config-branch wiring check below, plus the SQL
// generation itself since WordPress has no equivalent ORM-query-log fixture.
require_once __DIR__ . '/../includes/WpDbMetadataHandler.php';

class WpdbSpy
{
    public string $prefix = 'wp_';
    public array $queries = [];
    /** @var array<int, array|null> queue of canned get_row() results, consumed in order */
    public array $rowQueue = [];
    /** @var array<int, array> queue of canned get_results() results, consumed in order */
    public array $resultsQueue = [];
    /** @var array<int, mixed> queue of canned get_var() results, consumed in order */
    public array $varQueue = [];

    public function prepare(string $sql, $args)
    {
        $args = is_array($args) ? $args : array_slice(func_get_args(), 1);
        $i = 0;
        return preg_replace_callback('/%[sdi]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? null;
            if ($m[0] === '%i') {
                return '`' . str_replace('`', '``', (string) $v) . '`';
            }
            return is_int($v) ? (string) $v : "'" . addslashes((string) $v) . "'";
        }, $sql);
    }

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        return 1;
    }

    public function get_row(string $sql, $output = ARRAY_A)
    {
        $this->queries[] = $sql;
        return array_shift($this->rowQueue);
    }

    public function get_results(string $sql, $output = ARRAY_A)
    {
        $this->queries[] = $sql;
        return array_shift($this->resultsQueue) ?? [];
    }

    public function get_var(string $sql)
    {
        $this->queries[] = $sql;
        return array_shift($this->varQueue);
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }
}

function makeWpDbHandler(WpdbSpy $spy): WpDbMetadataHandler
{
    global $wpdb;
    $wpdb = $spy;
    return new WpDbMetadataHandler(new \FluxFiles\DiskManager([]));
}

test('WpDbMetadataHandler::audit() chains under a locked head row and advances it', function () {
    \FluxFiles\AuditChain::configure(str_repeat('k', 40));
    try {
        $spy = new WpdbSpy();
        $prev = str_repeat('a', 64);
        $spy->varQueue[] = $prev; // SELECT head_hash … FOR UPDATE
        makeWpDbHandler($spy)->audit('local', 'upload', ['user_id' => 'u', 'file_key' => 'x.txt']);
        $q = $spy->queries;
        assertEqual('START TRANSACTION', $q[0] ?? null, 'transaction opened');
        assertTrue(str_contains($q[1] ?? '', 'fluxfiles_audit_chain_head') && str_contains($q[1], 'FOR UPDATE'), 'head locked');
        $insert = $q[2] ?? '';
        assertTrue(str_contains($insert, 'prev_hash') && str_contains($insert, "'{$prev}'"), 'row links to the previous head');
        preg_match_all("/'([0-9a-f]{64})'/", $insert, $m);
        $hash = end($m[1]);
        assertTrue($hash !== $prev && strlen((string) $hash) === 64, 'new entry hash written');
        assertTrue(str_contains($q[3] ?? '', 'UPDATE') && str_contains($q[3], (string) $hash), 'head advanced to the new hash');
        assertEqual('COMMIT', $q[4] ?? null, 'committed');
    } finally {
        \FluxFiles\AuditChain::configure('');
    }
});

test('WpDbMetadataHandler::audit() without a secret stays a plain insert', function () {
    \FluxFiles\AuditChain::configure('');
    $keep = $_ENV['FLUXFILES_SECRET'] ?? null;
    unset($_ENV['FLUXFILES_SECRET']);
    try {
        $spy = new WpdbSpy();
        makeWpDbHandler($spy)->audit('local', 'upload', ['user_id' => 'u']);
        assertEqual(1, count($spy->queries), 'single INSERT');
        assertTrue(str_starts_with($spy->queries[0], 'INSERT INTO'), 'plain insert');
    } finally {
        if ($keep !== null) { $_ENV['FLUXFILES_SECRET'] = $keep; }
    }
});

test('WpDbMetadataHandler::save() upserts with ON DUPLICATE KEY UPDATE against the file_metadata table', function () {
    $spy = new WpdbSpy();
    $handler = makeWpDbHandler($spy);
    $handler->save('local', 'a.txt', ['title' => 'A', 'uploaded_by' => 'u']);

    $sql = implode(' ', $spy->queries);
    assertTrue(str_contains($sql, 'ON DUPLICATE KEY UPDATE'), 'save() uses an upsert');
    assertTrue(str_contains($sql, $spy->prefix . 'fluxfiles_file_metadata'), 'save() targets the prefixed file_metadata table');
});

test('WpDbMetadataHandler::save() keeps created_at sticky from the existing row, not the new call', function () {
    $spy = new WpdbSpy();
    $spy->rowQueue[] = ['id' => 1, 'title' => 'A', 'alt_text' => null, 'caption' => null, 'tags' => null,
        'owner' => 'u', 'mime' => null, 'size' => null, 'width' => null, 'height' => null,
        'created_at' => 1000, 'modified_at' => null, 'file_hash' => null];
    $handler = makeWpDbHandler($spy);

    $handler->save('local', 'a.txt', ['title' => 'A', 'uploaded_by' => 'u', 'created' => 2000]);

    $sql = implode(' ', $spy->queries);
    assertTrue(str_contains($sql, '1000'), 'sticky created_at (1000) reaches the SQL');
    assertTrue(!str_contains($sql, '2000'), 'the new call\'s created (2000) must NOT override an existing created_at');
});

test('WpDbMetadataHandler::renameChildren() deletes the destination slot, then updates the source row', function () {
    $spy = new WpdbSpy();
    $spy->resultsQueue[] = [['id' => 5, 'path' => 'old/a.txt']];
    $handler = makeWpDbHandler($spy);

    $moved = $handler->renameChildren('local', 'old', 'new');

    assertEqual(1, $moved);
    $deleteIdx = null; $updateIdx = null;
    foreach ($spy->queries as $i => $q) {
        if ($deleteIdx === null && str_starts_with($q, 'DELETE FROM ' . $spy->prefix . 'fluxfiles_file_metadata WHERE disk')) {
            $deleteIdx = $i;
        }
        if ($updateIdx === null && str_starts_with($q, 'UPDATE ' . $spy->prefix . 'fluxfiles_file_metadata SET path')) {
            $updateIdx = $i;
        }
    }
    assertTrue($deleteIdx !== null && $updateIdx !== null, 'both a DELETE (destination clear) and UPDATE (move) query ran');
    assertTrue($deleteIdx < $updateIdx, 'DELETE (clear destination) must run before UPDATE (move source in) — source wins on collision');
    assertTrue(in_array('START TRANSACTION', $spy->queries, true) && in_array('COMMIT', $spy->queries, true), 'wraps the move in a transaction');
});

test('WpDbMetadataHandler::renameDirPrefix() deletes the source unconditionally, then checks before inserting at the destination', function () {
    $spy = new WpdbSpy();
    $spy->resultsQueue[] = [['path' => 'old', 'path_hash' => hash('sha256', 'old')]];
    $spy->varQueue[] = null; // destination does not already exist -> INSERT should run
    $handler = makeWpDbHandler($spy);

    $moved = $handler->renameDirPrefix('local', 'old', 'new');

    assertEqual(1, $moved);
    $deleteIdx = null; $selectIdx = null; $insertIdx = null;
    foreach ($spy->queries as $i => $q) {
        if ($deleteIdx === null && str_starts_with($q, 'DELETE FROM ' . $spy->prefix . 'fluxfiles_directories')) {
            $deleteIdx = $i;
        }
        if ($selectIdx === null && str_starts_with($q, 'SELECT 1 FROM ' . $spy->prefix . 'fluxfiles_directories')) {
            $selectIdx = $i;
        }
        if ($insertIdx === null && str_starts_with($q, 'INSERT INTO ' . $spy->prefix . 'fluxfiles_directories')) {
            $insertIdx = $i;
        }
    }
    assertTrue($deleteIdx !== null && $selectIdx !== null && $insertIdx !== null, 'DELETE, destination SELECT, and INSERT all ran');
    assertTrue($deleteIdx < $selectIdx && $selectIdx < $insertIdx, 'sequence is DELETE source -> SELECT destination -> INSERT (destination wins on collision)');
});

test('WpDbMetadataHandler trash round-trip decodes JSON columns and casts is_dir/size/deleted_at', function () {
    $spy = new WpdbSpy();
    $spy->rowQueue[] = [
        'original_key' => 'a/b', 'disk' => 'local', 'basename' => 'b', 'is_dir' => '1',
        'size' => '42', 'deleted_at' => '1700000000', 'owner' => 'u',
        'variants' => '{"thumb":"x"}', 'meta' => '{"title":"t"}', 'files' => '[]', 'dirs' => '[]',
    ];
    $handler = makeWpDbHandler($spy);

    $entry = $handler->getTrash('local', 'trash-id-1');

    assertTrue($entry !== null, 'getTrash() found the row');
    assertTrue($entry['is_dir'] === true, 'is_dir cast to bool');
    assertTrue($entry['size'] === 42, 'size cast to int');
    assertTrue($entry['deleted_at'] === 1700000000, 'deleted_at cast to int');
    assertEqual(['thumb' => 'x'], $entry['variants'], 'variants JSON decoded');
    assertEqual(['title' => 't'], $entry['meta'], 'meta JSON decoded');
});

test('storage backend option branches metadata repo between StorageMetadataHandler and WpDbMetadataHandler', function () {
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(
        strpos($apiSrc, "get_option('fluxfiles_storage_backend', 'json') === 'db'") !== false,
        'constructor checks the fluxfiles_storage_backend option'
    );
    assertTrue(strpos($apiSrc, 'new WpDbMetadataHandler(') !== false, 'constructor can build WpDbMetadataHandler');

    $bootSrc = (string) file_get_contents(__DIR__ . '/../fluxfiles.php');
    assertTrue(
        strpos($bootSrc, "includes/WpDbMetadataHandler.php") !== false,
        'fluxfiles.php requires WpDbMetadataHandler.php'
    );
});

test('WP-CLI: migrate_json_to_db is wired as `wp fluxfiles migrate-json-to-db`', function () {
    $cliSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesCli.php');
    assertTrue(strpos($cliSrc, 'function migrate_json_to_db') !== false, 'FluxFilesCli defines migrate_json_to_db()');
    assertTrue(strpos($cliSrc, 'wp fluxfiles migrate-json-to-db') !== false, 'docblock documents the migrate-json-to-db example');
    assertTrue(strpos($cliSrc, 'WpDbMetadataHandler(') !== false, 'command targets WpDbMetadataHandler');
    assertTrue(strpos($cliSrc, 'JsonToDbMigrator(') !== false, 'command builds a JsonToDbMigrator');
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
    // Share/Intake's public routes are no longer literal `$uri === …` checks in index.php:
    // PublicRoutes::dispatch() owns them, so read the table it routes from.
    // Guarded: the adapter-core floor guard runs this smoke against older cores that
    // predate PublicRoutes and still check these routes literally in index.php.
    if (class_exists(\FluxFiles\PublicRoutes::class)) {
        foreach (\FluxFiles\PublicRoutes::ROUTES as $publicPaths) {
            foreach ($publicPaths as $publicPath) {
                $coreRoutes[] = substr($publicPath, strlen('/api/fm/'));
            }
        }
    }
    $coreRoutes = array_values(array_unique($coreRoutes));
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
    // chmod and zip USED to be listed here. Both rationales had gone stale:
    // chmod's ("the proxy doesn't expose SFTP") is contradicted by a BYOB
    // sftp disk in the token reaching proxy mode; zip's ("REST
    // handlers don't do raw streaming") is contradicted by handleStream()/
    // handleImg()/handleAuditExport(). allow_zip defaults TRUE, so the UI's
    // "Download ZIP" button rendered in proxy mode and 404'd. Both are proxied now.
    // - SSO bridge (sso/login, sso/callback, sso/exchange): pre-auth routes for
    //   the standalone /public UI's own login screen. A WordPress site already
    //   authenticates via the plugin's own token minting, so there's nothing to
    //   proxy here.
    // AI Vision/OCR/Backup Bridge/C2PA and gated media
    // stream/img are now fully proxied too — see FluxFilesApi's
    // handleAiVision()/handleOcr()/handleBackup()/handleC2pa()/handleC2paSign()/
    // handleStream()/handleImg().
    // - metadata/export, metadata/import (docs/design/DB-STORAGE-MIGRATION-DESIGN.md §7):
    //   MetadataExporter/MetadataImporter work directly against core's own
    //   \FluxFiles\Db\Connection SQL layer. WordPress's `db` backend option (§6)
    //   uses WpDbMetadataHandler on WordPress's own $wpdb connection instead, so
    //   these two classes have nothing to bind to in proxy mode. No such port
    //   exists yet.
    $intentionallyUnproxied = [
        'sso/login', 'sso/callback', 'sso/exchange',
        'metadata/export', 'metadata/import',
        // - tus (resumable uploads): stages partial bytes in core's own
        //   <FLUXFILES_STORAGE_PATH>/tus/ and speaks raw tus (PATCH/HEAD, no JSON
        //   envelope). The embedded UI falls back to a plain upload when /tus
        //   404s, so proxy mode loses nothing it had before. A port would need the
        //   adapter's own staging dir + PATCH body streaming — not done in v1.
        'tus',
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

test('handleOptimize() gates on the allow_optimize claim directly, not ModuleRegistry (FREE/core, not a paid module)', function () {
    // 'optimize' was removed from ModuleRegistry::$map when Optimize moved to
    // free/core (core index.php's /api/fm/optimize route does a direct claim
    // check, not ModuleRegistry::require()). This adapter's manual-trigger route
    // regressed to the stale gate once — assert it mirrors the on-upload hook
    // in the same file (setUploadOptimizer's `if ($claims->autoOptimize ?? false)`
    // block) instead of the paid-module 3-layer gate.
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');

    $start = strpos($apiSrc, 'function handleOptimize(');
    assertTrue($start !== false, 'handleOptimize() method exists');
    $end = strpos($apiSrc, "\n    public function ", $start + 1);
    $body = $end !== false ? substr($apiSrc, $start, $end - $start) : substr($apiSrc, $start);

    assertTrue(strpos($body, "ModuleRegistry::require('optimize'") === false, 'handleOptimize() no longer calls ModuleRegistry::require for optimize');
    assertTrue(strpos($body, 'allowOptimize') !== false, 'handleOptimize() checks the allowOptimize claim directly');
    assertTrue(strpos($body, "'optimize_forbidden'") !== false, 'handleOptimize() throws optimize_forbidden when the claim is absent');
    assertTrue(strpos($body, 'new \\FluxFiles\\OptimizeModule()') !== false, 'handleOptimize() instantiates OptimizeModule directly');
});

test('handleChunkComplete() wires preflight validation without deleting an existing object', function () {
    // Behavior is exercised by core's test-chunk-preflight and write-policy-parity.
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    $start = strpos($apiSrc, 'function handleChunkComplete(');
    assertTrue($start !== false, 'handleChunkComplete() method exists');
    $end = strpos($apiSrc, "\n    public function ", $start + 1);
    $body = $end !== false ? substr($apiSrc, $start, $end - $start) : substr($apiSrc, $start);
    assertTrue(strpos($body, 'fn(int $size) => $fm->validateChunkUpload($disk, $key, $size)') !== false, 'validates the measured parts before completion');
    assertTrue(strpos($body, 'deleteObject(') === false, 'must not delete a live key after a rejected upload');
    assertTrue(strpos($body, "\$claims->uploadCollision === 'overwrite'") !== false, 'passes the collision policy to conditional completion');
});

test('every mutating route logs audit + dispatches webhook (regression: legal-hold/audit-purge/version-restore/metadata-delete/ocr/chunk-lifecycle silently skipped both, twice for version-restore)', function () {
    // Unlike core's index.php (one centralized post-response hook), this proxy has
    // NO central hook — every write route must call logAudit()/dispatchWebhook()
    // itself. A security audit found 6 routes that silently didn't. Guard each one
    // by name so a 3rd recurrence (this is version_restore's SECOND time) fails CI
    // instead of shipping silently again.
    $apiSrc = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');

    $extractMethod = function (string $src, string $name): string {
        $start = strpos($src, "function {$name}(");
        assertTrue($start !== false, "method {$name}() exists");
        $end = strpos($src, "\n    public function ", $start + 1);
        if ($end === false) {
            $end = strpos($src, "\n    private function ", $start + 1);
        }
        return $end !== false ? substr($src, $start, $end - $start) : substr($src, $start);
    };

    $mustLog = [
        'handleHold'            => 'legal_hold_place',
        'handleHoldRelease'     => 'legal_hold_release',
        'handleAuditPurge'      => 'audit_purge',
        'handleVersionsRestore' => 'versions/restore', // 2nd recurrence of this exact bug — see docs above
        'handleDeleteMetadata'  => 'metadata_update',
        'handleOcr'             => 'ocr',
        'handleChunkComplete'   => 'chunk_upload',
        'handleChunkAbort'      => 'chunk_upload',
        'handleSetChmod'        => 'chmod',
    ];
    foreach ($mustLog as $method => $action) {
        $body = $extractMethod($apiSrc, $method);
        assertTrue(strpos($body, 'logAudit(') !== false || strpos($body, 'auditOk(') !== false, "{$method}() records an audit entry");
        assertTrue(strpos($body, "'{$action}'") !== false, "{$method}() logs the '{$action}' action");
        assertTrue(strpos($body, 'dispatchWebhook(') !== false, "{$method}() calls dispatchWebhook()");
    }

    // handleChunkInit() logging at INIT (before any bytes exist) is intentional here —
    // it mirrors core's own behavior (index.php's central hook fires on every
    // successful POST /api/fm/chunk/* substep, not only completion). Assert it's
    // still present so a future edit doesn't silently remove it while "fixing" this test.
    $chunkInitBody = $extractMethod($apiSrc, 'handleChunkInit');
    assertTrue(strpos($chunkInitBody, 'logAudit(') !== false, 'handleChunkInit() still logs too, matching core');
});

// ── H-6: WordPress Subscribers no longer receive full read+write+delete tokens ──

test("H-6: the minted token follows the WP user's capabilities, not one site-wide default", function () use ($secret) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 4242;
    $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'write', 'delete'];
    $saveCaps = $GLOBALS['WP_CAPS'];

    try {
        // A Subscriber on an open-registration site: no upload_files. Used to get
        // read+write+delete over an empty prefix, then a read token over every
        // user's files via [fluxfiles]. Now: no token at all by default…
        $GLOBALS['WP_CAPS'] = ['read' => true];
        $threw = false;
        try { FluxFilesPlugin::tokenForCurrentUser(); } catch (\RuntimeException $e) { $threw = true; }
        assertTrue($threw, 'subscriber is refused a token at mint time');

        // …unless the operator deliberately lowers the bar — then viewer.
        $GLOBALS['WP_FILTERS']['fluxfiles_required_capability'][] = fn () => 'read';
        try {
            $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
            assertEqual(['read'], (array) $p->perms, 'subscriber gets read only');
            assertEqual(true, $p->owner_only ?? null, 'subscriber is owner-scoped');
            assertEqual(false, $p->allow_chmod ?? null, 'subscriber cannot chmod');

            // A Contributor (delete_posts, no upload_files) with the lowered bar:
            // delete_posts must NOT promote to admin.
            $GLOBALS['WP_CAPS'] = ['read' => true, 'edit_posts' => true, 'delete_posts' => true];
            $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
            assertEqual(['read'], (array) $p->perms, 'contributor is a viewer, not an admin');
        } finally {
            unset($GLOBALS['WP_FILTERS']['fluxfiles_required_capability']);
        }

        // An Author as WordPress really defines one: upload_files AND delete_posts
        // (own posts) → editor, NOT admin.
        $GLOBALS['WP_CAPS'] = ['read' => true, 'upload_files' => true, 'delete_posts' => true];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertEqual(['read', 'write'], (array) $p->perms, 'author gets read+write');
        assertEqual(true, $p->owner_only ?? null, 'author is owner-scoped');
        assertTrue(empty($p->allow_code_edit), 'author gets no code editor');

        // A WP Editor (delete_others_posts) → admin bundle.
        $GLOBALS['WP_CAPS'] = ['read' => true, 'upload_files' => true, 'delete_posts' => true, 'delete_others_posts' => true];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertEqual(['read', 'write', 'delete'], (array) $p->perms, 'wp editor gets the site ceiling');

        // An Administrator → admin bundle.
        $GLOBALS['WP_CAPS'] = ['read' => true, 'upload_files' => true, 'delete_posts' => true, 'manage_options' => true];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertEqual(['read', 'write', 'delete'], (array) $p->perms, 'admin gets the site ceiling');
        assertEqual(false, $p->owner_only ?? false, 'admin is not owner-scoped');
    } finally {
        $GLOBALS['WP_CAPS'] = $saveCaps;
    }
});

test('H-6: the site-wide perms option is a ceiling the capability role cannot exceed', function () use ($secret) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 4243;
    $savePerms = $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] ?? null;
    $saveCaps  = $GLOBALS['WP_CAPS'];
    try {
        // Operator narrowed the whole site to read-only: even an administrator
        // must not be minted a write/delete token by the preset.
        $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read'];
        $GLOBALS['WP_CAPS'] = ['read' => true, 'upload_files' => true, 'delete_posts' => true, 'manage_options' => true];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertEqual(['read'], (array) $p->perms, 'ceiling wins over the admin bundle');
    } finally {
        $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = $savePerms;
        $GLOBALS['WP_CAPS'] = $saveCaps;
    }
});

test('H-6: an explicit role override still means what it means in the other builders', function () use ($secret) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 4244;
    $saveCaps = $GLOBALS['WP_CAPS'];
    try {
        // A Subscriber, but the operator deliberately asked for editor.
        $GLOBALS['WP_CAPS'] = ['read' => true];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(['role' => 'editor']), $secret);
        assertEqual(['read', 'write'], (array) $p->perms, 'explicit role is not capability-capped');
    } finally {
        $GLOBALS['WP_CAPS'] = $saveCaps;
    }
});

test('H-6: the REST permission callbacks require a capability, not just a session', function () {
    $src = file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($src, 'function requiredCapability()') !== false, 'requiredCapability() exists');
    assertTrue(strpos($src, "\$cap = 'upload_files';") !== false, 'it defaults to upload_files');
    assertTrue(strpos($src, "apply_filters('fluxfiles_required_capability'") !== false,
        'operators can lower the bar deliberately, via a filter');
    // No permission callback may fall back to a bare is_user_logged_in().
    assertTrue(strpos($src, 'return is_user_logged_in();') === false,
        'no permission callback returns is_user_logged_in() alone');
    foreach (['checkAuth', 'checkLoggedIn'] as $fn) {
        $pos = strpos($src, "function {$fn}(");
        assertTrue($pos !== false, "{$fn}() exists");
        $tail = substr($src, $pos, 1200);
        assertTrue(strpos($tail, 'current_user_can(self::requiredCapability())') !== false,
            "{$fn}() checks the required capability");
    }
});

test('storage protection: .htaccess guards _fluxfiles/, scripts and active content', function () {
    $root = FluxFilesPlugin::storagePath();
    @mkdir($root . '/uploads', 0777, true);
    @unlink($root . '/.htaccess');
    @unlink($root . '/uploads/.htaccess');
    FluxFilesPlugin::protectStorage();

    $rootHt = (string) @file_get_contents($root . '/.htaccess');
    assertTrue(strpos($rootHt, 'Require all denied') !== false, 'storage root denied');
    $up = (string) @file_get_contents($root . '/uploads/.htaccess');
    assertTrue(strpos($up, 'Require all granted') !== false, 'uploads re-allowed');
    assertTrue(strpos($up, 'RedirectMatch 403 (?i)/_fluxfiles(/|$)') !== false, '_fluxfiles at any depth');
    assertTrue(strpos($up, 'php_flag engine off') !== false, 'php engine off');
    assertTrue(strpos($up, 'php[0-9]?|pht') !== false, 'php8/pht denied');
    assertTrue(strpos($up, 'Content-Security-Policy "sandbox"') !== false, 'csp sandbox');
    assertTrue(strpos($up, 'ForceType application/octet-stream') !== false, 'html never rendered');
    assertTrue(is_file($root . '/uploads/index.php'), 'no directory listing');

    // Never clobbers an operator-authored file…
    file_put_contents($root . '/.htaccess', "# mine\n");
    FluxFilesPlugin::protectStorage();
    assertEqual("# mine\n", file_get_contents($root . '/.htaccess'), 'operator file kept');
    // …but does refresh one of our own older versions.
    file_put_contents($root . '/uploads/.htaccess', "# FluxFiles storage protection v0\nold\n");
    FluxFilesPlugin::protectStorage();
    assertTrue(strpos((string) file_get_contents($root . '/uploads/.htaccess'), 'Require all granted') !== false, 'own old version refreshed');
});

test('M-1: CodeMirror is vendored, not pulled from a CDN into the JWT origin', function () {
    $fm = file_get_contents(__DIR__ . '/../../core/assets/fm.js');
    assertTrue(strpos($fm, 'cdnjs.cloudflare.com/ajax/libs/codemirror') === false,
        'no cdnjs script tags remain');
    assertTrue(is_file(__DIR__ . '/../../core/assets/vendor/codemirror/codemirror.min.js'),
        'the vendored core bundle is present');
    // Both proxies have to be able to serve assets/vendor/* or the lazy loaders 404.
    $wp = file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($wp, 'vendor/[A-Za-z0-9._/-]+') !== false, 'the WP asset route reaches vendor/');
    $sp = file_get_contents(__DIR__ . '/../../laravel/src/FluxFilesServiceProvider.php');
    assertTrue(strpos($sp, '(?:/[a-zA-Z0-9._') !== false, 'the Laravel asset route spans slashes');
    $build = file_get_contents(__DIR__ . '/../../../scripts/build-wordpress.sh');
    assertTrue(strpos($build, 'assets/vendor') !== false, 'the plugin build bundles assets/vendor');
});

// The plugin version lives in three places WordPress and wordpress.org read
// independently, and they have to agree — including with the wordpress-vX.Y.Z
// tag, which the release workflow checks. They drifted once: the header sat at
// 0.2.43 through eight tagged releases, which would have made an update channel
// offer the same "update" forever (WordPress compares the advertised version
// against this header, so installing never advances it).
test('plugin version agrees across header, constant and readme.txt', function () {
    $main   = file_get_contents(__DIR__ . '/../fluxfiles.php');
    $readme = file_get_contents(__DIR__ . '/../readme.txt');

    preg_match('/^ \* Version: *(.+)$/m', $main, $h);
    preg_match("/define\('FLUXFILES_VERSION', '([^']*)'\);/", $main, $c);
    preg_match('/^Stable tag: *(.+)$/m', $readme, $r);

    $header = trim($h[1] ?? '');
    $const  = trim($c[1] ?? '');
    $stable = trim($r[1] ?? '');

    assertTrue($header !== '', 'plugin header carries a Version:');
    assertTrue(preg_match('/^\d+\.\d+\.\d+$/', $header) === 1, "header version is X.Y.Z (got '{$header}')");
    assertEqual($header, $const, 'FLUXFILES_VERSION matches the plugin header');
    assertEqual($header, $stable, 'readme.txt Stable tag matches the plugin header');
});

// The PHP floor lives in four places WordPress and Composer each read separately;
// one left behind lets a site update into a release its PHP can't run, or keeps
// advertising an end-of-life PHP.
test('Requires PHP agrees across header, readme.txt, composer.json and FLUXFILES_MIN_PHP', function () {
    $main     = file_get_contents(__DIR__ . '/../fluxfiles.php');
    $readme   = file_get_contents(__DIR__ . '/../readme.txt');
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

    preg_match('/^ \* Requires PHP: *(.+)$/m', $main, $h);
    preg_match('/^Requires PHP: *(.+)$/m', $readme, $r);
    $header = trim($h[1] ?? '');

    assertTrue(preg_match('/^\d+\.\d+\.\d+$/', $header) === 1, "header Requires PHP is X.Y.Z (got '{$header}')");
    assertEqual($header, trim($r[1] ?? ''), 'readme.txt Requires PHP matches the plugin header');
    assertEqual('^' . $header, $composer['require']['php'] ?? null, 'composer.json php floor matches');
    assertEqual($header, $composer['config']['platform']['php'] ?? null, 'composer platform pin matches');
    preg_match("/define\('FLUXFILES_MIN_PHP', '([^']*)'\);/", $main, $m);
    assertEqual($header, $m[1] ?? null, 'FLUXFILES_MIN_PHP (the runtime guard) matches');
});

// The ZIP is the only artifact a wordpress-v* tag produces (the plugin is not
// split to a Composer repo like core/laravel), so the release path has to stay
// wired: the build must accept the tag's version and stamp it into the built
// copy, or a ZIP would ship whatever number the sources happened to carry.
test('the release path can stamp a version into the built plugin', function () {
    $build = file_get_contents(__DIR__ . '/../../../scripts/build-wordpress.sh');
    assertTrue(strpos($build, 'PLUGIN_VERSION') !== false, 'build script takes a version argument');
    assertTrue(strpos($build, 'stamp-wp-version.py') !== false, 'build script invokes the stamper');
    assertTrue(is_file(__DIR__ . '/../../../scripts/stamp-wp-version.py'), 'the stamper exists');

    $wf = __DIR__ . '/../../../.github/workflows/wordpress-release.yml';
    assertTrue(is_file($wf), 'a wordpress-v* release workflow exists');
    $yml = file_get_contents($wf);
    assertTrue(strpos($yml, 'wordpress-v*') !== false, 'it triggers on wordpress-v* tags');
    assertTrue(strpos($yml, 'build-wordpress.sh') !== false, 'it builds the plugin ZIP');
});

// The updater is only as useful as the catalogue behind it: with no `wordpress`
// entry the update server answers `404 unknown module` and a site never learns a
// new version exists — including a security release.
test('the release workflow writes the catalogue entry the update server needs', function () {
    $yml = (string) file_get_contents(__DIR__ . '/../../../.github/workflows/wordpress-release.yml');
    assertTrue(strpos($yml, 'wordpress-catalogue.json') !== false, 'a catalogue entry is produced');
    // The checksum has to be of the bytes actually attached to the release, because
    // UpdateClient re-hashes the download and refuses a mismatch.
    assertTrue(strpos($yml, '"sha256": "${SHA}"') !== false, 'it carries the ZIP sha256');
    assertTrue(strpos($yml, '"zip": "fluxfiles-${VERSION}.zip"') !== false,
        'it names the ZIP actually attached');
});

test('pack-modules keeps the wordpress entry instead of overwriting it', function () {
    // The plugin cannot be packaged by pack-modules (no src/, no repo of its own, and
    // its artifact is a bundled ZIP rather than a git archive), so it must never be
    // added to ModuleRegistry::$map to force it through — that would also gate a free
    // MIT product behind ModuleRegistry::require(). It writes its own entry, which a
    // plain catalogue rewrite would silently delete.
    $pack = (string) file_get_contents(__DIR__ . '/../../../scripts/pack-modules.php');
    assertTrue(strpos($pack, 'EXTERNALLY_BUILT') !== false, 'externally built artifacts are known');
    assertTrue(strpos($pack, "'wordpress' =>") !== false, 'wordpress is one of them');
    assertTrue(strpos($pack, '$existing[$extId]') !== false, 'an existing entry is preserved');

    $reg = (string) file_get_contents(__DIR__ . '/../../../packages/core/api/ModuleRegistry.php');
    assertTrue(strpos($reg, "'wordpress'") === false,
        'the MIT plugin is NOT registered as a gated paid module');
});

test('the update server serves the MIT plugin without demanding a paid licence', function () {
    // No plan in services/license-server/Plans.php grants 'wordpress', so
    // LicenseManager::licensed('wordpress') is false for every key that exists — a
    // licence gate here would withhold security updates from a free product forever.
    // The manifest is still signed and the download still re-hashed; only entitlement
    // is skipped.
    $srv = (string) file_get_contents(__DIR__ . '/../../../docs/update-server.example.php');
    assertTrue(strpos($srv, '$FREE_MODULES') !== false, 'free modules are recognised');
    assertTrue(strpos($srv, "\$FREE_MODULES = ['wordpress']") !== false, 'the plugin is one');
    assertTrue(strpos($srv, 'in_array($module, $FREE_MODULES, true)') !== false,
        'the licence gate is skipped for them');
    assertTrue(strpos($srv, 'sodium_crypto_sign_detached') !== false,
        'the manifest is still signed either way');
});

// ── Adapter audit 2026-10-23 (A-H1 … A-N6, versioning B2) ────────────────────
// Each test below fails on the pre-fix code; the comment on each names the finding.

if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($id) { $GLOBALS['WP_OPTIONS']['_test_user_id'] = (int) $id; return null; }
}
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_html__')) { function esc_html__($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
// A request whose multipart file part is settable (the shared stub returns none).
if (!class_exists('FfUploadRequest')) {
    class FfUploadRequest extends WP_REST_Request
    {
        private array $f;
        public function __construct(array $q, array $f) { parent::__construct([], $q); $this->f = $f; }
        public function get_file_params() { return $this->f; }
    }
}
if (!function_exists('home_url')) { function home_url($p = '') { return 'http://wp.test/' . ltrim((string) $p, '/'); } }
if (!function_exists('site_url')) { function site_url($p = '') { return 'http://wp.test/' . ltrim((string) $p, '/'); } }

$ffUploads = FluxFilesPlugin::storagePath() . '/uploads';
$ffPut = function (string $rel, string $bytes = 'x') use ($ffUploads): string {
    $abs = $ffUploads . '/' . $rel;
    @mkdir(dirname($abs), 0777, true);
    file_put_contents($abs, $bytes);
    return $abs;
};
$ffMetaRepo = function (): \FluxFiles\StorageMetadataHandler {
    return new \FluxFiles\StorageMetadataHandler(new \FluxFiles\DiskManager(FluxFilesPlugin::diskConfigs()));
};
$ffAuditRows = function () use ($ffUploads): array {
    $rows = [];
    foreach (array_filter(explode("\n", (string) @file_get_contents($ffUploads . '/_fluxfiles/audit.jsonl'))) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) { $rows[] = $r; }
    }
    return $rows;
};
$ffAsUser = function (int $uid, array $claims, callable $fn) {
    $keep = $_SERVER['HTTP_AUTHORIZATION'];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken($uid, $claims);
    try { return $fn(); } finally { $_SERVER['HTTP_AUTHORIZATION'] = $keep; }
};

test('A-H1: the Bearer filter never clears a nonce error for a route outside /fluxfiles/v1 (URI/query substring trick)', function () {
    $keep = [$_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] ?? null, $_GET, $GLOBALS['WP_OPTIONS']['_test_user_id'] ?? null];
    try {
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = 1;          // the cookie user (an admin)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?_wpnonce=bad&z=/fluxfiles/v1/';
        $_SERVER['QUERY_STRING'] = '_wpnonce=bad&z=/fluxfiles/v1/';
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/wp/v2/users']];
        $err = new WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
        assertTrue(FluxFilesApi::bypassCookieAuthForBearer($err) === $err, 'wp/v2 keeps its CSRF error');
        assertEqual(1, get_current_user_id(), 'cookie user untouched outside our namespace');

        // ?rest_route= naming our namespace does not count when WP dispatches another route.
        $_GET['rest_route'] = '/fluxfiles/v1/api/fm/list';
        assertTrue(FluxFilesApi::bypassCookieAuthForBearer($err) === $err, 'the dispatched route (query var) is authoritative');
        // Look-alike namespaces are not ours.
        $GLOBALS['wp']->query_vars['rest_route'] = '/fluxfiles/v10/x';
        assertTrue(FluxFilesApi::bypassCookieAuthForBearer($err) === $err, '/fluxfiles/v10 is not /fluxfiles/v1');
    } finally {
        [$_SERVER['HTTP_AUTHORIZATION'], $uri, $_GET, $uid] = $keep;
        $_SERVER['REQUEST_URI'] = $uri;
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = $uid;
        unset($GLOBALS['wp'], $_SERVER['QUERY_STRING']);
    }
});

test('A-H1: on our route only rest_cookie_invalid_nonce is neutralised, and the request is downgraded to user 0', function () {
    $keep = [$_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['WP_OPTIONS']['_test_user_id'] ?? null];
    try {
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/fluxfiles/v1/api/fm/list']];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def.ghi';
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = 1;

        $other = new WP_Error('jwt_auth_invalid_token', 'another plugin');
        assertTrue(FluxFilesApi::bypassCookieAuthForBearer($other) === $other, "another plugin's error is never cleared");
        assertTrue(FluxFilesApi::bypassCookieAuthForBearer(null) === null, 'no error → untouched');

        $r = FluxFilesApi::bypassCookieAuthForBearer(new WP_Error('rest_cookie_invalid_nonce', 'x'));
        assertTrue($r === true, 'stale nonce + Bearer on our route → proceeds');
        assertEqual(0, get_current_user_id(), 'and never as the cookie user');

        $GLOBALS['WP_OPTIONS']['_test_user_id'] = 1;
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        $err = new WP_Error('rest_cookie_invalid_nonce', 'x');
        assertTrue(FluxFilesApi::bypassCookieAuthForBearer($err) === $err, 'no Bearer → WP decides');
    } finally {
        [$_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['WP_OPTIONS']['_test_user_id']] = $keep;
        unset($GLOBALS['wp']);
    }
});

test('A-H2: metadata save/delete/get refuse system paths (_fluxfiles/) like core', function () {
    $api = new FluxFilesApi();
    $save = $api->handleSaveMetadata(new WP_REST_Request(['disk' => 'local', 'key' => '_fluxfiles/audit.jsonl', 'title' => 't']));
    assertEqual(403, $save->get_status(), 'save on _fluxfiles/ refused');
    assertEqual('system_path', $save->get_data()['error_code'] ?? null);
    $del = $api->handleDeleteMetadata(new WP_REST_Request(['disk' => 'local', 'key' => '_variants/x.jpg_thumb.webp']));
    assertEqual(403, $del->get_status(), 'delete on _variants/ refused');
    $get = $api->handleGetMetadata(new WP_REST_Request([], ['disk' => 'local', 'key' => '_fluxfiles/trash.json']));
    assertEqual(403, $get->get_status(), 'get on _fluxfiles/ refused');
});

test("A-H2: an owner_only token cannot rewrite or wipe another user's metadata", function () use ($ffPut, $ffMetaRepo, $ffAsUser) {
    $key = 'meta-own-' . uniqid() . '.txt';
    $ffPut($key);
    $ffMetaRepo()->save('local', $key, ['uploaded_by' => 'alice', 'title' => 'Alice’s']);
    $ffAsUser(7811, ['owner_only' => true], function () use ($key) {
        $api = new FluxFilesApi();
        $r = $api->handleSaveMetadata(new WP_REST_Request(['disk' => 'local', 'key' => $key, 'title' => 'pwned']));
        assertEqual(403, $r->get_status(), 'save refused');
        assertEqual('owner_only', $r->get_data()['error_code'] ?? null);
        $d = $api->handleDeleteMetadata(new WP_REST_Request(['disk' => 'local', 'key' => $key]));
        assertEqual(403, $d->get_status(), 'delete refused');
    });
    assertEqual('Alice’s', $ffMetaRepo()->get('local', $key)['title'] ?? null, 'metadata untouched');
});

test('A-M3: audit + webhook keys are tenant-scoped (a prefixed tenant can find its own entries)', function () use ($ffAuditRows, $ffAsUser) {
    $dir = 'aud-' . uniqid();
    $ffAsUser(7821, ['prefix' => 'tenant-a'], function () use ($dir) {
        $r = (new FluxFilesApi())->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => $dir]));
        assertEqual(200, $r->get_status(), 'mkdir ok');
    });
    $hit = null;
    foreach ($ffAuditRows() as $row) {
        if (($row['action'] ?? '') === 'mkdir' && ($row['context']['user_id'] ?? '') === '7821') { $hit = $row; }
    }
    assertTrue($hit !== null, 'mkdir audited');
    assertEqual('tenant-a/' . $dir, $hit['context']['file_key'] ?? null, 'key carries the tenant prefix');
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($src, "\$context['path'] = \$this->auditKey(\$claims, \$context['path']);") !== false, 'webhook path scoped too');
});

test('A-M4: URL import checks write perm BEFORE any outbound fetch', function () use ($ffAsUser) {
    $ffAsUser(7831, ['perms' => ['read'], 'allow_url_import' => true], function () {
        $r = (new FluxFilesApi())->handleImportUrl(new WP_REST_Request(['disk' => 'local', 'url' => 'http://127.0.0.1:9/x.jpg']));
        assertEqual(403, $r->get_status());
        assertEqual('permission_denied', $r->get_data()['error_code'] ?? null, 'refused on perms, not by the SSRF guard after resolving');
    });
});

test('A-M4: URL import has its own bucket and honours import_rate_limit', function () use ($ffAsUser) {
    $ffAsUser(7832, ['allow_url_import' => true, 'import_rate_limit' => 1], function () {
        $api = new FluxFilesApi();
        $req = new WP_REST_Request(['disk' => 'local', 'url' => 'http://127.0.0.1:9/x.jpg']);
        $first = $api->handleImportUrl($req);
        assertTrue($first->get_status() !== 429, 'first import attempt is not throttled');
        assertEqual(429, $api->handleImportUrl($req)->get_status(), 'second attempt in the window → 429');
    });
});

test('A-N3: presign and c2pa verify spend the WRITE bucket, as core counts them', function () use ($ffAsUser) {
    $ffAsUser(7841, ['rate_write' => 1], function () {
        $api = new FluxFilesApi();
        $req = new WP_REST_Request(['disk' => 'local', 'path' => 'p.txt', 'method' => 'GET', 'ttl' => 60]);
        $api->handlePresign($req);
        assertEqual(429, $api->handlePresign($req)->get_status(), 'presign throttled at rate_write');
    });
    $ffAsUser(7842, ['rate_write' => 1], function () {
        $api = new FluxFilesApi();
        $req = new WP_REST_Request(['disk' => 'local', 'path' => 'p.jpg']);
        $api->handleC2pa($req);
        assertEqual(429, $api->handleC2pa($req)->get_status(), 'c2pa verify throttled at rate_write');
    });
});

test('A-M2: /duplicates and /usage?refresh=true use the dedicated 2/min bucket; plain /usage is cached', function () use ($ffAsUser) {
    $ffAsUser(7851, [], function () {
        $api = new FluxFilesApi();
        $req = new WP_REST_Request([], ['disk' => 'local']);
        $api->handleDuplicates($req);
        $api->handleDuplicates($req);
        assertEqual(429, $api->handleDuplicates($req)->get_status(), '3rd duplicate scan in a minute → 429');
    });
    $ffAsUser(7852, [], function () {
        $api = new FluxFilesApi();
        $refresh = new WP_REST_Request([], ['disk' => 'local', 'refresh' => 'true']);
        assertEqual(200, $api->handleUsage($refresh)->get_status());
        assertEqual(200, $api->handleUsage($refresh)->get_status());
        assertEqual(429, $api->handleUsage($refresh)->get_status(), '3rd forced recompute → 429');
        $plain = $api->handleUsage(new WP_REST_Request([], ['disk' => 'local']));
        assertEqual(200, $plain->get_status(), 'a plain view still works (cache)');
        assertTrue(array_key_exists('cache_age_seconds', $plain->get_data()['data'] ?? []), 'cache age reported');
    });
});

test('A-N4: force_upload="false" is false (FILTER_VALIDATE_BOOLEAN), so it does not bypass dedupe', function () use ($ffAsUser) {
    // (bool) "false" === true: the multipart field used to force EVERY upload that
    // sent it, skipping the dedupe (and, on older cores, the collision policy).
    $dir = 'force-' . uniqid();
    $bytes = 'same-bytes-' . uniqid();
    $mk = function () use ($bytes) {
        $tmp = tempnam(sys_get_temp_dir(), 'ffup');
        file_put_contents($tmp, $bytes);
        return ['name' => 'same.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
    };
    $ffAsUser(7871, ['dedupe_uploads' => true], function () use ($dir, $mk) {
        $api = new FluxFilesApi();
        $a = $api->handleUpload(new FfUploadRequest(['disk' => 'local', 'path' => $dir, 'force_upload' => 'false'], ['file' => $mk()]));
        $b = $api->handleUpload(new FfUploadRequest(['disk' => 'local', 'path' => $dir, 'force_upload' => 'false'], ['file' => $mk()]));
        assertEqual(200, $a->get_status(), 'first upload ok');
        assertEqual(200, $b->get_status(), 'second upload ok');
        assertEqual(true, $b->get_data()['data']['duplicate'] ?? false, 'identical bytes reported as a duplicate, not force-stored');
        $c = $api->handleUpload(new FfUploadRequest(['disk' => 'local', 'path' => $dir, 'force_upload' => 'true'], ['file' => $mk()]));
        assertTrue(empty($c->get_data()['data']['duplicate']), '"true" still forces');
    });
});

test('A-N5: search / search-folders results are unscoped like core', function () use ($ffAsUser) {
    $name = 'findme' . bin2hex(random_bytes(4));
    $ffAsUser(7861, ['prefix' => 'tenant-s'], function () use ($name) {
        $api = new FluxFilesApi();
        assertEqual(200, $api->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => $name]))->get_status());
        $rows = $api->handleSearchFolders(new WP_REST_Request([], ['disk' => 'local', 'q' => $name]))->get_data()['data'] ?? [];
        assertTrue(count($rows) >= 1, 'folder found');
        assertEqual($name, $rows[0]['dir_key'] ?? null, 'dir_key relative to the tenant root');
    });
});

test('the UI page carries the escaped fluxfiles-codemirror meta (WordPress CodeMirror + dark theme)', function () {
    if (!function_exists('includes_url')) { function includes_url($p = '') { return 'http://wp.test/wp-includes/' . ltrim($p, '/'); } }
    if (!function_exists('esc_attr')) { function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } }
    $meta = FluxFilesApi::codeMirrorMeta('http://wp.test/wp-json/fluxfiles/v1/assets', '?', fn ($f) => 'abc12345');
    assertTrue(strpos($meta, '<meta name="fluxfiles-codemirror" content="') === 0, 'meta tag');
    assertTrue(strpos($meta, '"') !== false && substr_count($meta, '"') === 4, 'JSON quotes are escaped inside the attribute');
    $json = json_decode(html_entity_decode(preg_replace('/^.*content="(.*)">$/', '$1', $meta)), true);
    assertEqual(['http://wp.test/wp-includes/js/codemirror/codemirror.min.js'], $json['js'], 'WordPress codemirror.min.js');
    assertEqual(2, count($json['css']), 'core css + dark theme');
    assertTrue(strpos($json['css'][1], 'codemirror-theme-material-darker.css?v=abc12345') !== false, 'theme linked with a content hash');
});

test('A-N6: the iframe UI sends Referrer-Policy: no-referrer and frame-ancestors', function () {
    $h = FluxFilesApi::uiHtmlHeaders('en');
    assertTrue(in_array('Referrer-Policy: no-referrer', $h, true), 'no-referrer');
    $csp = array_values(array_filter($h, fn ($x) => strpos($x, 'Content-Security-Policy: frame-ancestors') === 0));
    assertEqual(1, count($csp), 'frame-ancestors present');
    assertTrue(strpos($csp[0], "'self'") !== false && strpos($csp[0], 'http://wp.test') !== false, 'self + site origin');
    add_filter('fluxfiles_frame_ancestors', fn () => "'self'; script-src *");
    $h2 = FluxFilesApi::uiHtmlHeaders('en');
    assertTrue(count(array_filter($h2, fn ($x) => strpos($x, 'Content-Security-Policy') === 0)) === 0, 'an injected directive is dropped');
    $GLOBALS['WP_FILTERS'] = [];
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($src, "self::uiHtmlHeaders(\$locale)") !== false, 'serveUiHtml() uses them');
});

test('B2: version purger + mover + keeper wiring is core\'s FileManagerFactory (purger/mover NOT behind allow_versioning)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($src, 'setVersionKeeper') === false && strpos($src, 'setVersionPurger') === false && strpos($src, 'setVersionMover') === false,
        'the proxy keeps no hand-wired copy of the version hooks');
    $fac = (string) file_get_contents(__DIR__ . '/../../core/api/FileManagerFactory.php');
    $claimGate = strpos($fac, 'if ($claims->allowVersioning) {');
    assertTrue($claimGate !== false, 'the factory keeps the keeper claim-gated');
    $block = substr($fac, $claimGate, strpos($fac, 'setVersionPurger', $claimGate) - $claimGate);
    assertTrue(strpos($block, 'setVersionKeeper') !== false, 'keeper inside the claim gate; purger/mover follow outside it');
});

test('A-H4: /attach resolves the key through the user\'s token — system path / missing file / foreign owner refused', function () use ($ffPut, $ffMetaRepo) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 9101;
    $GLOBALS['WP_POSTS'] = [];
    $api = new FluxFilesApi();
    try {
        $r = $api->handleAttach(new WP_REST_Request(['url' => 'https://x/y', 'key' => '_fluxfiles/audit.jsonl', 'disk' => 'local']));
        assertEqual(403, $r->get_status(), 'system path refused');
        $r = $api->handleAttach(new WP_REST_Request(['url' => 'https://x/y', 'key' => 'nope-' . uniqid() . '.jpg', 'disk' => 'local']));
        assertEqual(404, $r->get_status(), 'non-existent file refused');
        $hidden = 'att-' . uniqid() . '/.env';
        $ffPut($hidden);
        assertEqual(403, $api->handleAttach(new WP_REST_Request(['key' => $hidden, 'disk' => 'local']))->get_status(), 'hidden file refused');

        $foreign = 'att-' . uniqid() . '/theirs.jpg';
        $ffPut($foreign);
        $ffMetaRepo()->save('local', $foreign, ['uploaded_by' => 'someone-else']);
        add_filter('fluxfiles_token_overrides', function ($o) { $o['owner_only'] = true; return $o; });
        $r = $api->handleAttach(new WP_REST_Request(['key' => $foreign, 'disk' => 'local']));
        assertEqual(403, $r->get_status(), "owner_only: no delete handle on someone else's file");
        assertEqual('owner_only', $r->get_data()['error_code'] ?? null);
        $GLOBALS['WP_FILTERS'] = [];

        add_filter('fluxfiles_token_overrides', function ($o) { $o['prefix'] = 'tenant-x'; return $o; });
        $outside = 'att-' . uniqid() . '/outside.jpg';
        $ffPut($outside);   // exists at the disk root, i.e. OUTSIDE tenant-x
        $r = $api->handleAttach(new WP_REST_Request(['key' => $outside, 'disk' => 'local']));
        assertEqual(404, $r->get_status(), 'a key outside the tenant prefix resolves inside it (and is not there)');
        assertEqual(0, count($GLOBALS['WP_POSTS']), 'no attachment created by any refused call');
    } finally {
        $GLOBALS['WP_FILTERS'] = [];
    }
});

test('N11: renaming a missing file is a 404 not_found envelope (core parity)', function () {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 9101;
    $r = (new FluxFilesApi())->handleRename(new WP_REST_Request(['disk' => 'local', 'path' => 'ghost-' . uniqid() . '.txt', 'name' => 'x.txt']));
    assertEqual(404, $r->get_status(), 'status');
    assertEqual('not_found', $r->get_data()['error_code'] ?? null, 'code');
});

test('N1: /attach refuses with download_forbidden when the token cannot download originals (a watermark forces that)', function () use ($ffPut) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 9101;
    $GLOBALS['WP_POSTS'] = [];
    $key = 'att-' . uniqid() . '/nodl.jpg';
    $ffPut($key);
    try {
        foreach ([['allow_download' => false], ['watermark_enabled' => true]] as $over) {
            $GLOBALS['WP_FILTERS'] = [];
            add_filter('fluxfiles_token_overrides', function ($o) use ($over) { return $over + $o; });
            $r = (new FluxFilesApi())->handleAttach(new WP_REST_Request(['key' => $key, 'disk' => 'local']));
            assertEqual(403, $r->get_status(), json_encode($over) . ' status');
            assertEqual('download_forbidden', $r->get_data()['error_code'] ?? null, json_encode($over) . ' code');
        }
        assertEqual(0, count($GLOBALS['WP_POSTS']), 'no attachment created');
        $GLOBALS['WP_FILTERS'] = [];
        $r = (new FluxFilesApi())->handleAttach(new WP_REST_Request(['key' => $key, 'disk' => 'local']));
        assertEqual(200, $r->get_status(), 'a normal token still attaches: ' . json_encode($r->get_data()));
    } finally {
        $GLOBALS['WP_FILTERS'] = [];
    }
});

test('A-H4: /attach derives the URL (and srcset) server-side; client url/variants are not trusted', function () use ($ffPut) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 9101;
    $GLOBALS['WP_POSTS'] = [];
    $dir = 'att-' . uniqid();
    $ffPut($dir . '/pic.jpg');
    $ffPut($dir . '/_variants/pic.jpg_thumb.webp');
    $base = content_url('uploads/fluxfiles/uploads');
    $r = (new FluxFilesApi())->handleAttach(new WP_REST_Request([
        'url' => 'https://evil.example/x.jpg', 'key' => $dir . '/pic.jpg', 'disk' => 'local', 'width' => 1000, 'height' => 800,
        'variants' => [
            'thumb'  => ['url' => $base . '/' . $dir . '/_variants/pic.jpg_thumb.webp'],
            'medium' => ['url' => 'https://evil.example/m.webp'],
            'large'  => ['url' => $base . '/' . $dir . '/_variants/missing_large.webp'],
        ],
    ]));
    assertEqual(200, $r->get_status(), 'attach ok');
    $id = $r->get_data()['data']['id'];
    $meta = $GLOBALS['WP_POSTS'][$id]['meta'];
    assertEqual($base . '/' . $dir . '/pic.jpg', $meta['_fluxfiles_url'], 'URL from the disk config, not the body');
    assertEqual($dir . '/pic.jpg', $meta['_fluxfiles_key'], 'scoped key stored');
    $srcset = json_decode((string) ($meta['_fluxfiles_variants'] ?? '[]'), true);
    assertEqual([150, 1000], array_map('intval', array_keys($srcset)), 'only the real, existing variant (+ original) kept');
    assertTrue(strpos(json_encode($srcset), 'evil.example') === false, 'no foreign URL in the srcset');
});

test("A-H4: re-picking a file never rewrites alt text on someone else's attachment", function () {
    $GLOBALS['WP_POSTS'] = [];
    $first = FluxFilesAttachments::findOrCreate(['url' => 'https://cdn/a/b.jpg', 'key' => 'a/b.jpg', 'disk' => 'local', 'alt' => 'original']);
    $keepCaps = $GLOBALS['WP_CAPS'];
    try {
        $GLOBALS['WP_CAPS']['edit_post'] = false;
        FluxFilesAttachments::findOrCreate(['url' => 'https://cdn/a/b.jpg', 'key' => 'a/b.jpg', 'disk' => 'local', 'alt' => 'pwned']);
        assertEqual('original', $GLOBALS['WP_POSTS'][$first['id']]['meta']['_wp_attachment_image_alt'], 'alt kept for a non-editor');
        $GLOBALS['WP_CAPS']['edit_post'] = true;
        FluxFilesAttachments::findOrCreate(['url' => 'https://cdn/a/b.jpg', 'key' => 'a/b.jpg', 'disk' => 'local', 'alt' => 'edited']);
        assertEqual('edited', $GLOBALS['WP_POSTS'][$first['id']]['meta']['_wp_attachment_image_alt'], 'an editor may refresh it');
    } finally {
        $GLOBALS['WP_CAPS'] = $keepCaps;
    }
});

test('A-H4: onDelete refuses system/hidden paths and legal holds, and audits a real delete', function () use ($ffPut, $ffMetaRepo, $ffAuditRows) {
    $GLOBALS['WP_OPTIONS']['_test_user_id'] = 9102;
    $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'write', 'delete'];
    $GLOBALS['WP_OPTIONS']['fluxfiles_delete_storage'] = '1';
    $mkPost = function (string $key): int {
        $id = $GLOBALS['WP_NEXT_ID']++;
        $GLOBALS['WP_POSTS'][$id] = ['post' => [], 'meta' => ['_fluxfiles_url' => 'u', '_fluxfiles_key' => $key, '_fluxfiles_disk' => 'local']];
        return $id;
    };
    try {
        $sys = '_fluxfiles/probe-' . uniqid() . '.json';
        $sysAbs = $ffPut($sys, '{}');
        FluxFilesAttachments::onDelete($mkPost($sys));
        assertTrue(is_file($sysAbs), 'a _fluxfiles/ file is never deleted via an attachment');

        $hid = 'odh-' . uniqid() . '/.secret';
        $hidAbs = $ffPut($hid);
        FluxFilesAttachments::onDelete($mkPost($hid));
        assertTrue(is_file($hidAbs), 'a hidden file is never deleted via an attachment');

        $heldDir = 'held-' . uniqid();
        $heldAbs = $ffPut($heldDir . '/a.txt');
        $ffMetaRepo()->addHold('local', 'h-' . uniqid(), ['hold_id' => 'h1', 'path' => $heldDir, 'reason' => 'litigation',
            'placed_by' => 'legal', 'placed_at' => time(), 'released_at' => null]);
        FluxFilesAttachments::onDelete($mkPost($heldDir . '/a.txt'));
        assertTrue(is_file($heldAbs), 'a file under legal hold survives');

        $ok = 'odn-' . uniqid() . '/a.txt';
        $okAbs = $ffPut($ok);
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = 0;   // cron / CLI: no user → no token → file stays
        FluxFilesAttachments::onDelete($pid = $mkPost($ok));
        assertTrue(is_file($okAbs), 'no WP user → nothing deleted');
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = 9102;
        FluxFilesAttachments::onDelete($pid);
        assertTrue(!is_file($okAbs), 'a normal file is deleted when opted in');
        $hit = null;
        foreach ($ffAuditRows() as $row) {
            if (($row['action'] ?? '') === 'delete' && ($row['context']['file_key'] ?? '') === $ok) { $hit = $row; }
        }
        assertTrue($hit !== null, 'the storage delete is audited');
        assertTrue(strpos((string) ($hit['context']['detail'] ?? ''), 'wp_attachment_delete') === 0, 'with its origin');
    } finally {
        $GLOBALS['WP_OPTIONS']['fluxfiles_delete_storage'] = '0';
    }
});

test('A-N2: an unknown role refuses to mint; an unknown edition is ignored (and noted under WP_DEBUG)', function () {
    $threw = false;
    try { FluxFilesPlugin::generateToken(1, ['role' => 'veiwer']); } catch (\InvalidArgumentException $e) { $threw = true; }
    assertTrue($threw, 'generateToken refuses an unknown role');
    $threw = false;
    try { FluxFilesPlugin::generateByobToken(1, [], ['role' => 'veiwer']); } catch (\InvalidArgumentException $e) { $threw = true; }
    assertTrue($threw, 'generateByobToken refuses an unknown role');

    // No add-on registered any edition: the free plugin ignores the name, never throws.
    $GLOBALS['WP_DOING_IT_WRONG'] = [];
    $secret = $GLOBALS['WP_OPTIONS']['fluxfiles_secret'];
    foreach (['enterprize', 'pro'] as $ed) {
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(1, ['edition' => $ed]), $secret);
        assertTrue(!isset($p->allow_share), "edition '{$ed}' without an add-on grants nothing");
        FluxFilesPlugin::generateByobToken(1, [], ['edition' => $ed]);   // no throw
    }
    assertTrue(count($GLOBALS['WP_DOING_IT_WRONG']) >= 2, 'each ignored edition is reported via _doing_it_wrong under WP_DEBUG');

    // Registered by an add-on: applied; a name still absent from the map stays ignored.
    $GLOBALS['WP_FILTERS']['fluxfiles_edition_presets'] = [fn () => require __DIR__ . '/fixtures/edition-presets.php'];
    try {
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(1, ['role' => 'Viewer', 'edition' => 'PRO']), $secret);
        assertEqual(['read'], (array) $p->perms, 'known names still work, case-insensitively');
        assertEqual(true, $p->allow_share ?? null, 'a registered edition applies');
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(1, ['edition' => 'enterprize']), $secret);
        assertTrue(!isset($p->allow_virus_scan), 'a typo is still ignored when presets exist');
        $GLOBALS['WP_FILTERS']['fluxfiles_edition_presets'] = [fn () => ['PRO' => ['allow_share' => true]]];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(1, ['edition' => 'pro']), $secret);
        assertEqual(true, $p->allow_share ?? null, 'map keys match case-insensitively');
        $GLOBALS['WP_FILTERS']['fluxfiles_edition_presets'] = [fn () => 'garbage'];
        FluxFilesPlugin::generateToken(1, ['edition' => 'pro']);   // a malformed map never throws
    } finally {
        unset($GLOBALS['WP_FILTERS']['fluxfiles_edition_presets']);
    }
    FluxFilesPlugin::generateToken(1, ['role' => '', 'edition' => '']);   // empty = unset, no throw
});

test('pro_hints is false by default and an explicit override still wins', function () {
    $secret = $GLOBALS['WP_OPTIONS']['fluxfiles_secret'];
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(1), $secret);
    assertEqual(false, $p->pro_hints ?? null, 'minted with pro_hints=false (core would decode an absent claim as true)');
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(1, ['pro_hints' => true]), $secret);
    assertEqual(true, $p->pro_hints ?? null, 'an add-on / override can turn it back on');
    $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateByobToken(1, [], []), $secret);
    assertEqual(false, $p->pro_hints ?? null, 'BYOB tokens too');
});

// A-M7 needs WordPress's capability + MIME APIs; defined last so every earlier
// token keeps the unrestricted behaviour it was written against.
if (!function_exists('user_can')) {
    function user_can($user, $cap) { return !empty($GLOBALS['WP_CAPS'][$cap]); }
}
if (!function_exists('get_allowed_mime_types')) {
    // What a site with an "allow SVG" plugin returns: WP's own list never offers
    // html/js to a user without unfiltered_html, but plugins widen upload_mimes.
    function get_allowed_mime_types($user = null) {
        return ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'txt|asc|c|cc|h|srt' => 'text/plain',
            'pdf' => 'application/pdf', 'svg' => 'image/svg+xml', 'htm|html' => 'text/html', 'xml' => 'application/xml',
            'js' => 'application/javascript', 'php' => 'application/x-php'];
    }
}

test('A-M7: a user without unfiltered_html never gets html/svg/xml/js/php in allowed_ext', function () use ($secret) {
    $keepCaps = $GLOBALS['WP_CAPS'];
    try {
        $GLOBALS['WP_CAPS'] = ['upload_files' => true, 'read' => true];   // an Author
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(31), $secret);
        $ext = (array) ($p->allowed_ext ?? null);
        assertTrue($ext !== [] && isset($p->allowed_ext), 'unrestricted default becomes an explicit list');
        foreach (['html', 'htm', 'svg', 'xml', 'js', 'php'] as $bad) {
            assertTrue(!in_array($bad, $ext, true), "{$bad} withheld");
        }
        foreach (['jpg', 'png', 'txt', 'pdf'] as $good) {
            assertTrue(in_array($good, $ext, true), "{$good} still allowed");
        }
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(31, ['allowed_ext' => ['JPG', 'svg', 'exe', 'html']]), $secret);
        assertEqual(['jpg'], (array) $p->allowed_ext, 'an explicit list is intersected with WP and the deny-list');
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(31, ['claims' => ['allowed_ext' => ['svg']]]), $secret);
        assertEqual([], (array) $p->allowed_ext, 'the claims escape hatch cannot re-open it (empty = nothing allowed)');

        $GLOBALS['WP_CAPS']['unfiltered_html'] = true;                     // an Administrator
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::generateToken(32), $secret);
        assertTrue(!isset($p->allowed_ext), 'unfiltered_html keeps the unrestricted default');
    } finally {
        $GLOBALS['WP_CAPS'] = $keepCaps;
    }
});

test('A-M7: nginx sites get an admin notice that the uploads dir must not run PHP / serve active content', function () {
    require_once __DIR__ . '/../includes/FluxFilesAdmin.php';
    $keep = [$_SERVER['SERVER_SOFTWARE'] ?? null, $GLOBALS['pagenow'] ?? null];
    try {
        $GLOBALS['WP_OPTIONS']['fluxfiles_disks'] = ['local'];
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
        $GLOBALS['pagenow'] = 'plugins.php';
        $admin = (new ReflectionClass('FluxFilesAdmin'))->newInstanceWithoutConstructor();
        ob_start();
        $admin->renderNginxStorageNotice();
        $out = (string) ob_get_clean();
        assertTrue(strpos($out, 'nginx') !== false && strpos($out, 'deny all') !== false, 'notice + rules shown on nginx');
        assertTrue(strpos($out, '/wp-content/uploads/fluxfiles/uploads/') !== false, 'names the uploads location');

        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
        ob_start();
        $admin->renderNginxStorageNotice();
        assertEqual('', (string) ob_get_clean(), 'no notice on Apache (.htaccess applies)');
    } finally {
        [$_SERVER['SERVER_SOFTWARE'], $GLOBALS['pagenow']] = $keep;
    }
});

// ── Paid modules through the proxy (found by tests/modules-live/wordpress) ──────
// The LIVE WordPress rig (real plugin + 13 real modules + a licence pasted into the
// Settings screen) turned up two plugin bugs; these lock both without Docker.

test('public share/intake routes: the filter-supplied licence reaches the module via PublicContext', function () {
    // The gate in PublicRoutes::dispatch() and the module both consult $ctx->license, so
    // it must be built from the plugin OPTION (FluxFilesPlugin::license()), never from
    // env-only LicenseManager::fromEnv() — else every recipient of a share link / portal
    // on a licensed shared host got `402 license_required`. publicLink() exits after the
    // module, so this runs it in a child process with a fake licence verifier (records
    // the key it was built from, entitles everything) and a fake PublicModuleInterface
    // share module that reports what its context carried.
    $child = <<<'CHILD'
<?php
[$self, $autoload, $wpDir, $optKey] = $argv;
define('ABSPATH', sys_get_temp_dir() . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ff-wp-lic-' . getmypid());
define('FLUXFILES_PLUGIN_FILE', $wpDir . '/fluxfiles.php');
define('FLUXFILES_PLUGIN_DIR', $wpDir . '/');
$GLOBALS['WP_OPTIONS'] = ['fluxfiles_secret' => 'wp-smoke-secret-key-0123456789abcdef'];
function get_option($k, $d = false) { return $GLOBALS['WP_OPTIONS'][$k] ?? $d; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function content_url($p = '') { return 'http://wp.test/wp-content/' . ltrim($p, '/'); }
function plugins_url($p = '', $f = '') { return 'http://wp.test/wp-content/plugins/fluxfiles/' . ltrim($p, '/'); }
function rest_url($p = '') { return 'http://wp.test/wp-json/' . ltrim($p, '/'); }
function add_query_arg($a, $u) { return $u . '?' . http_build_query($a); }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function status_header($c) {}
function wp_send_json($d, $c = 200) { echo json_encode($d); exit; }
function add_action(...$a) {} function add_filter(...$a) {} function do_action(...$a) {}
// An add-on supplies the licence through the filter; no key given -> no filter value (NoLicense).
function apply_filters($t, $v, ...$a) { return $t === 'fluxfiles_license' && $GLOBALS['optKey'] !== '' ? new \FluxFiles\WpSmokeLicense($GLOBALS['optKey']) : $v; }
$GLOBALS['optKey'] = $optKey;
class WP_REST_Request {
    public function get_method() { return 'GET'; }
    public function get_route() { return '/fluxfiles/v1/api/fm/share/info'; }
}
require $autoload;
class_exists(\FluxFiles\LicenseCheckInterface::class);
eval('namespace FluxFiles; class WpSmokeLicense implements LicenseCheckInterface {
    public ?string $key;
    public function __construct(?string $key = null) { $this->key = $key; }
    public function licensed(string $module): bool { return true; }
    public function status(): string { return "active"; }
    public function info(): array { return []; }
}');
eval('namespace FluxFiles; class WpSmokeShare implements ModuleInterface, PublicModuleInterface {
    public static function id(): string { return "share"; }
    public static function claim(): string { return "allow_share"; }
    public function handlePublic(string $method, string $uri, PublicContext $ctx): void {
        echo json_encode(["uri" => $uri, "licence_key" => $ctx->license->key ?? null, "host" => $ctx->hostInjected, "secret_ok" => $ctx->secretConfigured()]);
    }
    public static function pages(): array { return []; }
}');
\FluxFiles\ModuleRegistry::register('share', \FluxFiles\WpSmokeShare::class);
require $wpDir . '/includes/FluxFilesPlugin.php';
require $wpDir . '/includes/FluxFilesApi.php';
$m = new ReflectionMethod('FluxFilesApi', 'publicLink');
$m->invoke(new FluxFilesApi(), 'share', new WP_REST_Request());
CHILD;
    $file = tempnam(sys_get_temp_dir(), 'ffwplic') . '.php';
    file_put_contents($file, $child);
    $autoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
    $run = static function (string $opt) use ($file, $autoload): string {
        $cmd = [PHP_BINARY, $file, realpath($autoload), realpath(__DIR__ . '/..'), $opt];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return (string) $out;
    };
    try {
        $out = $run('key-from-filter');
        assertTrue(strpos($out, '"uri":"\/api\/fm\/share\/info"') !== false, 'the module was dispatched: ' . substr($out, 0, 300));
        assertTrue(strpos($out, '"licence_key":"key-from-filter"') !== false,
            'the context licence came from the fluxfiles_license filter: ' . substr($out, 0, 300));
        assertTrue(strpos($out, '"host":true') !== false && strpos($out, '"secret_ok":true') !== false, 'host-injected context with the WP secret');
        // No licence anywhere → the context carries none; nothing is invented.
        $out = $run('');
        assertTrue(strpos($out, '"error_code":"license_required"') !== false, 'no add-on licence -> NoLicense -> 402 license_required: ' . substr($out, 0, 300));
    } finally {
        @unlink($file);
    }
});

test('public recipient page: the REST page routes inject the WP REST base, with the gate and headers', function () {
    // PublicRoutes::dispatch() hard-codes `/api/fm` for pages (right for standalone), so a
    // page served through it would call the wrong API under /wp-json/. Run both entry
    // points (the REST route) in a child with a fake page module and a fake
    // verifier, and read the served HTML. Args: autoload wpDir entry mode secret licensed
    // (comma list of blog ids licensed; "-" none) blog.
    $child = <<<'CHILD'
<?php
[$self, $autoload, $wpDir, $entry, $multi, $secret, $licensed, $blog, $method] = $argv;
$tree = sys_get_temp_dir() . '/ff-wp-page-' . getmypid();
define('ABSPATH', $tree . '/');
define('WP_CONTENT_DIR', $tree . '/wp-content');
define('FLUXFILES_PLUGIN_FILE', $wpDir . '/fluxfiles.php');
define('FLUXFILES_PLUGIN_DIR', $wpDir . '/');
$GLOBALS['CUR'] = 1;
$GLOBALS['LIC'] = $licensed === '-' ? [] : array_map('intval', explode(',', $licensed));
$GLOBALS['OPT'] = [1 => ['fluxfiles_secret' => $secret], 2 => ['fluxfiles_secret' => $secret . '-two']];
function get_option($k, $d = false) { return $GLOBALS['OPT'][$GLOBALS['CUR']][$k] ?? $d; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function content_url($p = '') { return 'http://wp.test/wp-content/' . ltrim($p, '/'); }
function plugins_url($p = '', $f = '') { return 'http://wp.test/wp-content/plugins/fluxfiles/' . ltrim($p, '/'); }
function rest_url($p = '') { return 'http://wp.test/wp-json/' . ltrim($p, '/'); }
function get_rest_url($id, $p = '') { return 'http://wp.test/' . ((int) $id === 2 ? 'two/' : '') . 'wp-json/' . ltrim($p, '/'); }
function home_url($p = '') { return 'http://wp.test' . $p; }
function site_url($p = '') { return 'http://wp.test' . $p; }
function add_query_arg($a, $u) { return $u . '?' . http_build_query($a); }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function status_header($c) { http_response_code($c); }
function wp_send_json($d, $c = 200) { echo json_encode($d); exit; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function add_action(...$a) {} function add_filter(...$a) {} function do_action(...$a) {}
function apply_filters($t, $v, ...$a) { return $t === 'fluxfiles_license' ? new \FluxFiles\WpSmokeLicense() : $v; }
function is_multisite() { return $GLOBALS['MULTI']; }
function get_current_blog_id() { return $GLOBALS['CUR']; }
function get_site($id) { return in_array((int) $id, [1, 2], true) ? (object) ['blog_id' => (string) $id, 'deleted' => 0, 'archived' => 0, 'spam' => 0] : null; }
function switch_to_blog($id) { $GLOBALS['STACK'][] = $GLOBALS['CUR']; $GLOBALS['CUR'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['CUR'] = array_pop($GLOBALS['STACK']); return true; }
$GLOBALS['MULTI'] = $multi === '1';
$GLOBALS['STACK'] = [];
class WP_REST_Request {
    public function __construct(public string $m = 'GET', public string $r = '') {}
    public function get_method() { return $this->m; }
    public function get_route() { return $this->r; }
}
require $autoload;
class_exists(\FluxFiles\LicenseCheckInterface::class);
eval('namespace FluxFiles; class WpSmokeLicense implements LicenseCheckInterface {
    public ?string $key;
    public function __construct(?string $key = null) { $this->key = $key; }
    public function licensed(string $module): bool { return in_array($GLOBALS["CUR"], $GLOBALS["LIC"], true); }
    public function status(): string { return "active"; }
    public function info(): array { return []; }
}');
file_put_contents($tree . '/share.html', "<!doctype html><html><head><title>t</title></head><body>page</body></html>");
eval('namespace FluxFiles; class WpSmokePageShare implements ModuleInterface, PublicModuleInterface {
    public static function id(): string { return "share"; }
    public static function claim(): string { return "allow_share"; }
    public function handlePublic(string $method, string $uri, PublicContext $ctx): void {}
    public static function pages(): array { return ["share" => ABSPATH . "share.html"]; }
}');
@mkdir($tree, 0777, true);
file_put_contents($tree . '/share.html', "<!doctype html><html><head><title>t</title></head><body>page</body></html>");
\FluxFiles\ModuleRegistry::register('share', \FluxFiles\WpSmokePageShare::class);
require $wpDir . '/includes/FluxFilesPlugin.php';
require $wpDir . '/includes/FluxFilesApi.php';
register_shutdown_function(static function () { echo "\nSTATUS=" . http_response_code() . "\nHEADERS=" . json_encode(headers_list()); });
$_GET = ['token' => 'x'] + ($blog !== '' ? ['blog' => $blog] : []);
$m = new ReflectionMethod('FluxFilesApi', 'publicLink');
$m->invoke(new FluxFilesApi(), 'share', new WP_REST_Request($method, '/fluxfiles/v1/api/fm/share/page'));
CHILD;
    $file = tempnam(sys_get_temp_dir(), 'ffwppage') . '.php';
    file_put_contents($file, $child);
    $autoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
    $run = static function (string $entry, string $multi, string $secret, string $licensed, string $blog = '', string $method = 'GET') use ($file, $autoload): string {
        $cmd = [PHP_BINARY, $file, realpath($autoload), realpath(__DIR__ . '/..'), $entry, $multi, $secret, $licensed, $blog, $method];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return (string) $out;
    };
    $good = 'wp-smoke-secret-key-0123456789abcdef';
    $base = 'http://wp.test/wp-json/fluxfiles/v1/api/fm';
    // header() is a no-op under the CLI SAPI (headers_list() stays empty), so the header
    // set is asserted on the shared helper's source: one implementation serves both entries.
    $plug = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesPlugin.php');
    $helper = substr($plug, (int) strpos($plug, 'function servePublicPage('));
    $helper = substr($helper, 0, (int) strpos($helper, 'Get the FluxFiles base installation path'));
    assertEqual(2, substr_count($helper, "header('Referrer-Policy: no-referrer')"), 'page and error path both send no-referrer');
    assertEqual(2, substr_count($helper, "header('X-Content-Type-Options: nosniff')"), 'page and error path both send nosniff');
    assertTrue(strpos($helper, "header('Cache-Control: private, no-store')") !== false, 'never cached by a shared proxy');
    assertTrue(strpos($helper, 'FluxFilesApi::uiHtmlHeaders(') !== false && strpos($helper, 'FrameAncestors::policy') === false, 'frame-ancestors comes from the WP UI policy, not core\'s env-only one');
    $csp = array_values(array_filter(FluxFilesApi::uiHtmlHeaders('en'), static fn ($h) => stripos($h, 'Content-Security-Policy:') === 0));
    assertTrue(count($csp) === 1 && strpos($csp[0], "frame-ancestors 'self'") !== false, 'the UI policy yields a frame-ancestors CSP');
    try {
        $out = $run('rest', '0', $good, '1');
        assertTrue(strpos($out, '<meta name="fm-api-base" content="' . $base . '">') !== false, "page carries the WP REST api base: " . substr($out, 0, 300));
        assertTrue(strpos($out, 'content="/api/fm"') === false, "not the standalone base");
        assertTrue(strpos($out, 'STATUS=200') !== false, "200");
        // Gate failures: JSON envelope + hardening headers, no page.
        $out = $run('rest', '0', $good, '-');
        assertTrue(strpos($out, '"error_code":"license_required"') !== false && strpos($out, 'STATUS=402') !== false, "unlicensed -> 402 envelope: " . substr($out, 0, 300));
        assertTrue(strpos($out, 'fm-api-base') === false, "no page when unlicensed");
        // The placeholder secret counts as "not configured" (as in PublicContext).
        $out = $run('rest', '0', \FluxFiles\PublicContext::DEFAULT_SECRET, '1');
        assertTrue(strpos($out, 'STATUS=500') !== false && strpos($out, '"error_code":"server_error"') !== false, "default secret refused");
        $out = $run('rest', '0', '', '1');
        assertTrue(strpos($out, 'STATUS=500') !== false, "empty secret refused");
        // HEAD is answered like GET; other verbs are not a page.
        $out = $run('rest', '0', $good, '1', '', 'HEAD');
        assertTrue(strpos($out, 'STATUS=200') !== false && strpos($out, 'fm-api-base') !== false, 'HEAD served like GET');
        $out = $run('rest', '0', $good, '1', '', 'POST');
        assertTrue(strpos($out, 'STATUS=404') !== false && strpos($out, 'fm-api-base') === false, 'POST is not a page');

        // The REST page route resolves the hint itself: another blog -> 302 (no page served),
        // a bad blog -> 404 envelope, the booted blog or no hint -> the page.
        $out = $run('rest', '1', $good, '1,2', '2');
        assertTrue(strpos($out, 'STATUS=302') !== false && strpos($out, 'fm-api-base') === false, 'REST page: blog 2 hint from blog 1 -> 302: ' . substr($out, 0, 300));
        $out = $run('rest', '1', $good, '1,2', '99');
        assertTrue(strpos($out, 'STATUS=404') !== false && strpos($out, '"error_code":"not_found"') !== false && strpos($out, 'fm-api-base') === false, 'REST page: unknown blog -> 404');
        $out = $run('rest', '1', $good, '1,2', '1');
        assertTrue(strpos($out, 'STATUS=200') !== false && strpos($out, 'fm-api-base') !== false, 'REST page: own blog hint -> page');
        $out = $run('rest', '1', $good, '1,2', '');
        assertTrue(strpos($out, 'STATUS=200') !== false, 'REST page: no hint -> page');
        $out = $run('rest', '0', $good, '1', '2');
        assertTrue(strpos($out, 'STATUS=200') !== false, 'REST page: single site ignores the hint');
    } finally {
        @unlink($file);
    }
});

test('settings: `audit` can be granted as a default permission (else no embed token ever carries it)', function () use ($secret) {
    // tokenForCurrentUser() intersects the capability-derived role's perms with the
    // `fluxfiles_default_perms` option. The admin role carries `audit`, but the
    // Settings screen could only ever store read/write/delete, so not even an
    // Administrator's shortcode token had it: Activity log, audit export/verify,
    // fixity, legal hold and retention all answered 403 through the plugin.
    require_once __DIR__ . '/../includes/FluxFilesAdmin.php';
    if (!function_exists('esc_attr')) { function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } }
    if (!function_exists('esc_html')) { function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } }
    $admin = (new ReflectionClass('FluxFilesAdmin'))->newInstanceWithoutConstructor();
    assertEqual(['read', 'write', 'audit'], $admin->sanitizePerms(['read', 'write', 'audit', 'root']), 'audit survives the sanitizer, junk does not');
    $keep = $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] ?? null;
    $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'audit'];
    ob_start();
    try { $admin->renderPermsField(); } finally { $html = (string) ob_get_clean(); }
    assertTrue(strpos($html, 'value="audit" checked') !== false, 'an "Audit" checkbox is rendered (and reflects the option)');

    $keepUser = $GLOBALS['WP_OPTIONS']['_test_user_id'] ?? null;
    $keepCaps = $GLOBALS['WP_CAPS'];
    try {
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = 1;
        $GLOBALS['WP_CAPS'] = ['manage_options' => true, 'upload_files' => true, 'read' => true, 'unfiltered_html' => true];
        $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'write', 'delete', 'audit'];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertTrue(in_array('audit', $p->perms, true), 'an Administrator gets audit once it is ticked');
        $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'write', 'delete'];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertTrue(!in_array('audit', $p->perms, true), 'and not when it is unticked (the option stays the ceiling)');
        $GLOBALS['WP_CAPS'] = ['upload_files' => true, 'read' => true];
        $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = ['read', 'write', 'delete', 'audit'];
        $p = \FluxFiles\JwtCompat::decode(FluxFilesPlugin::tokenForCurrentUser(), $secret);
        assertTrue(!in_array('audit', $p->perms, true), 'an Author never gets audit, ticked or not (role still caps)');
    } finally {
        $GLOBALS['WP_OPTIONS']['_test_user_id'] = $keepUser;
        $GLOBALS['WP_CAPS'] = $keepCaps;
        $GLOBALS['WP_OPTIONS']['fluxfiles_default_perms'] = $keep;
    }
});

// (module-engine settings moved to the FluxFiles Pro plugin; see the extension-API tests below)

// ── 1.x proxy-parity fixes: X-Accel encoding, audit action names, 403 codes, asset caching ──
test('audit: a code-editor save is logged as content_update (the name the UI labels), not content_edit', function () {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(7710, ['perms' => ['read', 'write'], 'allow_code_edit' => true, 'prefix' => 'cu-' . uniqid()]);
    try {
        $api = new FluxFilesApi();
        $dir = 'cu-dir-' . uniqid();
        $api->handleMkdir(new WP_REST_Request(['disk' => 'local', 'path' => $dir]));
        $root = FluxFilesPlugin::storagePath() . '/uploads';
        $claims = (new ReflectionProperty(FluxFilesApi::class, 'lastClaims'))->getValue($api);
        $abs = $root . '/' . $claims->scopePath($dir) . '/conf.txt';
        file_put_contents($abs, 'old');
        $resp = $api->handlePutContent(new WP_REST_Request(['disk' => 'local', 'path' => $dir . '/conf.txt', 'content' => 'new']));
        assertEqual(200, $resp->get_status(), 'save ok: ' . json_encode($resp->get_data()));
        $actions = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents($root . '/_fluxfiles/audit.jsonl'))) as $line) {
            $row = json_decode($line, true);
            if (($row['context']['user_id'] ?? '') === '7710') {
                $actions[] = $row['action'] ?? '';
            }
        }
        assertTrue(in_array('content_update', $actions, true), 'content_update logged: ' . json_encode($actions));
        assertTrue(!in_array('content_edit', $actions, true), 'legacy name gone');
    } finally {
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
    }
});

test('403s carry the error codes core uses (disk_denied / permission_denied) and none is codeless', function () {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    try {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(7711, ['perms' => ['read'], 'disks' => ['local']]);
        $api = new FluxFilesApi();
        $r = $api->handleSaveMetadata(new WP_REST_Request(['disk' => 'local', 'key' => 'a.txt', 'title' => 't']));
        assertEqual(403, $r->get_status(), 'write needed');
        assertEqual('permission_denied', $r->get_data()['error_code'] ?? null, 'permission_denied');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(7712, ['perms' => ['read', 'write'], 'disks' => ['local']]);
        $r = $api->handleSearch(new WP_REST_Request(['disk' => 'nope', 'q' => 'x']));
        assertEqual(403, $r->get_status(), 'disk refused');
        assertEqual('disk_denied', $r->get_data()['error_code'] ?? null, 'disk_denied');
    } finally {
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
    }
    // Guard: every 403 ApiException names a code (the UI localises by code; a bare one shows raw English).
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(preg_match_all('/new ApiException\((?:[^;]*?),\s*403\s*\)/s', $src, $m) === 0, 'codeless 403 left: ' . json_encode($m[0] ?? []));
});

test('assetVersionMatches(): only a v naming the served file (or its vendor tree) may be immutable', function () {
    $dir = sys_get_temp_dir() . '/ffav-' . bin2hex(random_bytes(3));
    mkdir($dir . '/vendor/codemirror', 0775, true);
    file_put_contents($dir . '/fm.js', 'js!');
    file_put_contents($dir . '/vendor/codemirror/x.js', 'X');
    file_put_contents($dir . '/vendor/codemirror/y.css', 'Y');
    $real = realpath($dir);
    try {
        assertTrue(FluxFilesApi::assetVersionMatches("$real/fm.js", $real, substr(md5('js!'), 0, 8)), '8-hex prefix of the file md5');
        assertTrue(FluxFilesApi::assetVersionMatches("$real/fm.js", $real, substr(md5('js!'), 0, 10)), '10-hex prefix');
        assertTrue(!FluxFilesApi::assetVersionMatches("$real/fm.js", $real, '0123456789'), 'unrelated hash');
        assertTrue(!FluxFilesApi::assetVersionMatches("$real/fm.js", $real, 'abc'), 'too short / junk');
        $tree = substr(md5("x.js\0X\0y.css\0Y\0"), 0, 10);
        assertTrue(FluxFilesApi::assetVersionMatches("$real/vendor/codemirror/x.js", $real, $tree), 'vendor tree hash');
        assertTrue(!FluxFilesApi::assetVersionMatches("$real/fm.js", $real, $tree), 'a tree hash only counts for vendor files');
    } finally {
        array_map('unlink', glob("$dir/vendor/codemirror/*")); @unlink("$dir/fm.js");
        @rmdir("$dir/vendor/codemirror"); @rmdir("$dir/vendor"); @rmdir($dir);
    }
});

test('assetCaching(): ?v= is immutable; otherwise a cheap ETag with a real 304', function () {
    $f = tempnam(sys_get_temp_dir(), 'ffa');
    file_put_contents($f, 'body{}');
    try {
        [$h, $nm] = FluxFilesApi::assetCaching($f, true, '');
        assertTrue(in_array('Cache-Control: public, max-age=31536000, immutable', $h, true), 'immutable when versioned');
        assertEqual(false, $nm, 'versioned is not a 304');
        [$h, $nm] = FluxFilesApi::assetCaching($f, false, '');
        assertEqual(false, $nm, 'no validator -> 200');
        assertTrue(in_array('Cache-Control: public, max-age=300, must-revalidate', $h, true), 'revalidating cache');
        $etagHeader = array_values(array_filter($h, fn ($x) => strpos($x, 'ETag: ') === 0))[0] ?? '';
        $etag = substr($etagHeader, 6);
        assertTrue($etag !== '', 'ETag sent');
        [, $nm] = FluxFilesApi::assetCaching($f, false, $etag);
        assertEqual(true, $nm, 'matching If-None-Match -> 304');
        [, $nm] = FluxFilesApi::assetCaching($f, false, 'W/' . $etag);
        assertEqual(true, $nm, 'weak validator matches');
        [, $nm] = FluxFilesApi::assetCaching($f, false, '"other"');
        assertEqual(false, $nm, 'different ETag -> 200');
    } finally {
        @unlink($f);
    }
});

// ── 1.0.0: shared core classes (MediaEndpoint, FileManagerFactory, RateLimiterFactory …) ──
if (!function_exists('status_header')) {
    function status_header($code) { $GLOBALS['WP_STATUS'][] = (int) $code; }
}

/** Call a private method of a FluxFilesApi. */
$ffPriv = function (FluxFilesApi $api, string $method, array $args = []) {
    $m = new ReflectionMethod($api, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($api, $args);
};
$ffProp = function (object $o, string $name) {
    for ($c = new ReflectionClass($o); $c; $c = $c->getParentClass() ?: null) {   // private props live on the declaring class
        if ($c->hasProperty($name) && $c->getProperty($name)->getDeclaringClass()->getName() === $c->getName()) {
            $p = $c->getProperty($name);
            $p->setAccessible(true);
            return $p->getValue($o);
        }
    }
    throw new RuntimeException("no property {$name}");
};
class FileManagerHolder { public function __construct(public FluxFilesApi $api, public \FluxFiles\FileManager $fm) {} }
$ffFileManager = function (array $claims) use ($ffAsUser, $ffPriv): FileManagerHolder {
    return $ffAsUser(9300, $claims, function () use ($ffPriv) {
        $api = new FluxFilesApi();
        $c = $ffPriv($api, 'claims');
        return new FileManagerHolder($api, $ffPriv($api, 'fileManager', [$c]));
    });
};

test('fileManager() wires core\'s hooks only on the claim, and virus/DLP stay fail-closed', function () use ($ffFileManager, $ffProp) {
    $plain = $ffFileManager([])->fm;
    foreach (['virusScanner', 'dlpScanner', 'uploadOptimizer', 'aiTagger', 'versionKeeper'] as $p) {
        assertTrue($ffProp($plain, $p) === null, "{$p} not wired for a plain token");
    }
    assertTrue($ffProp($plain, 'quotaManager') !== null, 'quota always wired');
    assertEqual((string) get_option('fluxfiles_secret'), $ffProp($plain, 'streamSecret'), 'stream secret always wired');
    assertEqual('http://wp.test/wp-json/fluxfiles/v1/api/fm', $ffProp($plain, 'apiBasePath'), 'WordPress-only API base path');

    $fm = $ffFileManager(['allow_virus_scan' => true, 'allow_dlp_scan' => true, 'auto_optimize' => true])->fm;
    assertTrue($ffProp($fm, 'virusScanner') !== null && $ffProp($fm, 'dlpScanner') !== null, 'scanners wired on the claim');
    assertTrue($ffProp($fm, 'uploadOptimizer') !== null, 'auto_optimize wires the optimizer');

    // Fail-closed: no installed+licensed module -> the callback THROWS (501/402/403) rather than returning "clean".
    foreach (['virusScanner' => ['/tmp/x'], 'dlpScanner' => ['/tmp/x', 'x.txt']] as $prop => $args) {
        $threw = false;
        try { ($ffProp($fm, $prop))(...$args); }
        catch (\FluxFiles\ApiException $e) { $threw = in_array($e->getHttpCode(), [501, 402, 403], true); }
        assertTrue($threw, "{$prop} refuses (module gate inside the callback) instead of passing the file");
    }
});

test('fileManager(): the AI tagger is wired only for ai_auto_tag AND a configured provider', function () use ($ffFileManager, $ffProp) {
    $keep = $GLOBALS['WP_OPTIONS']['fluxfiles_ai_provider'] ?? null;
    try {
        unset($GLOBALS['WP_OPTIONS']['fluxfiles_ai_provider']);
        assertTrue($ffProp($ffFileManager(['ai_auto_tag' => true])->fm, 'aiTagger') === null, 'claim without a provider: none');
        $GLOBALS['WP_OPTIONS']['fluxfiles_ai_provider'] = 'claude';
        $GLOBALS['WP_OPTIONS']['fluxfiles_ai_api_key'] = 'k';
        assertTrue($ffProp($ffFileManager([])->fm, 'aiTagger') === null, 'provider without the claim: none (stricter than core)');
        assertTrue($ffProp($ffFileManager(['ai_auto_tag' => true])->fm, 'aiTagger') !== null, 'claim + provider: wired');
    } finally {
        if ($keep === null) { unset($GLOBALS['WP_OPTIONS']['fluxfiles_ai_provider']); } else { $GLOBALS['WP_OPTIONS']['fluxfiles_ai_provider'] = $keep; }
    }
});

test('the media endpoint is core\'s MediaEndpoint with status_header() and X-Accel from the plugin\'s source', function () use ($ffPriv, $ffProp) {
    $api = new FluxFilesApi();
    $ep = $ffPriv($api, 'mediaEndpoint');
    assertTrue($ep instanceof FluxFilesMediaEndpoint && $ep instanceof \FluxFiles\MediaEndpoint, 'subclass of the core class');
    assertEqual('', $ffProp($ep, 'xaccelBase'), 'no X-Accel by default');
    putenv('FLUXFILES_XACCEL=/protected/');
    try {
        assertEqual('/protected/', $ffProp($ffPriv($api, 'mediaEndpoint'), 'xaccelBase'), 'FLUXFILES_XACCEL reaches the endpoint');
    } finally {
        putenv('FLUXFILES_XACCEL');
    }
    $GLOBALS['WP_STATUS'] = [];
    $m = new ReflectionMethod($ep, 'status');
    $m->setAccessible(true);
    $m->invoke($ep, 429);
    assertEqual([429], $GLOBALS['WP_STATUS'], 'status() goes through status_header()');
});

test('media rate limits default to core\'s (stream 300/min, img 120/min); env and the plugin filter override', function () use ($ffPriv, $ffProp) {
    $limit = $ffProp($ffPriv(new FluxFilesApi(), 'mediaEndpoint'), 'rateLimit');
    $burn = function (string $bucket, int $n) use ($limit): int {
        $sub = 'rl-' . $bucket . '-' . uniqid();
        $ok = 0;
        for ($i = 0; $i < $n; $i++) { if ($limit($sub, $bucket, $bucket === 'stream' ? 300 : 120)) { $ok++; } }
        return $ok;
    };
    assertEqual(300, $burn('stream', 305), 'stream: 300 allowed, then limited');
    assertEqual(120, $burn('img', 125), 'img: 120 allowed, then limited');

    $_ENV['FLUXFILES_IMG_RATE_LIMIT'] = '3';
    try {
        assertEqual(3, $burn('img', 6), 'FLUXFILES_IMG_RATE_LIMIT overrides the default');
        add_filter('fluxfiles_rate_limit_img', fn ($v) => 2);
        assertEqual(2, $burn('img', 6), 'the plugin filter wins over the env');
        $GLOBALS['WP_FILTERS'] = [];
        add_filter('fluxfiles_rate_limit_img', fn ($v) => 0);
        assertEqual(6, $burn('img', 6), '<= 0 disables');
    } finally {
        unset($_ENV['FLUXFILES_IMG_RATE_LIMIT']);
        $GLOBALS['WP_FILTERS'] = [];
    }
});

test('/img composites the preview watermark overlay through the proxy (and a preview-only token never gets the clean original)', function () use ($secret, $ffPut, $ffPriv) {
    $dir = 'wm-' . uniqid();
    $img = imagecreatetruecolor(400, 300);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
    ob_start(); imagepng($img); $png = (string) ob_get_clean();
    $ffPut($dir . '/a.png', $png);

    $ep = $ffPriv(new FluxFilesApi(), 'mediaEndpoint');
    $serve = function (string $token, array $q = [], string $accept = 'image/webp') use ($ep): string {
        // The test's own output already started, so header() warns — irrelevant to the bytes.
        set_error_handler(static fn ($no, $str) => str_contains($str, 'headers already sent'));
        ob_start();
        try { $ep->image(['token' => $token] + $q, ['accept' => $accept]); }
        finally { $out = (string) ob_get_clean(); restore_error_handler(); }
        return $out;
    };
    $sub = 'wm-user-' . uniqid();
    $plain = $serve(\FluxFiles\ImageToken::mint('local', $dir . '/a.png', $sub, 60, $secret, 2000, 80), ['format' => 'webp']);
    $marked = $serve(\FluxFiles\ImageToken::mint('local', $dir . '/a.png', $sub, 60, $secret, 2000, 80,
        ['enabled' => true, 'type' => 'text', 'text' => 'CONFIDENTIAL', 'position' => 'center', 'opacity' => 0.8, 'font_size' => 40], false), ['format' => 'webp']);
    assertTrue(strncmp($plain, 'RIFF', 4) === 0 && strncmp($marked, 'RIFF', 4) === 0, 'both are WebP (no 415)');
    assertTrue($plain !== $marked, 'the overlay changed the pixels');

    // Accept: */* would be served the clean original by an unprotected endpoint; a watermarked token is forced to WebP.
    $wild = $serve(\FluxFiles\ImageToken::mint('local', $dir . '/a.png', $sub, 60, $secret, 2000, 80,
        ['enabled' => true, 'type' => 'text', 'text' => 'CONFIDENTIAL'], false), [], '*/*');
    assertTrue(strncmp($wild, 'RIFF', 4) === 0 && $wild !== $png, 'wildcard Accept never returns the clean original to a watermarked token');
    $dlOff = $serve(\FluxFiles\ImageToken::mint('local', $dir . '/a.png', $sub, 60, $secret, 2000, 80, null, false), [], '*/*');
    assertTrue(strncmp($dlOff, 'RIFF', 4) === 0, 'a no-download token is forced to WebP too');
});

test('a watermark_enabled plugin token arrives as a watermark config and forces preview-only', function () use ($ffAsUser) {
    $ffAsUser(9301, ['watermark_enabled' => true, 'watermark_text' => 'DRAFT'], function () {
        $c = \FluxFiles\JwtMiddleware::handle(substr($_SERVER['HTTP_AUTHORIZATION'], 7), (string) get_option('fluxfiles_secret'));
        assertTrue(is_array($c->watermark) && ($c->watermark['text'] ?? '') === 'DRAFT', 'watermark config arrives in Claims');
        assertTrue($c->allowDownload === false, 'core forces a watermarked token preview-only');
    });
});

test('db mode: the rate limiter lives in the database (START TRANSACTION … FOR UPDATE … COMMIT), json mode uses the file limiter', function () use ($ffPriv) {
    global $wpdb;
    $keepDb = $wpdb ?? null;
    $api = new FluxFilesApi();
    $json = $ffPriv($api, 'limiter', [5, 5]);
    assertTrue($json instanceof \FluxFiles\RateLimiterFileStorage, 'json mode: core\'s file limiter via RateLimiterFactory');

    $GLOBALS['WP_OPTIONS']['fluxfiles_storage_backend'] = 'db';
    $GLOBALS['WP_OPTIONS']['fluxfiles_db_version'] = FluxFilesDbSchema::VERSION;
    try {
        $wpdb = new WpdbSpy();
        $lim = $ffPriv($api, 'limiter', [5, 2]);
        assertTrue($lim instanceof WpRateLimiterStorage, 'db mode: the DB-backed limiter');
        $wpdb->varQueue[] = 0;
        $lim->check('u1', 'write');
        $q = $wpdb->queries;
        assertEqual('START TRANSACTION', $q[0] ?? null, 'transaction opened');
        assertTrue(str_contains($q[1] ?? '', 'DELETE FROM wp_fluxfiles_rate_limits'), 'expired hits swept');
        assertTrue(str_contains($q[2] ?? '', 'FOR UPDATE'), 'count taken under lock');
        assertTrue(str_contains($q[3] ?? '', 'INSERT INTO wp_fluxfiles_rate_limits'), 'hit recorded');
        assertEqual('COMMIT', $q[4] ?? null, 'committed');

        $wpdb->varQueue[] = 2;   // already at the write limit (2)
        try { $lim->check('u1', 'write'); $threw = null; }
        catch (\FluxFiles\ApiException $e) { $threw = [$e->getHttpCode(), $e->getErrorCode()]; }
        assertEqual([429, 'rate_limited'], $threw, 'over the limit -> 429 rate_limited');
        $wpdb->varQueue[] = 4;   // reads have their own, higher limit (5)
        $lim->check('u1', 'read');
    } finally {
        $wpdb = $keepDb;
        unset($GLOBALS['WP_OPTIONS']['fluxfiles_storage_backend'], $GLOBALS['WP_OPTIONS']['fluxfiles_db_version']);
    }
});

test('db mode: a missing/unusable rate_limits table degrades to the file limiter (throttled log) instead of a 500 on every call', function () use ($ffPriv) {
    global $wpdb;
    $keepDb = $wpdb ?? null;
    $heal = new \ReflectionProperty(WpRateLimiterStorage::class, 'healTried');
    $heal->setValue(null, true); // skip the dbDelta self-heal (not available in the stub runtime)
    $broken = new class extends WpdbSpy {
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            return str_starts_with($sql, 'DELETE FROM wp_fluxfiles_rate_limits') ? false : 1;
        }
    };
    $dir = sys_get_temp_dir() . '/ff-wp-rl-' . uniqid();
    @mkdir($dir, 0777, true);
    $errLog = ini_set('error_log', $dir . '/err.log');
    try {
        $wpdb = $broken;
        $lim = new WpRateLimiterStorage(5, 1, 60, \FluxFiles\RateLimiterFactory::makeFile($dir, 5, 1, 60));
        $lim->check('u-fb', 'write');                      // served by the file limiter, no exception
        try { $lim->check('u-fb', 'write'); $threw = null; }
        catch (\FluxFiles\ApiException $e) { $threw = $e->getHttpCode(); }
        assertEqual(429, $threw, 'still rate limited through the fallback');
        $log = (string) @file_get_contents($dir . '/err.log');
        assertEqual(1, substr_count($log, 'DB rate limiter unavailable'), 'logged once, not per request');
        $strict = new WpRateLimiterStorage(5, 1, 60);       // no fallback: fail closed as before
        try { $strict->check('u-fb', 'write'); $code = null; }
        catch (\FluxFiles\ApiException $e) { $code = $e->getHttpCode(); }
        assertEqual(500, $code, 'without a fallback it still fails closed');
    } finally {
        $wpdb = $keepDb;
        ini_set('error_log', (string) $errLog);
        $heal->setValue(null, false);
    }
});

test('the schema creates the rate_limits table', function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesDbSchema.php');
    assertTrue(strpos($src, 'fluxfiles_rate_limits') !== false && strpos($src, 'KEY identifier_bucket_ts (identifier,bucket,ts)') !== false, 'table + index');
    assertEqual('1.5.0', FluxFilesDbSchema::VERSION, 'schema version bumped so existing installs dbDelta it');
});

test('WpDbMetadataHandler uses core\'s MetadataRowLogic trait and keeps none of its duplicated methods', function () {
    assertTrue(in_array(\FluxFiles\Db\MetadataRowLogic::class, class_uses(WpDbMetadataHandler::class), true), 'trait used');
    $own = [];
    foreach ((new ReflectionClass(WpDbMetadataHandler::class))->getMethods() as $m) {
        if ($m->getFileName() === (new ReflectionClass(WpDbMetadataHandler::class))->getFileName()) { $own[] = $m->getName(); }
    }
    foreach (['pathHash', 'isReservedPath', 'isHiddenPath', 'highlight', 'rowToTrashEntry', 'rowToHoldEntry', 'matchOverlappingHold',
              'findOverlappingHold', 'holdCovering', 'holdBlocking', 'holdsCoveringMany', 'releaseHold', 'get', 'trackParents',
              'countChildren', 'syncToS3Tags', 'readAuditArchive'] as $dup) {
        assertTrue(!in_array($dup, $own, true), "{$dup}() comes from the trait, not a local copy");
    }
});

test('readAuditWindow(): SQL narrowed by actor/since/limit through the trait, rows mapped and re-ordered', function () {
    $spy = new WpdbSpy();
    $spy->resultsQueue[] = [   // newest first, as ORDER BY id DESC returns them
        ['owner' => 'u', 'action' => 'b', 'file_key' => 'k2', 'ip' => null, 'user_agent' => null, 'detail' => null, 'created_at' => '200', 'prev_hash' => null, 'entry_hash' => null],
        ['owner' => 'u', 'action' => 'a', 'file_key' => 'k1', 'ip' => null, 'user_agent' => null, 'detail' => null, 'created_at' => '150', 'prev_hash' => null, 'entry_hash' => null],
    ];
    $h = makeWpDbHandler($spy);
    $rows = $h->readAuditWindow('local', 100, 5, 'u');
    $sql = $spy->queries[0];
    assertTrue(str_contains($sql, "disk = 'local'") && str_contains($sql, "owner = 'u'") && str_contains($sql, 'created_at >= 100')
        && str_contains($sql, 'ORDER BY id DESC LIMIT 5'), 'SQL: ' . $sql);
    assertEqual(['a', 'b'], array_column($rows, 'action'), 'limit query is flipped back to chronological order');
    assertEqual(150, $rows[0]['created_at'], 'created_at cast to int');

    $spy2 = new WpdbSpy();
    makeWpDbHandler($spy2)->readAudit('local');
    assertTrue(str_contains($spy2->queries[0], 'ORDER BY id ASC') && !str_contains($spy2->queries[0], 'LIMIT'), 'readAudit() = the whole window, oldest first');
});

test('refused writes / 403s are audited through core\'s AuditPolicy and the plugin keeps no copy', function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($src, 'AuditPolicy::recordRefusedWrite(') !== false && strpos($src, 'AuditPolicy::recordDenied(') !== false, 'delegates');
    assertTrue(strpos($src, 'function auditDenied(') === false && strpos($src, 'function auditRefusedWrite(') === false, 'hand-written duplicates deleted');
    assertTrue(strpos($src, 'AuditPolicy::successEntry(') !== false, 'success entries come from AuditPolicy too');
});

test('multisite: each blog gets its own storage root + url; blog 1 cannot address blog 2', function () {
    // Single site: layout unchanged.
    $single = FluxFilesPlugin::diskConfigs()['local'];
    assertEqual(WP_CONTENT_DIR . '/uploads/fluxfiles/uploads', $single['root'], 'single-site root');
    assertEqual('http://wp.test/wp-content/uploads/fluxfiles/uploads', $single['url'], 'single-site url');
    assertEqual(WP_CONTENT_DIR . '/uploads/fluxfiles', FluxFilesPlugin::storagePath(), 'single-site storage path');

    $GLOBALS['WP_MULTISITE'] = true;
    try {
        $cfg = [];
        foreach ([1, 2] as $b) {
            $GLOBALS['WP_BLOG_ID'] = $b;
            $cfg[$b] = FluxFilesPlugin::diskConfigs()['local'];
            assertEqual(WP_CONTENT_DIR . "/uploads/fluxfiles/sites/{$b}", FluxFilesPlugin::storagePath(), "blog {$b} storage path");
            assertEqual(WP_CONTENT_DIR . "/uploads/fluxfiles/sites/{$b}/uploads", $cfg[$b]['root'], "blog {$b} root");
            assertEqual("http://wp.test/wp-content/uploads/fluxfiles/sites/{$b}/uploads", $cfg[$b]['url'], "blog {$b} url");
        }
        assertTrue($cfg[1]['root'] !== $cfg[2]['root'], 'roots differ');
        assertTrue(strpos($cfg[2]['root'] . '/', $cfg[1]['root'] . '/') !== 0, 'blog 2 root is not inside blog 1 root');
        assertTrue(strpos($cfg[1]['root'] . '/', $cfg[2]['root'] . '/') !== 0, 'blog 1 root is not inside blog 2 root');

        // Address blog 2's file through blog 1's disk: not reachable.
        $tmp = sys_get_temp_dir() . '/ffms-' . uniqid();
        $c1 = $cfg[1];
        $c1['root'] = $tmp . '/sites/1/uploads';
        mkdir($c1['root'], 0777, true);
        mkdir($tmp . '/sites/2/uploads', 0777, true);
        file_put_contents($tmp . '/sites/2/uploads/secret.txt', 'blog2');
        $d1 = (new \FluxFiles\DiskManager(['local' => $c1]))->disk('local');
        assertTrue(!$d1->fileExists('secret.txt'), 'blog 1 cannot see blog 2 file');
        $reachable = false;
        try { $reachable = $d1->fileExists('../../2/uploads/secret.txt'); } catch (\Throwable $e) { $reachable = false; }
        assertTrue(!$reachable, 'traversal into blog 2 is refused');
    } finally {
        $GLOBALS['WP_MULTISITE'] = false;
        $GLOBALS['WP_BLOG_ID'] = 1;
    }
});

test('multisite public links: a link resolves to the blog that minted it', function () {
    // Single site: no blog id in the URL, REST base is this site's, old links keep working.
    $u = FluxFilesPlugin::publicLinkUrl('share.html', 'tok');
    assertEqual('http://wp.test/wp-json/fluxfiles/v1/api/fm/share/page?token=tok', $u, 'single-site link is the REST page route');
    assertTrue(strpos($u, 'blog=') === false, 'single-site link carries no blog id');
    assertEqual('http://wp.test/wp-json/fluxfiles/v1/api/fm', FluxFilesPlugin::publicLinkRestBase(null), 'single-site base');
    assertEqual('http://wp.test/wp-json/fluxfiles/v1/api/fm', FluxFilesPlugin::publicLinkRestBase('2'), 'single-site ignores a blog param');

    $GLOBALS['WP_MULTISITE'] = true;
    try {
        $GLOBALS['WP_BLOG_ID'] = 2;
        foreach (['share.html' => 'share', 'intake.html' => 'intake'] as $page => $w) {
            $link = FluxFilesPlugin::publicLinkUrl($page, 'tok');
            // The REST URL is per blog, so the link needs no blog hint: it lands on blog
            // 2's own namespace, where blog 2's secret and storagePath() (sites/2) apply.
            assertEqual("http://wp.test/two/wp-json/fluxfiles/v1/api/fm/{$w}/page?token=tok", $link, "{$w} link minted on blog 2 is blog 2's REST page");
        }
        // The base-URL claim is the same link without a token.
        $base = FluxFilesPlugin::publicLinkUrl('share.html');
        assertEqual('http://wp.test/two/wp-json/fluxfiles/v1/api/fm/share/page', $base, 'base claim: blog 2 page, no token');

        // Request arrives at the main site (blog 1) for a blog-2 link: still blog 2.
        $GLOBALS['WP_BLOG_ID'] = 1;
        assertEqual('http://wp.test/two/wp-json/fluxfiles/v1/api/fm', FluxFilesPlugin::publicLinkRestBase('2'), 'resolved from the id, not the booted blog');
        // No blog param (a pre-1.0.4 link): the booted blog, as before.
        assertEqual('http://wp.test/wp-json/fluxfiles/v1/api/fm', FluxFilesPlugin::publicLinkRestBase(null), 'missing id = current blog');

        // Tampered / forged ids fail closed.
        foreach (['99', '0', '-1', '2 ', '2.0', '02', '2;3', 'abc', '1e1', '99999999999', '3' /* archived */] as $bad) {
            assertTrue(FluxFilesPlugin::publicLinkRestBase($bad) === null, "refused blog id '{$bad}'");
        }
        assertTrue(FluxFilesPlugin::publicLinkRestBase(['2']) === null, 'array id refused');
        // A host in the URL is never consulted: the base is derived from the site record.
        $_GET = ['blog' => '2', 'host' => 'evil.test'];
        assertTrue(strpos((string) FluxFilesPlugin::publicLinkRestBase('2'), 'evil.test') === false, 'host param has no effect');
        // Forging blog 1 on a blog-2 link only points the page at blog 1, whose own
        // secret rejects the signature; storage stays per site.
        $GLOBALS['WP_BLOG_ID'] = 2;
        $s2 = FluxFilesPlugin::storagePath();
        $GLOBALS['WP_BLOG_ID'] = 1;
        assertTrue($s2 !== FluxFilesPlugin::storagePath(), 'storage roots stay per-site');
    } finally {
        $_GET = [];
        $GLOBALS['WP_MULTISITE'] = false;
        $GLOBALS['WP_BLOG_ID'] = 1;
    }
});

test('public links: plain permalinks use ?rest_route= and the token is appended with &', function () {
    // rest_url() already carries a query string on plain permalinks; a naive "?token="
    // would corrupt the URL, so the token must be added with add_query_arg semantics.
    $GLOBALS['WP_PLAIN_PERMALINKS'] = true;
    try {
        $u = FluxFilesPlugin::publicLinkUrl('intake.html', 'tok');
        assertEqual('http://wp.test/?rest_route=/fluxfiles/v1/api/fm/intake/page&token=tok', $u, 'plain-permalink link');
        assertEqual('http://wp.test/?rest_route=/fluxfiles/v1/api/fm/share/page', FluxFilesPlugin::publicLinkUrl('share.html'), 'plain-permalink base has no token');
        assertEqual(1, substr_count($u, '?'), 'exactly one query string');
    } finally {
        unset($GLOBALS['WP_PLAIN_PERMALINKS']);
    }
});

test('public page redirect: a validated other blog -> 302 to its own REST page, bad blogs -> 404', function () {
    assertEqual([200, null], FluxFilesPlugin::publicPageRedirect('share', '2', 'tok'), 'single site ignores the hint');
    $GLOBALS['WP_MULTISITE'] = true;
    try {
        $GLOBALS['WP_BLOG_ID'] = 1;
        assertEqual([200, null], FluxFilesPlugin::publicPageRedirect('share', null, 'tok'), 'no hint serves here');
        assertEqual([200, null], FluxFilesPlugin::publicPageRedirect('share', '', 'tok'), 'empty hint serves here');
        assertEqual([200, null], FluxFilesPlugin::publicPageRedirect('share', '1', 'tok'), 'own blog serves here (no loop)');
        assertEqual([302, 'http://wp.test/two/wp-json/fluxfiles/v1/api/fm/share/page?token=a%2Bb'],
            FluxFilesPlugin::publicPageRedirect('share', '2', 'a+b'), 'other blog -> its REST page, token re-encoded');
        assertEqual([302, 'http://wp.test/two/wp-json/fluxfiles/v1/api/fm/intake/page'],
            FluxFilesPlugin::publicPageRedirect('intake', '2', ''), 'no token, intake');
        foreach (['99', '0', '-1', 'abc', '3' /* archived */, '2;3'] as $bad) {
            assertEqual([404, null], FluxFilesPlugin::publicPageRedirect('share', $bad, 'tok'), "bad blog '{$bad}' -> 404");
        }
        assertEqual([404, null], FluxFilesPlugin::publicPageRedirect('share', ['2'], 'tok'), 'array hint -> 404');
    } finally {
        $GLOBALS['WP_MULTISITE'] = false;
        $GLOBALS['WP_BLOG_ID'] = 1;
    }
});

test('licence seam: core resolves the plugin licence, NoLicense unless the filter supplies one', function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesPlugin.php');
    assertTrue(strpos($src, "ModuleRegistry::setLicenseResolver([self::class, 'license'])") !== false, 'resolver installed in the constructor');

    \FluxFiles\ModuleRegistry::setLicenseResolver([FluxFilesPlugin::class, 'license']);
    $GLOBALS['WP_FILTERS']['fluxfiles_license'] = [];
    try {
        $lic = \FluxFiles\ModuleRegistry::license();
        assertTrue($lic instanceof \FluxFiles\NoLicense, 'no add-on -> NoLicense, even with a stray key option or env');
        assertTrue(!$lic->licensed('share'), 'NoLicense entitles nothing');
        assertTrue(FluxFilesPlugin::license()->info() === (new \FluxFiles\NoLicense())->info(), 'info() is the NoLicense report');

        // The filter wins, but only for a real LicenseCheckInterface.
        $fake = new class implements \FluxFiles\LicenseCheckInterface {
            public function licensed(string $module): bool { return true; }
            public function status(): string { return 'active'; }
            public function info(): array { return ['status' => 'active', 'edition' => 'pro']; }
        };
        add_filter('fluxfiles_license', fn ($v) => $fake);
        assertTrue(FluxFilesPlugin::license() === $fake, 'filter result is used');
        assertTrue(\FluxFiles\ModuleRegistry::license() === $fake, 'and core sees it through the resolver');
        $GLOBALS['WP_FILTERS']['fluxfiles_license'] = [fn ($v) => 'nope'];
        assertTrue(FluxFilesPlugin::license() instanceof \FluxFiles\NoLicense, 'a non-checker filter value is ignored');
        $GLOBALS['WP_FILTERS']['fluxfiles_license'] = [function ($v) { throw new \RuntimeException('boom'); }];
        assertTrue(FluxFilesPlugin::license() instanceof \FluxFiles\NoLicense, 'a throwing filter fails closed');
    } finally {
        $GLOBALS['WP_FILTERS']['fluxfiles_license'] = [];
        \FluxFiles\ModuleRegistry::setLicenseResolver(null);
    }
});

test('first request on a site that never ran activation: uploads/ is created before protectStorage()', function () {
    // The API constructor must create <storagePath>/uploads itself: protectStorage()
    // returns early without it, so the deny files would only appear after the first write.
    $src = file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    $mk = strpos($src, "wp_mkdir_p(\$storagePath . '/uploads')");
    $pr = strpos($src, 'FluxFilesPlugin::protectStorage()');
    assertTrue($mk !== false && $pr !== false && $mk < $pr, 'uploads/ is created before protectStorage()');

    // And with uploads/ present, a fresh sub-site root gets its deny files at once.
    $GLOBALS['WP_MULTISITE'] = true;
    $GLOBALS['WP_BLOG_ID'] = 7;
    try {
        $root = FluxFilesPlugin::storagePath();
        assertTrue(!is_dir($root . '/uploads'), 'fresh site: nothing yet');
        wp_mkdir_p($root . '/uploads');
        FluxFilesPlugin::protectStorage();
        assertTrue(is_file($root . '/.htaccess') && is_file($root . '/uploads/.htaccess'), 'deny .htaccess written');
        assertTrue(is_file($root . '/uploads/index.php'), 'index.php written');
    } finally {
        $GLOBALS['WP_MULTISITE'] = false;
        $GLOBALS['WP_BLOG_ID'] = 1;
    }
});

test('the plugin build denies direct web access to the bundled public/ pages', function () {
    $build = file_get_contents(__DIR__ . '/../../../scripts/build-wordpress.sh');
    // No hidden .htaccess in the build (WordPress.org rejects hidden files); the directory
    // request is answered by public/index.php, which keeps its 403 for a direct hit.
    assertTrue(strpos($build, 'public/.htaccess') === false, 'no .htaccess is written into public/');
    assertTrue(strpos($build, '"$PLUGIN_DIR/public/index.php"') !== false && strpos($build, 'http_response_code(403)') !== false, '403 index.php');
    assertTrue(strpos($build, "defined('ABSPATH')") !== false, 'index.php has the ABSPATH guard');
    assertTrue(strpos($build, 'templates/ui-index.php') !== false && strpos($build, 'rm -f "$PLUGIN_DIR"/public/*.html') !== false,
        'the build turns the UI page into a guarded PHP template and removes public/*.html');
    // A direct HTTP hit on the template (ABSPATH undefined) must print nothing; the guard line
    // is exactly what serveUiHtml() strips before serving the page.
    $guard = (new ReflectionClassConstant(FluxFilesApi::class, 'UI_TEMPLATE_GUARD'))->getValue();
    $tpl = tempnam(sys_get_temp_dir(), 'ffui') . '.php';
    file_put_contents($tpl, $guard . "<html><body>SECRET-PAGE</body></html>");
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tpl) . ' 2>&1');
    @unlink($tpl);
    assertEqual('', $out, 'the template exits without output when hit directly');
    // The REST page still renders (source tree: falls back to core/public/index.html).
    if (!defined('FLUXFILES_PLUGIN_DIR')) { define('FLUXFILES_PLUGIN_DIR', realpath(__DIR__ . '/..') . '/'); }
    $html = (new ReflectionMethod(FluxFilesApi::class, 'uiPageHtml'))->invoke(null);
    assertTrue(is_string($html) && stripos($html, '<html') !== false && strpos($html, 'ABSPATH') === false, 'uiPageHtml() returns the page without the guard');
    // serveUiHtml() still reads the page from disk.
    $api = file_get_contents(__DIR__ . '/../includes/FluxFilesApi.php');
    assertTrue(strpos($api, "corePath('public')") !== false, 'serveUiHtml() still reads public/ from disk');
});

test('Site Health adds a FluxFiles section, escaped, with sensitive rows private and no secrets', function () use ($secret) {
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket'] = 'leaky-bucket-name';
    $GLOBALS['WP_OPTIONS']['fluxfiles_s3_secret'] = 'LEAKY-S3-SECRET';
    $info = FluxFilesPlugin::siteHealthInfo(['wp-core' => ['label' => 'WordPress', 'fields' => []]]);
    assertTrue(isset($info['wp-core'], $info['fluxfiles']), 'existing sections kept, ours added');
    assertEqual('FluxFiles', $info['fluxfiles']['label']);
    $fields = $info['fluxfiles']['fields'];
    assertTrue(isset($fields['plugin_version']), 'plugin version row');
    foreach ($fields as $f) {
        assertTrue(isset($f['label'], $f['value']) && is_bool($f['private']), 'field shape');
        assertTrue(strpos($f['value'], '<') === false, 'values are escaped');
    }
    if (class_exists(\FluxFiles\AboutInfo::class)) {
        $private = array_filter($fields, static fn ($f) => $f['private']);
        $labels = array_column($private, 'label');
        assertTrue(in_array('Disks', $labels, true) && in_array('SSRF allow-hosts', $labels, true), 'disks + SSRF rows are private');
    }
    $blob = json_encode($info);
    foreach ([$secret, 'leaky-bucket-name', 'LEAKY-S3-SECRET'] as $needle) {
        assertTrue(strpos($blob, $needle) === false, "leaked {$needle}");
    }
    unset($GLOBALS['WP_OPTIONS']['fluxfiles_s3_bucket'], $GLOBALS['WP_OPTIONS']['fluxfiles_s3_secret']);
});

test('the plugin hooks Site Health debug_information', function () {
    $src = file_get_contents(__DIR__ . '/../includes/FluxFilesPlugin.php');
    assertTrue(strpos($src, "add_filter('debug_information', [self::class, 'siteHealthInfo'])") !== false, 'filter registered');
});

// ── Extension API (FLUXFILES_EXT_API = 1, packages/wordpress/HOOKS.md) ───────────

test('extension API: level constant, documented hooks and boot order', function () {
    $main = (string) file_get_contents(__DIR__ . '/../fluxfiles.php');
    assertTrue(preg_match("/define\('FLUXFILES_EXT_API', 1\);/", $main) === 1, 'FLUXFILES_EXT_API = 1 defined by fluxfiles.php');
    $hooks = (string) file_get_contents(__DIR__ . '/../HOOKS.md');
    foreach (['FLUXFILES_EXT_API', 'fluxfiles_loaded', 'fluxfiles_module_paths', 'fluxfiles_license', 'fluxfiles_api_boot',
              'fluxfiles_admin_sections', 'fluxfiles_edition_presets', 'fluxfiles_rest_routes'] as $h) {
        assertTrue(strpos($hooks, $h) !== false, "HOOKS.md documents {$h}");
    }
    $plug = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesPlugin.php');
    assertTrue(strpos($plug, "add_action('plugins_loaded', [self::class, 'loaded'], 1)") !== false, 'booted at plugins_loaded priority 1');

    // Order: fluxfiles_module_paths (filter) -> autoloader registered -> fluxfiles_loaded (action).
    $dir = sys_get_temp_dir() . '/ffext-order-' . uniqid();
    mkdir($dir . '/ocr/src', 0777, true);
    $dir = (string) realpath($dir);   // module paths are canonicalised (macOS /var -> /private/var)
    $log = [];
    $ffInstance = new ReflectionProperty(FluxFilesPlugin::class, 'instance');
    $ffKeep = $ffInstance->getValue();
    $ffInstance->setValue(null, (new ReflectionClass(FluxFilesPlugin::class))->newInstanceWithoutConstructor());
    $GLOBALS['ffext_dir'] = $dir;
    $GLOBALS['WP_FILTERS']['fluxfiles_module_paths'] = [function ($paths) use (&$log, $dir) { $log[] = 'paths'; $paths[] = $dir; $paths[] = '/no/such/dir'; $paths[] = 42; return $paths; }];
    $GLOBALS['WP_ACTIONS']['fluxfiles_loaded'] = [function ($plugin) use (&$log) {
        $log[] = 'loaded:' . get_class($plugin);
        $log[] = in_array($GLOBALS['ffext_dir'] . '/', \FluxFiles\ModuleAutoloader::bases(), true) ? 'registered' : 'NOT-registered';
    }];
    try {
        FluxFilesPlugin::loaded();
        assertEqual(['paths', 'loaded:FluxFilesPlugin', 'registered'], $log, 'filter first, autoloader registered, then the action with the plugin instance');
        $bases = \FluxFiles\ModuleAutoloader::bases();
        assertTrue(in_array($dir . '/', $bases, true), 'a valid path is registered with the module autoloader');
        assertTrue(!in_array('/no/such/dir/', $bases, true), 'a missing directory is dropped');
        // Relative paths are refused; real paths are canonicalised.
        $rel = 'tests/../' . basename($dir);
        $GLOBALS['WP_FILTERS']['fluxfiles_module_paths'] = [fn () => [$rel, 'relative/dir', $dir . '/../' . basename($dir)]];
        \FluxFiles\ModuleAutoloader::reset();
        FluxFilesPlugin::loaded();
        $b = \FluxFiles\ModuleAutoloader::bases();
        assertEqual([rtrim((string) realpath($dir), '/') . '/'], $b, 'only the absolute path is registered, canonicalised');
        // No add-on: nothing registered, the action still fires.
        $GLOBALS['WP_FILTERS']['fluxfiles_module_paths'] = [];
        \FluxFiles\ModuleAutoloader::reset();
        $log = [];
        FluxFilesPlugin::loaded();
        assertEqual([], \FluxFiles\ModuleAutoloader::bases(), 'no add-on -> no module bases');
    } finally {
        $ffInstance->setValue(null, $ffKeep);
        $GLOBALS['WP_FILTERS']['fluxfiles_module_paths'] = [];
        $GLOBALS['WP_ACTIONS']['fluxfiles_loaded'] = [];
        \FluxFiles\ModuleAutoloader::reset();
        @rmdir($dir . '/ocr/src'); @rmdir($dir . '/ocr'); @rmdir($dir);
    }
});

test('extension API: fluxfiles_api_boot and fluxfiles_rest_routes fire with the documented arguments', function () {
    $seen = [];
    $GLOBALS['WP_ACTIONS']['fluxfiles_api_boot'] = [function ($api) use (&$seen) { $seen['boot'] = get_class($api); }];
    $GLOBALS['WP_ACTIONS']['fluxfiles_rest_routes'] = [function ($ns, $api) use (&$seen) { $seen['routes'] = [$ns, get_class($api)]; }];
    try {
        new FluxFilesApi();
        assertEqual('FluxFilesApi', $seen['boot'] ?? null, 'api_boot receives the API instance');
        if (!function_exists('register_rest_route')) { function register_rest_route(...$a) { return true; } }
        if (!function_exists('add_filter')) { function add_filter(...$a) { return true; } }
        FluxFilesApi::registerRoutes();
        assertEqual(['fluxfiles/v1', 'FluxFilesApi'], $seen['routes'] ?? null, 'rest_routes receives (namespace, api)');
    } finally {
        $GLOBALS['WP_ACTIONS']['fluxfiles_api_boot'] = [];
        $GLOBALS['WP_ACTIONS']['fluxfiles_rest_routes'] = [];
    }
    // The admin sections hook is the LAST thing registerSettings() does.
    $admin = (string) file_get_contents(__DIR__ . '/../includes/FluxFilesAdmin.php');
    assertTrue(strpos($admin, "do_action('fluxfiles_admin_sections', 'fluxfiles', 'fluxfiles');") !== false, 'admin sections hook present');
    assertTrue(strpos($admin, 'fluxfiles_license_key') === false && strpos($admin, 'MODULE_ENV') === false, 'no licence / engine settings in the free admin');
});

test('extension API: with no add-on a paid route answers 501 module_not_installed', function () {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    \FluxFiles\ModuleRegistry::setLicenseResolver([FluxFilesPlugin::class, 'license']);
    \FluxFiles\ModuleRegistry::register('ocr', 'FluxFiles\\Ocr\\DefinitelyMissingModule');
    try {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(8801, ['claims' => ['allow_ocr' => true]]);
        $r = (new FluxFilesApi())->handleOcr(new WP_REST_Request(['disk' => 'local', 'path' => 'a.png']));
        assertEqual(501, $r->get_status(), 'paid route without the module -> 501');
        assertEqual('module_not_installed', $r->get_data()['error_code'] ?? null, 'module_not_installed');
    } finally {
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
        \FluxFiles\ModuleRegistry::reset();
    }
});

test('extension API: a fake module via fluxfiles_module_paths + a fake licence via fluxfiles_license -> the paid route works', function () {
    $keepAuth = $_SERVER['HTTP_AUTHORIZATION'];
    $dir = sys_get_temp_dir() . '/ffext-mod-' . uniqid();
    mkdir($dir . '/ocr/src', 0777, true);
    file_put_contents($dir . '/ocr/src/OcrModule.php', '<?php
namespace FluxFiles\Ocr;
final class OcrModule implements \FluxFiles\ModuleInterface {
    public static function id(): string { return "ocr"; }
    public static function claim(): string { return "allow_ocr"; }
    public function run($fm, $dm, $claims, array $body): array { return ["text" => "hello from the add-on"]; }
}');
    $fake = new class implements \FluxFiles\LicenseCheckInterface {
        public function licensed(string $module): bool { return $module === 'ocr'; }
        public function status(): string { return 'active'; }
        public function info(): array { return ['status' => 'active']; }
    };
    \FluxFiles\ModuleRegistry::setLicenseResolver([FluxFilesPlugin::class, 'license']);
    $GLOBALS['WP_FILTERS']['fluxfiles_module_paths'] = [fn ($p) => array_merge($p, [$dir])];
    $ffInstance = new ReflectionProperty(FluxFilesPlugin::class, 'instance');
    $ffKeep = $ffInstance->getValue();
    try {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . FluxFilesPlugin::generateToken(8802, ['claims' => ['allow_ocr' => true]]);
        // Module present, but no licence yet -> 402 (the licence gate is the add-on's to open).
        $ffInstance->setValue(null, (new ReflectionClass(FluxFilesPlugin::class))->newInstanceWithoutConstructor());
        FluxFilesPlugin::loaded();
        assertTrue(\FluxFiles\ModuleRegistry::installed('ocr'), 'the module is autoloaded from the add-on path');
        $r = (new FluxFilesApi())->handleOcr(new WP_REST_Request(['disk' => 'local', 'path' => 'a.png']));
        assertEqual(402, $r->get_status(), 'installed but unlicensed -> 402');
        $GLOBALS['WP_FILTERS']['fluxfiles_license'] = [fn ($v) => $fake];
        $r = (new FluxFilesApi())->handleOcr(new WP_REST_Request(['disk' => 'local', 'path' => 'a.png']));
        assertEqual(200, $r->get_status(), 'module + licence from the add-on -> 200: ' . json_encode($r->get_data()));
        assertEqual('hello from the add-on', $r->get_data()['data']['text'] ?? null, 'the add-on module ran');
    } finally {
        $ffInstance->setValue(null, $ffKeep);
        $_SERVER['HTTP_AUTHORIZATION'] = $keepAuth;
        $GLOBALS['WP_FILTERS']['fluxfiles_module_paths'] = [];
        $GLOBALS['WP_FILTERS']['fluxfiles_license'] = [];
        \FluxFiles\ModuleAutoloader::reset();
        \FluxFiles\ModuleRegistry::reset();
        @unlink($dir . '/ocr/src/OcrModule.php'); @rmdir($dir . '/ocr/src'); @rmdir($dir . '/ocr'); @rmdir($dir);
    }
});

// Keep LAST: this defines FLUXFILES_CORE_VERSION process-wide (a constant cannot be
// undefined), and the 'undefined -> null' assertion needs no earlier test to have set it.
test('bundled core version: the stamped constant feeds Site Health, and the build script stamps it', function () {
    assertEqual(null, FluxFilesPlugin::bundledCoreVersion(), 'undefined -> null');
    define('FLUXFILES_CORE_VERSION', '1.1.0');
    assertEqual('1.1.0', FluxFilesPlugin::bundledCoreVersion(), 'stamped value');
    $fields = FluxFilesPlugin::siteHealthInfo([])['fluxfiles']['fields'];
    assertEqual('1.1.0', $fields['bundled_core']['value'] ?? null, 'Site Health shows the bundled core');
    if (class_exists(\FluxFiles\AboutInfo::class)) {
        assertTrue(strpos((string) json_encode(array_column($fields, 'value')), '1.1.0 (env)') !== false, 'AboutInfo reports the stamped core version');
    }

    $main = (string) file_get_contents(__DIR__ . '/../fluxfiles.php');
    assertTrue(preg_match("/define\('FLUXFILES_CORE_VERSION', '[^']*'\);/", $main) === 1, 'placeholder defined in fluxfiles.php');
    $dir = sys_get_temp_dir() . '/ffstamp-' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/fluxfiles.php', $main);
    file_put_contents($dir . '/readme.txt', "Stable tag: 0.0.0\n");
    $p = proc_open(['python3', '-I', __DIR__ . '/../../../scripts/stamp-wp-version.py', $dir, '9.9.9', '7.8.9'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    $out = (string) file_get_contents($dir . '/fluxfiles.php');
    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
    assertEqual(0, $rc, 'stamp script succeeds');
    assertTrue(strpos($out, "define('FLUXFILES_CORE_VERSION', '7.8.9');") !== false, 'core version stamped');
    assertTrue(strpos($out, "define('FLUXFILES_VERSION', '9.9.9');") !== false, 'plugin version stamped');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

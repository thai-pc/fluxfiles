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

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

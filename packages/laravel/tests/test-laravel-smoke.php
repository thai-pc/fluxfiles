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

// Default: the sibling core in this monorepo (always newest). CI's floor check
// overrides FLUXFILES_CORE_AUTOLOAD to point at core built at composer.json's
// declared floor, so a call to a core API newer than the floor fails here
// instead of in a user's production app.
$coreAutoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
require_once $coreAutoload;   // FluxFiles\JwtCompat, CredentialEncryptor
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

test('token() emits per-tenant overrides without depending on a core method', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token(9, [
        'ai_auto_tag' => true,
        'rate_read'   => 200,
        'variants'    => ['large' => 2560],
    ]);
    $claims = \FluxFiles\JwtCompat::decode($token, $secret);
    assertEqual(true, $claims->ai_auto_tag ?? null, 'ai_auto_tag carried');
    assertEqual(200, $claims->rate_read ?? 0, 'rate_read carried');
    assertEqual(['large' => 2560], (array) ($claims->variants ?? null), 'variants passed through (core sanitizes on decode)');
});

test('token() forwards URL-import claims so the feature can be enabled', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token(11, [
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

test('token() forwards media-preview claims', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token(21, [
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

test('token() forwards webp claims', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token(31, ['webp_enabled' => false, 'webp_max_width' => 1600, 'webp_default_quality' => 75,
        'srcset_widths' => [400, 1200], 'srcset_sizes' => '100vw']);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(false, $c->webpEnabled, 'webp_enabled');
    assertEqual(1600, $c->webpMaxWidth, 'webp_max_width');
    assertEqual(75, $c->webpDefaultQuality, 'webp_default_quality');
    assertEqual([400, 1200], $c->srcsetWidths, 'srcset_widths');
    assertEqual('100vw', $c->srcsetSizes, 'srcset_sizes');
});

test('token() forwards download/zip claims; overlay watermark only in standalone mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_download' => false, 'allow_chmod' => false, 'allow_code_edit' => true, 'allow_optimize' => true,
        'allow_zip' => false, 'allow_extract' => false, 'zip_max_mb' => 50,
        'watermark_enabled' => true, 'watermark_type' => 'text', 'watermark_text' => '© Acme',
        'watermark_position' => 'center', 'watermark_opacity' => 0.5,
    ];
    // Non-overlay claims always forward.
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($mgr->token(41, $overrides), $secret));
    assertEqual(false, $c->allowDownload, 'allow_download');
    assertEqual(false, $c->allowChmod, 'allow_chmod');
    assertEqual(true, $c->allowCodeEdit, 'allow_code_edit');
    assertEqual(true, $c->allowOptimize, 'allow_optimize');
    assertEqual(false, $c->allowZip, 'allow_zip');
    assertEqual(50, $c->zipMaxMb, 'zip_max_mb');

    // Proxy mode (default): the OVERLAY watermark is dropped — /api/fm/img isn't
    // proxied, so a watermark token would break (no clean URL, no servable preview).
    assertEqual(null, $c->watermark, 'overlay watermark NOT forwarded in proxy mode');

    // Standalone mode: the token targets a real core that serves /img → forward it.
    $prev = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'];
    $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = 'standalone';
    try {
        $sc = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($mgr->token(41, $overrides), $secret));
        assertEqual('© Acme', $sc->watermark['text'], 'watermark text (standalone)');
        assertEqual('center', $sc->watermark['position'], 'watermark position (standalone)');
    } finally {
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = $prev;
    }
});

test('token() forwards usage-dashboard claims', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $token = $mgr->token(51, ['usage_cache_ttl' => 600, 'usage_warning_threshold' => 60, 'usage_folder_depth' => 2]);
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($token, $secret));
    assertEqual(600, $c->usageCacheTtl, 'cache_ttl');
    assertEqual(60, $c->usageWarningThreshold, 'warning');
    assertEqual(2, $c->usageFolderDepth, 'depth');
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

test('proxy route surface covers every core /api/fm route', function () {
    // The Laravel proxy is an explicit allow-list, so a core route that isn't
    // proxied 404s for Laravel users (this is the disk/doctor + trash gap).
    // Diff core's literal `$uri === '/api/fm/…'` routes against routes/fluxfiles.php.
    $coreSrc  = (string) file_get_contents(__DIR__ . '/../../core/api/index.php');
    $routeSrc = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');

    preg_match_all("#\\\$uri === '/api/fm/([a-z0-9/_-]+)'#", $coreSrc, $cm);
    $coreRoutes = array_unique($cm[1]);
    sort($coreRoutes);

    preg_match_all("#Route::[a-z]+\\(\\s*'([a-z0-9/_{}-]+)'#", $routeSrc, $rm);
    $proxyRoutes = array_map(fn ($r) => preg_replace('#/\{[^}]+\}#', '', $r), $rm[1]);

    // Core routes that are intentionally NOT proxied (keep empty unless justified).
    // - stream / img: gated-local media and on-demand WebP are core-standalone /
    //   Docker features. Both mint their tokens only when FileManager has a stream
    //   secret (setStreamSecret), which the Laravel proxy controller does not set —
    //   so list() never emits stream/img URLs in proxy mode, and there are no
    //   broken links. Proxying these byte-serving endpoints is a future option.
    // - chmod: only operates on an SFTP disk, which is a core-standalone driver
    //   (the proxy doesn't expose SFTP), so chmod has nothing to act on in proxy
    //   mode. Belongs with the SFTP/core-standalone group.
    // - zip: streams a binary zip to the client (ZipStream → php://output, bypassing
    //   the JSON encoder); the JSON-returning proxy controllers don't do streaming
    //   responses, so it's a core-standalone / Docker feature like stream/img.
    //   (Extract, by contrast, returns JSON and IS proxied.)
    // - paid module endpoints (share, ai-vision, ocr, backup, c2pa): the module code
    //   is a separate proprietary package gated by ModuleRegistry. They're wired in
    //   core; the adapter proxy route for each lands with that module's full release
    //   (optimize is already proxied as the reference). Scaffolded now, proxied per
    //   module when its engine ships.
    // - terminal: opens a shell over SSH on an SFTP disk (phpseclib exec). It needs
    //   the live SFTP connection + the allow_terminal claim and is a core-standalone /
    //   Docker feature; the adapter proxies don't expose a shell.
    $intentionallyUnproxied = ['stream', 'img', 'chmod', 'zip', 'terminal', 'share', 'ai-vision', 'ocr', 'backup', 'c2pa'];

    $missing = array_values(array_diff($coreRoutes, $proxyRoutes, $intentionallyUnproxied));
    assertTrue($missing === [], 'core routes not proxied by Laravel: ' . implode(', ', $missing));
});

test('every proxy route maps to an existing controller method', function () {
    $routeSrc = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    $ctrlSrc  = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    preg_match_all("#FluxFilesController::class,\\s*'([a-zA-Z0-9_]+)'#", $routeSrc, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $method) {
        if (!preg_match('#function\s+' . preg_quote($method, '#') . '\s*\(#', $ctrlSrc)) {
            $missing[] = $method;
        }
    }
    assertTrue($missing === [], 'routes reference missing controller methods: ' . implode(', ', $missing));
});

// Session-expiry recovery: the embedded UI must be able to re-mint a JWT from
// the Laravel session (NOT the expired JWT) so "Try again" works without a full
// page reload. Three pieces must line up: the controller method, a session-auth
// token route in the ServiceProvider, and the blade wiring onTokenRefresh.
test('token-refresh recovery wiring is present (controller + route + blade)', function () {
    $ctrlSrc  = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    $provSrc  = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    $bladeSrc = (string) file_get_contents(__DIR__ . '/../src/Views/components/fluxfiles.blade.php');

    assertTrue(preg_match('#function\s+token\s*\(#', $ctrlSrc) === 1, 'controller has token() method');
    // The refresh route must be registered with the bare middleware (session),
    // NOT the FluxFilesAuth JWT class — the JWT is expired at refresh time.
    assertTrue(strpos($provSrc, "'token', [FluxFilesController::class, 'token']") !== false, 'ServiceProvider registers GET token route');
    assertTrue(strpos($bladeSrc, 'onTokenRefresh') !== false, 'blade wires onTokenRefresh');
    assertTrue(strpos($bladeSrc, '$tokenUrl') !== false, 'blade fetches the token-refresh URL');
});

test('allow_terminal (SSH terminal) forwarded only in standalone mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    // Proxy mode (default): /api/fm/terminal isn't proxied → claim dropped.
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($mgr->token(7, ['allow_terminal' => true]), $secret));
    assertEqual(false, $c->allowTerminal, 'terminal dropped in proxy mode');
    // Standalone mode: token targets a real core that serves /terminal → forward.
    $prev = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'];
    $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = 'standalone';
    try {
        $sc = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($mgr->token(7, ['allow_terminal' => true]), $secret));
        assertEqual(true, $sc->allowTerminal, 'terminal forwarded in standalone mode');
    } finally {
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = $prev;
    }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

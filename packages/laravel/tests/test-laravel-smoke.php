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

// Regression: the module GATE claims (allow_versioning/allow_webhooks) shipped here
// but their config claims did not, so a Laravel host could turn Webhooks on and have
// it POST nowhere. Gate + config must travel together.
test('token() forwards versioning + webhook config claims, not just the gate', function () use ($secret) {
    $mgr = new FluxFilesManager();
    // Both versioning and webhooks now have proxy routes, so both forward in any
    // mode (the default proxy mode here included) — no mode toggling needed.
    $token = $mgr->token(52, [
        'allow_versioning'  => true,
        'versioning_max'    => 5,
        'versioning_max_mb' => 50,
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
    assertEqual(true, $p->allow_versioning ?? null, 'versioning gate');
    assertEqual(5, $p->versioning_max ?? 0, 'versioning_max');
    assertEqual(50, $p->versioning_max_mb ?? 0, 'versioning_max_mb');
    assertEqual(true, $p->allow_webhooks ?? null, 'webhooks gate');
    assertEqual('https://hooks.acme.com/flux', $p->webhook_url ?? '', 'webhook_url');
    assertEqual(['upload', 'delete'], (array) ($p->webhook_events ?? []), 'webhook_events');
    assertEqual('whsec_abc123', $p->webhook_secret ?? '', 'webhook_secret');

    // A config file naturally holds a comma-separated string; the core normalizes it.
    $csv = \FluxFiles\JwtCompat::decode(
        $mgr->token(53, ['allow_webhooks' => true, 'webhook_events' => 'upload,delete']),
        $secret
    );
    assertEqual('upload,delete', $csv->webhook_events ?? '', 'CSV events forwarded as-is');
});

// Share + Intake now have routes in BOTH modes (proxy: FluxFilesController's
// shareIntake()/publicLink() dispatchers; standalone: index.php), so the gate
// claims — and the config that travels with them (link base, presigned-URL TTL,
// preview policy, analytics — all read at create time) — forward unconditionally
// in both modes, same as the other module gates below.
test('share/intake gates + their config forward in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_share'     => true,
        'allow_intake'    => true,
        'share_url_ttl'   => 120,
        'share_base_url'  => 'https://files.acme.com/public/share.html',
        'share_preview'   => false,
        'share_analytics' => true,
        'intake_base_url' => 'https://files.acme.com/public/intake.html',
        'intake_analytics' => true,
    ];
    // Assert on the RAW JWT payload, not Claims — the adapter only writes payload
    // keys (no new core API), so this stays valid against the declared core floor.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(56, $overrides), $secret);
    assertEqual(true, $p->allow_share ?? null, 'share gate');
    assertEqual(true, $p->allow_intake ?? null, 'intake gate');
    assertEqual(120, $p->share_url_ttl ?? 0, 'share_url_ttl');
    assertEqual('https://files.acme.com/public/share.html', $p->share_base_url ?? '', 'share_base_url');
    assertEqual(false, $p->share_preview ?? null, 'share_preview');
    assertEqual(true, $p->share_analytics ?? null, 'share_analytics');
    assertEqual('https://files.acme.com/public/intake.html', $p->intake_base_url ?? '', 'intake_base_url');
    assertEqual(true, $p->intake_analytics ?? null, 'intake_analytics');

    // …including via the edition preset, which defaults both gates for 'pro'.
    $pro = \FluxFiles\JwtCompat::decode($mgr->token(57, ['edition' => 'pro']), $secret);
    assertEqual(true, $pro->allow_share ?? null, 'edition preset lights up share in proxy mode');
    assertEqual(true, $pro->allow_intake ?? null, 'edition preset lights up intake in proxy mode');
    assertEqual(true, $pro->allow_optimize ?? null, 'the rest of the preset is unaffected');
});

test('role preset (docs/ACL-ROLE-PRESETS-DESIGN.md) sets the exact claim bundle', function () use ($secret) {
    $mgr = new FluxFilesManager();

    $admin = \FluxFiles\JwtCompat::decode($mgr->token(60, ['role' => 'admin']), $secret);
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $admin->perms, 'admin perms');
    assertEqual(true, ($admin->owner_only ?? false) === false, 'admin is not owner-scoped');
    assertEqual(true, $admin->allow_extract ?? null, 'admin allow_extract');
    assertEqual(true, $admin->allow_chmod ?? null, 'admin allow_chmod');
    assertEqual(true, $admin->allow_code_edit ?? null, 'admin allow_code_edit');
    assertEqual(true, $admin->show_hidden ?? null, 'admin show_hidden');

    $viewer = \FluxFiles\JwtCompat::decode($mgr->token(61, ['role' => 'viewer']), $secret);
    assertEqual(['read'], (array) $viewer->perms, 'viewer perms');
    assertEqual(true, $viewer->owner_only ?? false, 'viewer is owner-scoped');
    // Regression (B1): allow_extract/allow_chmod default TRUE when absent from
    // the JWT (Claims::fromJwtPayload) — viewer/editor must set them explicitly
    // false, not rely on omission, or a proxy-minted "editor" token silently
    // gets chmod on SFTP disks.
    assertEqual(false, $viewer->allow_extract ?? null, 'viewer allow_extract');
    assertEqual(false, $viewer->allow_chmod ?? null, 'viewer allow_chmod');

    // Regression: perms is resolved BEFORE the base payload array (which already
    // has an unconditional ['read'] default) — a plain post-hoc guard would never fire.
    $editor = \FluxFiles\JwtCompat::decode($mgr->token(62, ['role' => 'editor']), $secret);
    assertEqual(['read', 'write'], (array) $editor->perms, 'editor perms-early-resolution');
    assertEqual(true, $editor->allow_extract ?? null, 'editor allow_extract');
    assertEqual(false, $editor->allow_chmod ?? null, 'editor allow_chmod');

    // Explicit overrides win over the role default.
    $overridden = \FluxFiles\JwtCompat::decode($mgr->token(63, ['role' => 'viewer', 'perms' => ['read', 'write', 'delete'], 'owner_only' => false]), $secret);
    assertEqual(['read', 'write', 'delete'], (array) $overridden->perms, 'explicit perms overrides role');
    assertEqual(false, $overridden->owner_only ?? false, 'explicit owner_only=false overrides role default of true');

    // edition + role compose without clobbering each other.
    $both = \FluxFiles\JwtCompat::decode($mgr->token(64, ['edition' => 'pro', 'role' => 'admin']), $secret);
    assertEqual(true, $both->allow_share ?? null, 'edition claim present alongside role');
    assertEqual(['read', 'write', 'delete', 'audit'], (array) $both->perms, 'role claim present alongside edition');
});

// The other module gates have no mode-conditional history, so they keep forwarding
// in both modes too (documented in the manager).
test('the other module gates still forward in proxy mode', function () use ($secret) {
    $p = \FluxFiles\JwtCompat::decode(
        (new FluxFilesManager())->token(58, ['allow_ocr' => true, 'allow_c2pa' => true]),
        $secret
    );
    assertEqual(true, $p->allow_ocr ?? null, 'allow_ocr unchanged');
    assertEqual(true, $p->allow_c2pa ?? null, 'allow_c2pa unchanged');
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

    // Share/Intake's public recipient routes live in the ServiceProvider (they must
    // be registered outside the FluxFilesAuth group), not routes/fluxfiles.php —
    // scan both so this guard reflects true adapter route coverage.
    $provSrcForParity = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    // Matches both `Route::get('path', ...)` (routes/fluxfiles.php) and the
    // chained `Route::prefix($prefix)->get('path', ...)` form used for the
    // public recipient routes in FluxFilesServiceProvider — `->where(...)`
    // is deliberately excluded so its first string arg (a param name, not a
    // path) never gets mistaken for a route.
    preg_match_all("#(?:Route::|->)(?:get|post|put|patch|delete)\\(\\s*'([a-z0-9/_{}.-]+)'#", $routeSrc . "\n" . $provSrcForParity, $rm);
    $proxyRoutes = array_map(fn ($r) => preg_replace('#/\{[^}]+\}#', '', $r), $rm[1]);

    // Core routes that are intentionally NOT proxied (keep empty unless justified).
    // - chmod: only operates on an SFTP disk, which is a core-standalone driver
    //   (the proxy doesn't expose SFTP), so chmod has nothing to act on in proxy
    //   mode. Belongs with the SFTP/core-standalone group.
    // - zip: streams a binary zip to the client (ZipStream → php://output, bypassing
    //   the JSON encoder); the JSON-returning proxy controllers don't do streaming
    //   responses, so it stays a core-standalone / Docker feature (byte streaming
    //   like stream/img, but zip specifically was never ported).
    //   (Extract, by contrast, returns JSON and IS proxied.)
    $intentionallyUnproxied = ['chmod', 'zip',
        // Share + Intake (operator create/list/revoke/analytics AND the public
        // info/unlock/file/upload landing routes), file Versioning (list/restore),
        // Audit export/purge, AI Vision/OCR/Backup Bridge/C2PA, the SSH terminal,
        // and gated media stream/img are now fully proxied — see
        // routes/fluxfiles.php + FluxFilesServiceProvider::registerRoutes().
        // SSO bridge: pre-auth routes for the standalone /public UI's own login
        // screen. They exist to gate deployments with NO host app minting tokens —
        // Laravel apps already authenticate via fluxfiles_token(), so there is
        // nothing for the proxy to forward here at all (see the NOTE in
        // FluxFilesManager::applyOverrides about SSO not being a claim concern).
        // - metadata/export, metadata/import (docs/DB-STORAGE-MIGRATION-DESIGN.md §7):
        //   \FluxFiles\Db\MetadataExporter/MetadataImporter work directly against
        //   core's own \FluxFiles\Db\Connection/dialect SQL layer (raw table access,
        //   not MetadataRepositoryInterface). Laravel's `db` backend option (§5)
        //   uses LaravelDbMetadataHandler on Laravel's own Eloquent connection
        //   instead, so these two classes have nothing to bind to in proxy mode —
        //   porting export/import would mean a second, Eloquent-flavored
        //   implementation, not a passthrough. No such port exists yet.
        'sso/login', 'sso/callback', 'sso/exchange',
        'metadata/export', 'metadata/import'];

    $missing = array_values(array_diff($coreRoutes, $proxyRoutes, $intentionallyUnproxied));
    assertTrue($missing === [], 'core routes not proxied by Laravel: ' . implode(', ', $missing));
});

test('every proxy route maps to an existing controller method', function () {
    $routeSrc = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    $provSrc  = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    $ctrlSrc  = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    preg_match_all("#FluxFilesController::class,\\s*'([a-zA-Z0-9_]+)'#", $routeSrc . "\n" . $provSrc, $m);
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

test('allow_terminal (SSH terminal) forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    // SSH terminal now has a route in both modes (proxy: terminal(); standalone:
    // index.php), so the gate forwards unconditionally — proxy mode (default)
    // included. This used to be the last standalone-only holdout.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, ['allow_terminal' => true]), $secret);
    assertEqual(true, ($p->allow_terminal ?? false), 'terminal claim forwarded in proxy mode');
});

test('allow_git_deploy (+ path/branch/hooks) forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_git_deploy'  => true,
        'git_deploy_path'   => '/var/www/site',
        'git_deploy_branch' => 'main',
        'git_deploy_hooks'  => true,
    ];

    // Git deploy now has a route in both modes (proxy: gitDeploy(); standalone:
    // index.php), so the gate + its target claims forward unconditionally —
    // proxy mode (default) included, matching allow_terminal above.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, $overrides), $secret);
    assertEqual(true, ($p->allow_git_deploy ?? false), 'git-deploy gate forwarded in proxy mode');
    assertEqual('/var/www/site', $p->git_deploy_path ?? '', 'git_deploy_path forwarded in proxy mode');
    assertEqual('main', $p->git_deploy_branch ?? '', 'git_deploy_branch forwarded in proxy mode');
    assertEqual(true, ($p->git_deploy_hooks ?? false), 'git_deploy_hooks forwarded in proxy mode');
});

test('allow_versioning (+ its tuning claims) forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_versioning'  => true,
        'versioning_max'    => 5,
        'versioning_max_mb' => 50,
    ];

    // Versioning now has routes in both modes (proxy: versions()/versionsRestore()
    // + the version-keeper hook wired in fileManager()), so the gate + its tuning
    // claims forward unconditionally — proxy mode (default) included.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, $overrides), $secret);
    assertEqual(true, ($p->allow_versioning ?? false), 'versioning gate forwarded in proxy mode');
    assertEqual(5, $p->versioning_max ?? 0, 'versioning_max forwarded in proxy mode');
    assertEqual(50, $p->versioning_max_mb ?? 0, 'versioning_max_mb forwarded in proxy mode');
});

test('allow_audit_export (+ audit_retention_days) forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_audit_export'   => true,
        'audit_retention_days' => 365,
    ];

    // Audit export/purge now has routes in both modes (proxy: auditExport()/
    // auditPurge(); standalone: index.php), so the gate + its tuning claim
    // forward unconditionally — proxy mode (default) included.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, $overrides), $secret);
    assertEqual(true, ($p->allow_audit_export ?? false), 'audit-export gate forwarded in proxy mode');
    assertEqual(365, $p->audit_retention_days ?? 0, 'audit_retention_days forwarded in proxy mode');
});

test('allow_ai_vision forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();

    // AI Vision now has a route in both modes (proxy: aiVision(); standalone:
    // index.php), so the gate forwards unconditionally — proxy mode (default)
    // included. Previously this was standalone-only (see allow_terminal above
    // for the pattern this test replaces).
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, ['allow_ai_vision' => true]), $secret);
    assertEqual(true, ($p->allow_ai_vision ?? false), 'ai-vision gate forwarded in proxy mode');
});

test('gated media stream/img is wired (setStreamSecret + local disk private key)', function () {
    // Unlike every other phase, stream/img has no claim to de-gate — media-preview/
    // webp claims already forwarded fine, only the *serving* endpoints were missing.
    // What must be true instead: setStreamSecret() is called unconditionally (so
    // list() actually emits img_base/gatedLocal URLs), and the local disk config
    // has a 'private' switch for FileManager::isGatedLocal() to read.
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($ctrlSrc, 'setStreamSecret(') !== false, 'fileManager() wires setStreamSecret()');
    assertTrue(strpos($ctrlSrc, 'StreamToken::verify(') !== false, 'stream() verifies a StreamToken');
    assertTrue(strpos($ctrlSrc, 'ImageToken::verify(') !== false, 'img() verifies an ImageToken');

    $cfg = require __DIR__ . '/../config/fluxfiles.php';
    assertTrue(array_key_exists('private', $cfg['disks']['local']), "local disk config has a 'private' key");
});

test('stream/img raw-byte responses exit instead of returning (no Laravel default Response)', function () {
    // A controller action with a void/null return lets Laravel's kernel build and
    // send its OWN default Response, and Symfony's Response::sendHeaders() always
    // re-asserts Content-Type with $replace=true — silently clobbering any raw
    // header('Content-Type: ...') this code already sent (e.g. back to
    // text/html), which breaks every <img>/<video> hitting these endpoints
    // (a real bug found + fixed once — see FluxFilesController::publicLink()'s
    // pre-existing `exit;` for the established correct pattern this must match).
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');

    $extractMethod = function (string $src, string $name): string {
        $start = strpos($src, "function {$name}(");
        assertTrue($start !== false, "method {$name}() exists");
        $end = strpos($src, "\n    public function ", $start + 1);
        if ($end === false) {
            $end = strpos($src, "\n    private function ", $start + 1);
        }
        return $end !== false ? substr($src, $start, $end - $start) : substr($src, $start);
    };

    $streamBody = $extractMethod($ctrlSrc, 'stream');
    $imgBody = $extractMethod($ctrlSrc, 'img');
    $serveBytesBody = $extractMethod($ctrlSrc, 'serveBytes');

    assertTrue(strpos($serveBytesBody, 'exit;') !== false, 'serveBytes() exits after emitting raw bytes');
    assertTrue(!preg_match('/\breturn;/', $streamBody), 'stream() has no bare return; (must exit)');
    assertTrue(!preg_match('/\breturn;/', $imgBody), 'img() has no bare return; (must exit)');
});

test('controller branches metadata backend on fluxfiles.storage_backend', function () {
    // db mode = 4-table SQL backend (fluxfiles_*), added alongside core's own
    // DB storage backend (core-v0.2.79) — see LaravelDbMetadataHandler.
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($ctrlSrc, "config('fluxfiles.storage_backend') === 'db'") !== false, 'constructor checks storage_backend');
    assertTrue(strpos($ctrlSrc, 'LaravelDbMetadataHandler(') !== false, 'constructor can build LaravelDbMetadataHandler');
    assertTrue(strpos($ctrlSrc, "use FluxFiles\\Laravel\\LaravelDbMetadataHandler;") !== false, 'imports LaravelDbMetadataHandler');

    $cfg = require __DIR__ . '/../config/fluxfiles.php';
    assertTrue(array_key_exists('storage_backend', $cfg), "config has 'storage_backend'");
    assertTrue(array_key_exists('db_connection', $cfg), "config has 'db_connection'");
    assertEqual('json', $cfg['storage_backend'], 'storage_backend defaults to json');

    $provSrc = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    assertTrue(strpos($provSrc, "'fluxfiles-migrations'") !== false, 'ServiceProvider publishes the migrations tag');
});

test('MigrateJsonToDbCommand is registered and exposes the expected signature', function () {
    $provSrc = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    assertTrue(strpos($provSrc, 'Console\MigrateJsonToDbCommand::class') !== false, 'ServiceProvider registers MigrateJsonToDbCommand');

    $cmdSrc = (string) file_get_contents(__DIR__ . '/../src/Console/MigrateJsonToDbCommand.php');
    assertTrue(strpos($cmdSrc, "'fluxfiles:migrate-json-to-db") !== false, 'command signature is fluxfiles:migrate-json-to-db');
    assertTrue(strpos($cmdSrc, '--dry-run') !== false, 'command has --dry-run');
    assertTrue(strpos($cmdSrc, '--verify') !== false, 'command has --verify');
    assertTrue(strpos($cmdSrc, 'LaravelDbMetadataHandler(') !== false, 'command targets LaravelDbMetadataHandler');
});

test('optimize() gates on the allow_optimize claim directly, not ModuleRegistry (FREE/core, not a paid module)', function () {
    // 'optimize' was removed from ModuleRegistry::$map when Optimize moved to
    // free/core (core index.php's /api/fm/optimize route does a direct claim
    // check, not ModuleRegistry::require()). This adapter's manual-trigger route
    // regressed to the stale gate once — assert it mirrors the on-upload hook
    // in the same file (setUploadOptimizer's `if ($claims->autoOptimize ?? false)`
    // block) instead of the paid-module 3-layer gate.
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');

    $start = strpos($ctrlSrc, 'function optimize(');
    assertTrue($start !== false, 'optimize() method exists');
    $end = strpos($ctrlSrc, "\n    public function ", $start + 1);
    $body = $end !== false ? substr($ctrlSrc, $start, $end - $start) : substr($ctrlSrc, $start);

    assertTrue(strpos($body, "ModuleRegistry::require('optimize'") === false, 'optimize() no longer calls ModuleRegistry::require for optimize');
    assertTrue(strpos($body, 'allowOptimize') !== false, 'optimize() checks the allowOptimize claim directly');
    assertTrue(strpos($body, "'optimize_forbidden'") !== false, 'optimize() throws optimize_forbidden when the claim is absent');
    assertTrue(strpos($body, 'new \\FluxFiles\\OptimizeModule()') !== false, 'optimize() instantiates OptimizeModule directly');
});

test('chunkComplete() re-validates the REAL assembled size (S3 multipart size/quota bypass fix)', function () {
    // /chunk/init only ever checks a CLIENT-DECLARED size before any bytes move —
    // parts are then PUT straight to S3 on presigned URLs with no size condition,
    // so a client could declare 1 byte and upload gigabytes. Mirror core's fix
    // (index.php's handleChunkComplete): re-run max_upload_mb/quota against the
    // REAL size complete() now reports (via HeadObject), and delete the object
    // on violation instead of leaving it sitting in storage.
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');

    $start = strpos($ctrlSrc, 'function chunkComplete(');
    assertTrue($start !== false, 'chunkComplete() method exists');
    $end = strpos($ctrlSrc, "\n    public function ", $start + 1);
    $body = $end !== false ? substr($ctrlSrc, $start, $end - $start) : substr($ctrlSrc, $start);

    assertTrue(strpos($body, "\$result['size']") !== false, 'reads the real size back from complete()\'s result');
    assertTrue(strpos($body, 'validateUploadName(') !== false, 'chunkComplete() re-checks max_upload_mb via validateUploadName');
    assertTrue(strpos($body, 'assertQuota(') !== false, 'chunkComplete() re-checks quota via assertQuota');
    // The 0-delta detail is load-bearing: usage scans already see the just-completed
    // object on disk, so passing the real size again would double-count it.
    assertTrue((bool) preg_match('/assertQuota\(\s*\$disk,\s*\$claims->pathPrefix,\s*0,/s', $body), 'assertQuota is called with a 0 delta, not the real size (avoids double-counting)');
    assertTrue(strpos($body, 'deleteObject(') !== false, 'chunkComplete() deletes the oversized/over-quota object on violation');
    // The delete + rethrow must happen BEFORE metadata is saved for the object.
    assertTrue(strpos($body, 'deleteObject(') < strpos($body, 'metaRepo->save('), 'violation cleanup runs before metadata is saved');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

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

test('token() forwards download/zip claims and the overlay watermark in BOTH modes', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_download' => false, 'allow_chmod' => false, 'allow_code_edit' => true, 'allow_optimize' => true,
        'allow_zip' => false, 'allow_extract' => false, 'zip_max_mb' => 50,
        'watermark_enabled' => true, 'watermark_type' => 'text', 'watermark_text' => '© Acme',
        'watermark_position' => 'center', 'watermark_opacity' => 0.5,
    ];
    $c = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($mgr->token(41, $overrides), $secret));
    assertEqual(false, $c->allowDownload, 'allow_download');
    assertEqual(false, $c->allowChmod, 'allow_chmod');
    assertEqual(true, $c->allowCodeEdit, 'allow_code_edit');
    assertEqual(true, $c->allowOptimize, 'allow_optimize');
    assertEqual(false, $c->allowZip, 'allow_zip');
    assertEqual(50, $c->zipMaxMb, 'zip_max_mb');

    // /api/fm/img is the shared core MediaEndpoint in proxy mode too, which composites
    // the overlay, so the claim forwards whatever the mode.
    foreach (['direct', 'standalone'] as $mode) {
        $prev = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'];
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = $mode;
        try {
            $wc = \FluxFiles\Claims::fromJwtPayload(\FluxFiles\JwtCompat::decode($mgr->token(41, $overrides), $secret));
            assertEqual('© Acme', $wc->watermark['text'] ?? null, "watermark text ({$mode})");
            assertEqual('center', $wc->watermark['position'] ?? null, "watermark position ({$mode})");
        } finally {
            $GLOBALS['LARAVEL_CONFIG']['fluxfiles.mode'] = $prev;
        }
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

test('role preset (docs/design/ACL-ROLE-PRESETS-DESIGN.md) sets the exact claim bundle', function () use ($secret) {
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
    // chmod and zip USED to be listed here. Both rationales had gone stale:
    // chmod's ("the proxy doesn't expose SFTP") is contradicted by terminal() and
    // gitDeploy(), which are proxied and are themselves SFTP-only, plus a BYOB
    // sftp disk in the token reaches proxy mode via fileManager(); zip's ("the
    // JSON-returning controllers don't do streaming") is contradicted by
    // auditExport()'s response()->stream() and stream(). allow_zip defaults TRUE,
    // so the UI's "Download ZIP" button rendered in proxy mode and 404'd. Both
    // are proxied now.
    $intentionallyUnproxied = [
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
        // - metadata/export, metadata/import (docs/design/DB-STORAGE-MIGRATION-DESIGN.md §7):
        //   \FluxFiles\Db\MetadataExporter/MetadataImporter work directly against
        //   core's own \FluxFiles\Db\Connection/dialect SQL layer (raw table access,
        //   not MetadataRepositoryInterface). Laravel's `db` backend option (§5)
        //   uses LaravelDbMetadataHandler on Laravel's own Eloquent connection
        //   instead, so these two classes have nothing to bind to in proxy mode —
        //   porting export/import would mean a second, Eloquent-flavored
        //   implementation, not a passthrough. No such port exists yet.
        'sso/login', 'sso/callback', 'sso/exchange',
        'metadata/export', 'metadata/import',
        // - tus (resumable uploads): stages partial bytes in core's own
        //   <FLUXFILES_STORAGE_PATH>/tus/ and speaks raw tus (PATCH/HEAD, no JSON
        //   envelope). The embedded UI falls back to a plain upload when /tus
        //   404s, so proxy mode loses nothing it had before. A port would need the
        //   adapter's own staging dir + PATCH body streaming — not done in v1.
        'tus'];

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

test('the removed terminal/git-deploy claims are no longer minted, and an old token that carries them still decodes', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $p = (array) \FluxFiles\JwtCompat::decode($mgr->token(7, [
        'allow_terminal' => true, 'allow_git_deploy' => true, 'git_deploy_path' => '/var/www/site',
        'git_deploy_branch' => 'main', 'git_deploy_hooks' => true, 'terminal_pty_url' => 'https://tty.example.com',
    ]), $secret);
    foreach (['allow_terminal', 'allow_git_deploy', 'git_deploy_path', 'git_deploy_branch', 'git_deploy_hooks'] as $gone) {
        assertTrue(!array_key_exists($gone, $p), "{$gone} is not forwarded any more");
    }
    assertEqual('https://tty.example.com', $p['terminal_pty_url'] ?? null, 'terminal_pty_url still forwards (UI embeds the operator\'s ttyd)');

    // A token minted by an older adapter / SDK that still carries them must be ignored, not rejected.
    $legacy = \FluxFiles\JwtCompat::decode(\FluxFiles\JwtCompat::encode([
        'sub' => 'old', 'iat' => time(), 'exp' => time() + 600, 'perms' => ['read'], 'disks' => ['local'], 'prefix' => '',
        'allow_terminal' => true, 'allow_git_deploy' => true, 'git_deploy_path' => '/x', 'git_deploy_hooks' => true,
    ], $secret), $secret);
    $c = \FluxFiles\Claims::fromJwtPayload($legacy, $secret);
    assertEqual('old', $c->userId, 'a legacy token with the removed claims still yields Claims');
});

test('the terminal and git-deploy proxy routes are gone', function () {
    $routes = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    $ctrl = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(!preg_match("#'(terminal|git-deploy)'#", $routes), 'no terminal / git-deploy route');
    assertTrue(!preg_match('#function\s+(terminal|gitDeploy)\s*\(#', $ctrl), 'no terminal() / gitDeploy() action');
    assertTrue(!str_contains($ctrl, 'SshTerminal') && !str_contains($ctrl, 'GitDeploy::'), 'no SshTerminal / GitDeploy reference');
});

test('BYOB disks register for every route, and file text is read untrimmed', function () {
    $ctrl = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    preg_match('/private function claims\(.*?\n    }\n/s', $ctrl, $claims);
    assertTrue(str_contains($claims[0] ?? '', 'registerByobDisk'), 'claims() registers BYOB disks (search, quota, usage never build a FileManager)');
    preg_match('/public function putContent\(.*?\n    }\n/s', $ctrl, $put);
    assertTrue(str_contains($put[0] ?? '', '$this->rawContent($request)'), 'putContent bypasses TrimStrings');
    assertTrue(str_contains($ctrl, '$request->getContent()'), 'rawContent reads the raw body');
    assertTrue(str_contains((string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php'), 'SsrfGuard::setAllowHosts('), 'the provider applies FLUXFILES_SSRF_ALLOW_HOSTS');
    // Actually called, so the adapter-core floor guard fails if composer.json allows a core without it.
    \FluxFiles\SsrfGuard::setAllowHosts('Sftp.Example:22');
    assertEqual(['sftp.example:22'], \FluxFiles\SsrfGuard::$allowHosts, 'parsed');
    \FluxFiles\SsrfGuard::setAllowHosts('');
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

test('audit_reads forwards in proxy mode', function () use ($secret) {
    $p = \FluxFiles\JwtCompat::decode((new FluxFilesManager())->token(7, ['audit_reads' => true]), $secret);
    assertEqual(true, $p->audit_reads ?? null, 'audit_reads forwarded');
});

test('audit: the proxy routes success/denial auditing through core AuditPolicy, with no copy of its tables', function () {
    $src = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    foreach (['AuditPolicy::successEntry(', 'AuditPolicy::recordRefusedWrite(', 'AuditPolicy::recordDenied(', 'FileManagerFactory::webhookDispatcher('] as $needle) {
        assertTrue(strpos($src, $needle) !== false, "uses {$needle}");
    }
    foreach (['function auditKey(', 'function auditDenied(', 'function auditRefusedWrite(', 'function dispatchWebhook(', 'function logAudit(',
              "'virus_detected'    =>", "'presign_post'", "\$module . '_create'", "'c2pa_verify'", "'webhook_test'"] as $gone) {
        assertTrue(strpos($src, $gone) === false, "no hand-written copy: {$gone}");
    }
    foreach (["'zip_download'", "'content_read'", "'disk_doctor'", "'stream_read'" ] as $kept) {
        // stream_read now lives in MediaEndpoint; the other three are read/GET-side entries core also writes by hand.
        if ($kept === "'stream_read'") { continue; }
        assertTrue(strpos($src, $kept) !== false, "still logs {$kept}");
    }
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

test('allow_dlp_scan (+ its 4 tuning claims) forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();
    $overrides = [
        'allow_dlp_scan'      => true,
        'dlp_entity_types'    => ['US_SSN', 'CREDIT_CARD'],
        'dlp_scan_extensions' => ['txt', 'csv'],
        'dlp_max_scan_kb'     => 512,
        'dlp_min_score'       => 0.8,
    ];

    // DLP now has full proxy parity (fileManager()'s setDlpScanner() wiring + the
    // chunk-route 409 checks), so the gate + its tuning claims forward
    // unconditionally — proxy mode (default) included, matching
    // allow_versioning/allow_audit_export above. This regression-tests the exact
    // gap the webhooks/auto_optimize/ai_auto_tag history warns about: a claim that
    // decodes fine but whose hook was never wired in the proxy.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, $overrides), $secret);
    assertEqual(true, ($p->allow_dlp_scan ?? false), 'dlp gate forwarded in proxy mode');
    assertEqual(['US_SSN', 'CREDIT_CARD'], (array) ($p->dlp_entity_types ?? []), 'dlp_entity_types forwarded');
    assertEqual(['txt', 'csv'], (array) ($p->dlp_scan_extensions ?? []), 'dlp_scan_extensions forwarded');
    assertEqual(512, $p->dlp_max_scan_kb ?? 0, 'dlp_max_scan_kb forwarded');
    assertEqual(0.8, $p->dlp_min_score ?? 0, 'dlp_min_score forwarded');

    // …including via the enterprise edition preset.
    $ent = \FluxFiles\JwtCompat::decode($mgr->token(8, ['edition' => 'enterprise']), $secret);
    assertEqual(true, $ent->allow_dlp_scan ?? null, 'enterprise edition preset lights up allow_dlp_scan');
});

test('allow_legal_hold forwards in proxy mode', function () use ($secret) {
    $mgr = new FluxFilesManager();

    // Legal hold has full proxy parity (FluxFilesController's hold()/
    // holdRelease()/holdList()/holdStatus()), so the gate forwards
    // unconditionally — proxy mode (default) included, matching
    // allow_versioning/allow_audit_export/allow_dlp_scan above. Enforcement
    // itself needs no claim at all — it is wired inside core's FileManager
    // and inherited automatically since the proxy builds the same class.
    $p = \FluxFiles\JwtCompat::decode($mgr->token(7, ['allow_legal_hold' => true]), $secret);
    assertEqual(true, ($p->allow_legal_hold ?? false), 'legal-hold gate forwarded in proxy mode');

    // …including via the enterprise edition preset.
    $ent = \FluxFiles\JwtCompat::decode($mgr->token(8, ['edition' => 'enterprise']), $secret);
    assertEqual(true, $ent->allow_legal_hold ?? null, 'enterprise edition preset lights up allow_legal_hold');
});

test('the 4 legal-hold routes exist and only place/release/list are module-gated (status is free/core)', function () {
    // Mirrors the DLP inert-claims regression check above: assert the actual
    // wiring is present, not just that the claim decodes.
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($ctrlSrc, "ModuleRegistry::require('legal-hold'") !== false, 'hold()/holdRelease()/holdList() resolve the legal-hold module gate');
    assertTrue(strpos($ctrlSrc, 'function holdStatus(') !== false, 'holdStatus() exists');
    assertTrue(strpos($ctrlSrc, 'function hold(') !== false, 'hold() exists');
    assertTrue(strpos($ctrlSrc, 'function holdRelease(') !== false, 'holdRelease() exists');
    assertTrue(strpos($ctrlSrc, 'function holdList(') !== false, 'holdList() exists');

    $routesSrc = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    assertTrue(strpos($routesSrc, "'hold/status'") !== false, 'GET hold/status route registered');
    assertTrue(strpos($routesSrc, "'hold'") !== false, 'POST hold route registered');
    assertTrue(strpos($routesSrc, "'hold/release'") !== false, 'POST hold/release route registered');
    assertTrue(strpos($routesSrc, "'hold/list'") !== false, 'GET hold/list route registered');
});

test('compliance/scorecard route exists, maps to complianceScorecard(), and is gated by audit perm only (free/core, no module/license gate)', function () {
    // The scorecard is intentionally the OPPOSITE gating shape from every paid
    // module above (share/versioning/legal-hold/audit-export all call
    // ModuleRegistry::require()) — it's free/core, so a copy-paste of that
    // pattern onto this route would be a real regression (locking a free
    // dashboard behind a license check it was explicitly designed not to need).
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($ctrlSrc, 'function complianceScorecard(') !== false, 'complianceScorecard() exists');
    $body = substr($ctrlSrc, strpos($ctrlSrc, 'function complianceScorecard('));
    $body = substr($body, 0, strpos($body, "\n    }\n") ?: strlen($body));
    assertTrue(strpos($body, "hasPerm('audit')") !== false, 'complianceScorecard() gates on the audit perm');
    assertTrue(strpos($body, 'ComplianceScorecard::build(') !== false, 'complianceScorecard() calls the shared ComplianceScorecard::build()');
    assertTrue(strpos($body, 'ModuleRegistry::require(') === false, 'complianceScorecard() must NOT have a module/license gate — it is free/core');

    $routesSrc = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    assertTrue(strpos($routesSrc, "'compliance/scorecard'") !== false, 'GET compliance/scorecard route registered');
});

test('fileManager() wires every hook through the shared core factory (the inert-claims regression)', function () {
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($ctrlSrc, 'FileManagerFactory::wire(') !== false, 'fileManager() delegates to FileManagerFactory::wire()');
    foreach (['setDlpScanner(', 'setVirusScanner(', 'setVersionMover(', 'setStreamSecret('] as $own) {
        assertTrue(strpos($ctrlSrc, $own) === false, "the adapter no longer wires {$own} itself");
    }
    // The chunk handlers + presign(PUT) still refuse with 409 while a scan claim is on.
    assertEqual(5, substr_count($ctrlSrc, "'dlp_unscannable'"), 'the 4 chunk handlers AND presign(PUT) each check dlp_unscannable');
    assertEqual(5, substr_count($ctrlSrc, "'virus_unscannable'"), 'and the same 5 sites for virus_unscannable');
});

test('gated media stream/img is the shared MediaEndpoint and exits after it (no Laravel default Response)', function () {
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($ctrlSrc, 'new \\FluxFiles\\MediaEndpoint(') !== false, 'builds core MediaEndpoint');
    assertTrue(strpos($ctrlSrc, '->stream($request->query()') !== false && strpos($ctrlSrc, '->image($request->query()') !== false, 'both routes delegate');
    // The old hand-ported copies are gone.
    foreach (['StreamToken::verify(', 'ImageToken::verify(', 'function serveBytes', 'function snapQuality', 'function xaccelUri', 'function mediaRateLimit'] as $gone) {
        assertTrue(strpos($ctrlSrc, $gone) === false, "port removed: {$gone}");
    }
    // A void action lets Laravel's kernel send its own Response over the raw headers -> must exit.
    foreach (['stream', 'img'] as $m) {
        $start = strpos($ctrlSrc, "public function {$m}(");
        $body = substr($ctrlSrc, $start, strpos($ctrlSrc, "\n    }\n", $start) - $start);
        assertTrue(strpos($body, 'exit;') !== false, "{$m}() exits");
        assertTrue(!preg_match('/\breturn;/', $body), "{$m}() has no bare return");
    }
    $cfg = require __DIR__ . '/../config/fluxfiles.php';
    assertTrue(array_key_exists('private', $cfg['disks']['local']), "local disk config has a 'private' key");
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

test('chunkComplete() wires preflight validation without deleting an existing object', function () {
    // Behavior is exercised by core's test-chunk-preflight and write-policy-parity.
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    $start = strpos($ctrlSrc, 'function chunkComplete(');
    assertTrue($start !== false, 'chunkComplete() method exists');
    $end = strpos($ctrlSrc, "\n    public function ", $start + 1);
    $body = $end !== false ? substr($ctrlSrc, $start, $end - $start) : substr($ctrlSrc, $start);
    assertTrue(strpos($body, 'fn(int $size) => $fm->validateChunkUpload($disk, $key, $size)') !== false, 'validates the measured parts before completion');
    assertTrue(strpos($body, 'deleteObject(') === false, 'must not delete a live key after a rejected upload');
    assertTrue(strpos($body, "\$claims->uploadCollision === 'overwrite'") !== false, 'passes the collision policy to conditional completion');
});

// ── licenseInfo() (LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md §5/§C) ────────────
// No HTTP route backs this method — FluxFilesController::license() requires a
// Claims object built from an authenticated request, which a scheduled Artisan
// command or a host app's own admin panel doesn't have. licenseInfo() is a direct
// in-process call instead, so the regression to guard is "does it actually return
// what calling the core LicenseManager directly would", not an HTTP contract.

test('licenseInfo() returns exactly what a direct \\FluxFiles\\LicenseManager::fromEnv()->info() call would', function () {
    $mgr = new FluxFilesManager();
    $expected = \FluxFiles\LicenseManager::fromEnv()->info();
    $actual = $mgr->licenseInfo();
    assertEqual($expected, $actual, 'licenseInfo() must mirror the direct core call, not reimplement or wrap it differently');
    assertEqual('free', $actual['edition'] ?? null, 'no FLUXFILES_LICENSE_KEY in this env -> free edition');
    foreach (['status', 'enforcement', 'modules', 'expires', 'days_left', 'updates_allowed'] as $key) {
        assertTrue(array_key_exists($key, $actual), "info() shape includes '{$key}'");
    }
});

test('licenseInfo() picks up FLUXFILES_LICENSE_KEY from the environment, still matching a direct call', function () {
    $prev = $_ENV['FLUXFILES_LICENSE_KEY'] ?? null;
    $_ENV['FLUXFILES_LICENSE_KEY'] = 'not-a-real-license-key';
    try {
        $mgr = new FluxFilesManager();
        assertEqual(\FluxFiles\LicenseManager::fromEnv()->info(), $mgr->licenseInfo(), 'still mirrors fromEnv()->info() once an env key is present');
    } finally {
        if ($prev === null) { unset($_ENV['FLUXFILES_LICENSE_KEY']); } else { $_ENV['FLUXFILES_LICENSE_KEY'] = $prev; }
    }
});

test('every successful write is audited + webhooked centrally, through AuditPolicy (not per-route)', function () {
    // Like core's index.php: ONE hook in ok(). A route can no longer forget to log
    // (legal-hold / audit-purge / version-restore / metadata-delete / ocr / chunk-lifecycle
    // each did, once, when every route called logAudit() by hand).
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    $ok = substr($ctrlSrc, strpos($ctrlSrc, 'private function ok('), 500);
    assertTrue(strpos($ok, 'recordWrite(') !== false, 'ok() records the write');
    assertEqual(0, preg_match('/\$this->(logAudit|dispatchWebhook)\(/', $ctrlSrc), 'no route logs or dispatches by hand');
    // Every mutating route in the route file resolves to a named action (not "unknown").
    $routes = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    preg_match_all("#Route::(?:post|put|delete)\\('([a-z0-9/_-]+)'#", $routes, $m);
    foreach ($m[1] as $route) {
        $action = \FluxFiles\AuditPolicy::resolveAction('/api/fm/' . $route);
        assertTrue($action !== 'unknown', "POST/PUT/DELETE /{$route} maps to an audit action");
    }
});

// ══ 2026-10-23 adapter audit ══════════════════════════════════════════════
// Behavioural tests drive the real controller/middleware through the minimal
// Illuminate HTTP stubs in laravel-http-stubs.php (this smoke runs on core's
// autoload alone — CI never installs illuminate/* for it).
require_once __DIR__ . '/laravel-http-stubs.php';
require_once __DIR__ . '/../src/ClaimsResolver.php';
require_once __DIR__ . '/../src/Http/Middleware/FluxFilesAuth.php';
require_once __DIR__ . '/../src/Http/Controllers/FluxFilesController.php';

use FluxFiles\Laravel\Http\Controllers\FluxFilesController;
use FluxFiles\Laravel\Http\Middleware\FluxFilesAuth;
use Illuminate\Http\Request;

$ffTmp = sys_get_temp_dir() . '/ff_laravel_smoke_' . getmypid();
@mkdir($ffTmp . '/disk', 0775, true);
@mkdir($ffTmp . '/state', 0775, true);
$GLOBALS['LARAVEL_CONFIG'] += [
    'fluxfiles.storage_path'    => $ffTmp . '/state',
    'fluxfiles.storage_backend' => 'json',
    'fluxfiles.disks'           => ['local' => ['driver' => 'local', 'root' => $ffTmp . '/disk', 'url' => '/storage']],
    'fluxfiles.base_path'       => realpath(__DIR__ . '/../../core'),
    'fluxfiles.allowed_origins' => [],
];
register_shutdown_function(static function () use ($ffTmp) {
    exec('rm -rf ' . escapeshellarg($ffTmp));
});

/** Run $fn with config keys temporarily overridden; resets the manager singleton too. */
function withConfig(array $over, callable $fn)
{
    $prev = $GLOBALS['LARAVEL_CONFIG'];
    $GLOBALS['LARAVEL_CONFIG'] = array_replace($prev, $over);
    unset($GLOBALS['LARAVEL_MANAGER']);
    try {
        return $fn();
    } finally {
        $GLOBALS['LARAVEL_CONFIG'] = $prev;
        unset($GLOBALS['LARAVEL_MANAGER']);
    }
}

/** A fresh rate-limit state, so one test's buckets never leak into the next. */
function freshController(): FluxFilesController
{
    @unlink($GLOBALS['LARAVEL_CONFIG']['fluxfiles.storage_path'] . '/rate_limit.json');
    return new FluxFilesController();
}

function bearer(array $overrides, string $user = 'u1'): array
{
    return ['Authorization' => 'Bearer ' . (new FluxFilesManager())->token($user, $overrides)];
}

/** @return array<string,mixed> */
function decodeJwt(string $token): array
{
    return (array) \FluxFiles\JwtCompat::decode($token, $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret']);
}

final class SmokeTenantResolver implements \FluxFiles\Laravel\ClaimsResolver
{
    public function resolve(\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        return ['perms' => ['read'], 'prefix' => 'tenants/' . $user->getAuthIdentifier(), 'claims' => ['show_hidden' => false]];
    }
}

// ── A-N2: unknown role / edition ───────────────────────────────────────────
test('A-N2: an unknown/typo role or edition throws instead of failing open to the defaults', function () {
    $mgr = new FluxFilesManager();
    foreach ([['role' => 'viewr'], ['edition' => 'enterprize']] as $bad) {
        try {
            $mgr->token('u', $bad);
            throw new \RuntimeException('should throw for ' . json_encode($bad));
        } catch (\InvalidArgumentException $e) {
            assertTrue(stripos($e->getMessage(), 'unknown') !== false, 'names the problem');
        }
    }
    try {
        $mgr->tokenWithByob('u', [], ['role' => 'root']);
        throw new \RuntimeException('BYOB builder should throw too');
    } catch (\InvalidArgumentException $e) {
    }
    // Valid names (any case), empty, and edition "free" (a no-op preset) still mint.
    $p = decodeJwt($mgr->token('u', ['role' => 'Viewer', 'edition' => 'free']));
    assertEqual(['read'], (array) $p['perms'], 'case-insensitive role still applies');
    decodeJwt($mgr->token('u', ['role' => '', 'edition' => '']));
});

// ── A-H3: per-user claims resolver ─────────────────────────────────────────
test('A-H3: tokenForUser() applies the configured resolver; explicit overrides win; claims maps merge', function () {
    $GLOBALS['LARAVEL_AUTH_USER'] = new StubUser(7);
    try {
        withConfig(['fluxfiles.claims_resolver' => SmokeTenantResolver::class], function () {
            $mgr = new FluxFilesManager();
            assertTrue($mgr->hasClaimsResolver(), 'class-string resolver detected');
            $p = decodeJwt($mgr->tokenForUser());
            assertEqual('tenants/7', $p['prefix'], 'resolver prefix applied (was the empty config default)');
            assertEqual(['read'], (array) $p['perms'], 'resolver perms applied (was rwd)');

            $p = decodeJwt($mgr->tokenForUser(['prefix' => 'tenants/7/album', 'claims' => ['allow_zip' => false]]));
            assertEqual('tenants/7/album', $p['prefix'], 'component/tag override wins over the resolver');
            assertEqual(false, $p['allow_zip'] ?? null, 'override claims map applied');
            assertEqual(false, $p['show_hidden'] ?? null, 'resolver claims map kept (merged, not replaced)');
        });
        // Runtime registration wins over config and survives config:cache.
        $mgr = new FluxFilesManager();
        assertTrue(!$mgr->hasClaimsResolver(), 'no resolver by default');
        $mgr->resolveClaimsUsing(fn ($u) => ['prefix' => 'rt/' . $u->getAuthIdentifier()]);
        assertEqual('rt/7', decodeJwt($mgr->tokenForUser())['prefix'], 'runtime resolver');
        $mgr->resolveClaimsUsing(fn ($u) => null);
        try {
            $mgr->tokenForUser();
            throw new \RuntimeException('a non-array resolver result must fail closed');
        } catch (\RuntimeException $e) {
            assertTrue(strpos($e->getMessage(), 'must return an array') !== false, 'fails closed: ' . $e->getMessage());
        }
        withConfig(['fluxfiles.claims_resolver' => 12345], function () {
            try {
                (new FluxFilesManager())->tokenForUser();
                throw new \RuntimeException('should throw');
            } catch (\RuntimeException $e) {
                assertTrue(strpos($e->getMessage(), 'claims_resolver must be') !== false, 'a misconfigured resolver is not "no resolver"');
            }
        });
    } finally {
        unset($GLOBALS['LARAVEL_AUTH_USER']);
    }
});

test('A-H3: refresh ticket round-trips the rendered overrides and is bound to the user', function () {
    $mgr = new FluxFilesManager();
    $alice = new StubUser(1);
    $ticket = $mgr->refreshTicket($alice, ['prefix' => 'a/', 'perms' => ['read']]);
    assertEqual(['prefix' => 'a/', 'perms' => ['read']], $mgr->verifyRefreshTicket($alice, $ticket), 'round trip');
    foreach ([
        'other user' => fn () => $mgr->verifyRefreshTicket(new StubUser(2), $ticket),
        'tampered'   => fn () => $mgr->verifyRefreshTicket($alice, substr($ticket, 0, -2) . 'xx'),
        'forged'     => fn () => $mgr->verifyRefreshTicket($alice, rtrim(strtr(base64_encode('{"sub":"1","exp":9999999999,"o":{"perms":["read","write","delete"]}}'), '+/', '-_'), '=') . '.' . explode('.', $ticket)[1]),
        'malformed'  => fn () => $mgr->verifyRefreshTicket($alice, 'nope'),
        'expired'    => function () use ($mgr, $alice) {
            $key = new \ReflectionMethod($mgr, 'ticketKey');
            $body = rtrim(strtr(base64_encode('{"sub":"1","exp":' . (time() - 1) . ',"o":{}}'), '+/', '-_'), '=');
            $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key->invoke($mgr), true)), '+/', '-_'), '=');
            $mgr->verifyRefreshTicket($alice, $body . '.' . $mac);
        },
    ] as $what => $fn) {
        try {
            $fn();
            throw new \LogicException("{$what}: accepted");
        } catch (\RuntimeException $e) {
        }
    }
});

test('A-H3/A-N11: GET /token never mints the bare config defaults; no-store; Origin-checked', function () {
    $user = new StubUser(9);
    $call = function (array $headers = [], array $cfg = []) use ($user) {
        return withConfig($cfg, function () use ($headers, $user) {
            $resp = (new FluxFilesController())->token(new Request('GET', [], $headers, $user));
            return [$resp->getStatusCode(), $resp->getData(), $resp->headers];
        });
    };

    // No ticket, no resolver: refuse (used to be 200 + an unscoped rwd token).
    [$st, $body, $h] = $call();
    assertEqual(403, $st, 'no policy → refused');
    assertEqual('token_refresh_unconfigured', $body['error_code'] ?? null, 'error code');
    assertTrue(strpos($h['cache-control'] ?? '', 'no-store') !== false, 'no-store on errors too');

    // With the component's ticket: exactly the rendered overrides.
    $ticket = (new FluxFilesManager())->refreshTicket($user, ['prefix' => 'tag/9', 'perms' => ['read']]);
    [$st, $body, $h] = $call(['X-FluxFiles-Refresh-Ticket' => $ticket]);
    assertEqual(200, $st, 'ticket refresh ok');
    $p = decodeJwt($body['data']['token']);
    assertEqual('tag/9', $p['prefix'], 'same prefix as the page');
    assertEqual(['read'], (array) $p['perms'], 'same perms as the page');
    assertTrue(strpos($h['cache-control'] ?? '', 'no-store') !== false, 'token response is no-store (A-N11)');

    // Someone else's ticket → refused.
    $foreign = (new FluxFilesManager())->refreshTicket(new StubUser(10), ['prefix' => '']);
    [$st] = $call(['X-FluxFiles-Refresh-Ticket' => $foreign]);
    assertEqual(403, $st, "another user's ticket is refused");

    // Resolver configured: its policy.
    [$st, $body] = $call([], ['fluxfiles.claims_resolver' => SmokeTenantResolver::class]);
    assertEqual(200, $st, 'resolver refresh ok');
    assertEqual('tenants/9', decodeJwt($body['data']['token'])['prefix'], 'resolver prefix');

    // Cross-site caller (cookie-only route) → refused before minting.
    [$st, $body] = $call(['X-FluxFiles-Refresh-Ticket' => $ticket, 'Origin' => 'https://evil.example']);
    assertEqual(403, $st, 'cross-origin refresh refused');
    assertEqual('origin_denied', $body['error_code'] ?? null, 'origin_denied');
});

test('A-H3: FluxFilesAuth — no Bearer: writes refused, reads need a resolver + same site', function () {
    $user = new StubUser(5);
    $seen = null;
    $next = function ($req) use (&$seen) {
        $seen = $req;
        return response()->json(['data' => 'ok'], 200);
    };
    $run = function (Request $req, array $cfg = []) use ($next, &$seen) {
        $seen = null;
        return withConfig($cfg, fn () => (new FluxFilesAuth())->handle($req, $next));
    };
    $resolver = ['fluxfiles.claims_resolver' => SmokeTenantResolver::class];

    // Cookie-only POST: used to be auto-minted a full-power token → CSRF-able.
    $r = $run(new Request('POST', [], [], $user), $resolver);
    assertEqual(401, $r->getStatusCode(), 'state-changing request without Bearer refused');
    assertTrue($seen === null, 'controller never reached');

    // Cookie-only GET without a resolver: used to get the config defaults.
    $r = $run(new Request('GET', [], [], $user));
    assertEqual(401, $r->getStatusCode(), 'no resolver → no implicit token');

    // Cross-site GET (Origin or Sec-Fetch-Site) with a resolver → refused.
    assertEqual(403, $run(new Request('GET', [], ['Origin' => 'https://evil.example'], $user), $resolver)->getStatusCode(), 'foreign Origin');
    assertEqual(403, $run(new Request('GET', [], ['Sec-Fetch-Site' => 'cross-site'], $user), $resolver)->getStatusCode(), 'cross-site fetch');

    // Same-site GET with a resolver → resolver-scoped token injected.
    $r = $run(new Request('GET', [], ['Sec-Fetch-Site' => 'same-origin'], $user), $resolver);
    assertEqual(200, $r->getStatusCode(), 'same-site read allowed');
    assertEqual('tenants/5', decodeJwt((string) $seen->bearerToken())['prefix'], 'minted from the resolver, not the defaults');

    // A Bearer passes through untouched (any method; the JWT is the credential).
    $hdr = bearer(['perms' => ['read', 'write']]);
    $r = $run(new Request('POST', [], $hdr + ['Origin' => 'https://other.example'], $user));
    assertEqual(200, $r->getStatusCode(), 'Bearer request passes');
    assertEqual(substr($hdr['Authorization'], 7), $seen->bearerToken(), 'Bearer not replaced');

    // Pure origin decision: allow-listed origin + `none` (typed URL) pass.
    assertTrue(FluxFilesAuth::isSameSiteRequest('https://cms.example', '', 'https://app.example.com', ['https://cms.example']), 'allow-listed Origin');
    assertTrue(FluxFilesAuth::isSameSiteRequest('', 'none', 'https://app.example.com', []), 'typed URL');
    assertTrue(!FluxFilesAuth::isSameSiteRequest('', 'same-site', 'https://app.example.com', []), 'sibling subdomain is not same-origin');
});

test('A-H3: lang routes skip FluxFilesAuth (the UI fetches them without a Bearer); blade sends the ticket header', function () {
    $routes = (string) file_get_contents(__DIR__ . '/../routes/fluxfiles.php');
    assertEqual(2, substr_count($routes, "withoutMiddleware(['auth', \\FluxFiles\\Laravel\\Http\\Middleware\\FluxFilesAuth::class])"), 'both lang routes');
    $blade = (string) file_get_contents(__DIR__ . '/../src/Views/components/fluxfiles.blade.php');
    assertTrue(strpos($blade, "'X-FluxFiles-Refresh-Ticket': @json(\$refreshTicket)") !== false, 'default refresh hook sends the ticket');
    assertTrue(strpos($blade, 'refreshTicketForUser($overrides)') !== false, 'ticket carries the tag overrides');
});

// ── A-M3: audit (and webhook) keys are scoped ──────────────────────────────
test('A-M3: audit entries are logged under the scoped key, so the tenant sees its own', function () use ($ffTmp) {
    $secret = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret'];
    $ctrl = freshController();
    $hdr = bearer(['prefix' => 'tenant-a', 'perms' => ['read', 'write', 'audit']], 't1');
    // A write whose body names the key RELATIVE to the tenant, and one already scoped (idempotent).
    $ctrl->mkdir(new Request('POST', ['disk' => 'local', 'path' => 'docs/a'], $hdr, null, 'https://app.example.com', [], 'api/fm/mkdir'));
    $ctrl->mkdir(new Request('POST', ['disk' => 'local', 'path' => 'tenant-a/docs/b'], $hdr, null, 'https://app.example.com', [], 'api/fm/mkdir'));
    $claims = \FluxFiles\JwtMiddleware::handle(substr($hdr['Authorization'], 7), $secret);
    $repoProp = new \ReflectionProperty($ctrl, 'metaRepo');
    $keys = array_column(array_filter($repoProp->getValue($ctrl)->readAudit('local'), fn ($r) => $r['action'] === 'mkdir' && $r['user_id'] === 't1'), 'file_key');
    assertTrue(in_array('tenant-a/docs/a', $keys, true), 'relative key scoped: ' . json_encode($keys));
    assertTrue(in_array('tenant-a/docs/b', $keys, true), 'scoped key not double-prefixed');
    $listed = (new \FluxFiles\AuditLogStorage($repoProp->getValue($ctrl), ['local']))->list(100, 0, null, $claims);
    $rows = $listed['entries'] ?? $listed['data'] ?? $listed;
    assertEqual(2, count(array_filter((array) $rows, fn ($r) => is_array($r) && ($r['action'] ?? '') === 'mkdir' && str_starts_with((string) ($r['file_key'] ?? ''), 'tenant-a/'))), "the tenant's own audit view shows both");
});

// ── A-N5: search results are tenant-relative ───────────────────────────────
test('A-N5: search/search-folders strip the tenant prefix from returned keys', function () {
    $ctrl = freshController();
    $repoProp = new \ReflectionProperty($ctrl, 'metaRepo');
    $repo = $repoProp->getValue($ctrl);
    @mkdir($GLOBALS['LARAVEL_CONFIG']['fluxfiles.disks']['local']['root'] . '/tenant-s/sub', 0775, true);
    file_put_contents($GLOBALS['LARAVEL_CONFIG']['fluxfiles.disks']['local']['root'] . '/tenant-s/report.pdf', 'x');
    $repo->save('local', 'tenant-s/report.pdf', ['title' => 'Quarterly', 'uploaded_by' => 'u1']);
    $repo->trackDir('local', 'tenant-s/sub');
    $hdr = bearer(['prefix' => 'tenant-s', 'perms' => ['read']]);

    $rows = $ctrl->search(new Request('GET', ['disk' => 'local', 'q' => 'Quarterly'], $hdr))->getData()['data'];
    assertEqual(['report.pdf'], array_column($rows, 'file_key'), 'file_key relative');
    $dirs = $ctrl->searchFolders(new Request('GET', ['disk' => 'local', 'q' => 'sub'], $hdr))->getData()['data'];
    assertEqual(['sub'], array_column($dirs, 'dir_key'), 'dir_key relative');
});

// ── A-N4: force_upload parsed as a boolean ─────────────────────────────────
test('A-N4: force_upload="false" is false (dedupe still applies)', function () use ($ffTmp) {
    $ctrl = freshController();
    $hdr = bearer(['perms' => ['read', 'write'], 'prefix' => 'up-t', 'dedupe_uploads' => true, 'rate_write' => 50]);
    $mk = function (string $name) use ($ffTmp) {
        $tmp = tempnam($ffTmp, 'up');
        file_put_contents($tmp, 'same-bytes-for-dedupe');
        return new class($tmp, $name) {
            public function __construct(private string $tmp, private string $name) {}
            public function getClientOriginalName(): string { return $this->name; }
            public function getMimeType(): string { return 'text/plain'; }
            public function getRealPath(): string { return $this->tmp; }
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getSize(): int { return (int) filesize($this->tmp); }
        };
    };
    $up = fn (string $name, array $extra) => $ctrl->upload(new Request('POST', ['disk' => 'local', 'path' => ''] + $extra, $hdr, null, 'https://app.example.com', ['file' => $mk($name)]))->getData();
    $first = $up('one.txt', []);
    assertTrue(empty($first['error']), 'first upload ok: ' . json_encode($first));
    $second = $up('two.txt', ['force_upload' => 'false']);
    assertEqual(true, $second['data']['duplicate'] ?? null, '"false" must not force past dedupe: ' . json_encode($second));
});

// ── A-M4: URL import — write perm before the fetch + its own bucket ────────
test('A-M4: import-url checks write before fetching and has its own bucket (import_rate_limit)', function () {
    $ctrl = freshController();
    $ro = $ctrl->importUrl(new Request('POST', ['url' => 'http://127.0.0.1/x', 'disk' => 'local'], bearer(['perms' => ['read'], 'allow_url_import' => true])));
    assertEqual(403, $ro->getStatusCode(), 'read-only token refused');
    assertEqual('permission_denied', $ro->getData()['error_code'] ?? null, 'refused on the perm, not by the importer');

    $ctrl = freshController();
    $hdr = bearer(['perms' => ['read', 'write'], 'allow_url_import' => true, 'import_rate_limit' => 1, 'rate_write' => 50]);
    $req = fn () => new Request('POST', ['url' => 'http://127.0.0.1/x', 'disk' => 'local'], $hdr);
    $ctrl->importUrl($req());
    $second = $ctrl->importUrl($req());
    assertEqual(429, $second->getStatusCode(), 'import bucket honours the import_rate_limit claim');
});

// ── A-M2 / A-N3: dedicated + write buckets ─────────────────────────────────
test('A-M2: /usage and /duplicates take the tight usage_refresh bucket (2/min)', function () {
    foreach (['usage', 'duplicates'] as $route) {
        $ctrl = freshController();
        $hdr = bearer(['perms' => ['read'], 'rate_read' => 100]);
        $codes = [];
        for ($i = 0; $i < 3; $i++) {
            $codes[] = $ctrl->{$route}(new Request('GET', ['disk' => 'local'], $hdr))->getStatusCode();
        }
        assertEqual(429, $codes[2], "{$route}: third call in a minute is limited (" . json_encode($codes) . ')');
        assertTrue($codes[0] !== 429, "{$route}: first call not limited");
    }
});

test('A-N3: presign PUT/POST and c2pa verify count against the WRITE bucket', function () {
    $hdr = bearer(['perms' => ['read', 'write'], 'rate_read' => 100, 'rate_write' => 1]);
    $ctrl = freshController();
    $put = fn () => $ctrl->presign(new Request('POST', ['disk' => 'local', 'path' => 'a.txt', 'method' => 'PUT', 'ttl' => 60], $hdr))->getStatusCode();
    $put();
    assertEqual(429, $put(), 'second upload presign hits rate_write=1');

    $ctrl = freshController();
    $get = fn () => $ctrl->presign(new Request('POST', ['disk' => 'local', 'path' => 'a.txt', 'method' => 'GET', 'ttl' => 60], $hdr))->getStatusCode();
    $get();
    assertTrue($get() !== 429, 'a download presign stays on the read bucket');

    $ctrl = freshController();
    $c2pa = fn () => $ctrl->c2pa(new Request('POST', ['disk' => 'local', 'path' => 'a.jpg'], $hdr))->getStatusCode();
    $c2pa();
    assertEqual(429, $c2pa(), 'c2pa verify is a write (POST), as in core');
});

// ── A-M1 (retired): the proxy /img used to 415 a watermark scope; it now overlays ──
test('A-M1: /img composites the overlay watermark for a watermarked token instead of refusing it', function () use ($ffTmp) {
    $root = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.disks']['local']['root'];
    $png = $root . '/wm.png';
    $im = imagecreatetruecolor(200, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 40, 40));
    imagepng($im, $png);
    $secretKey = $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret'];
    // Mint exactly as the adapter does: watermark_enabled forwarded -> the scope carries `wm`.
    $claims = \FluxFiles\JwtMiddleware::handle((new FluxFilesManager())->token('u1', [
        'watermark_enabled' => true, 'watermark_type' => 'text', 'watermark_text' => 'CONFIDENTIAL',
    ]), $secretKey);
    assertTrue($claims->watermark !== null, 'the minted token carries the watermark claim');
    $mint = static fn (?array $wm) => \FluxFiles\ImageToken::mint('local', 'wm.png', 'u1', 300, $secretKey, 2000, 80, $wm, false);
    // img() emits raw bytes and exit()s, so run it in a child process.
    $run = function (string $token) use ($ffTmp): string {
        $script = $ffTmp . '/img-child.php';
        file_put_contents($script, '<?php
            $GLOBALS["LARAVEL_CONFIG"] = ' . var_export($GLOBALS['LARAVEL_CONFIG'], true) . ';
            function config($k, $d = null) { return $GLOBALS["LARAVEL_CONFIG"][$k] ?? $d; }
            function env($k, $d = null) { return $d; }
            require ' . var_export(getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php', true) . ';
            require ' . var_export(__DIR__ . '/laravel-http-stubs.php', true) . ';
            require ' . var_export(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php', true) . ';
            (new FluxFiles\Laravel\Http\Controllers\FluxFilesController())->img(
                new Illuminate\Http\Request("GET", ["token" => ' . var_export($token, true) . ', "format" => "webp", "width" => 100], ["Accept" => "image/webp"])
            );');
        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    };
    $wmOut = $run($mint(['enabled' => true, 'type' => 'text', 'text' => 'CONFIDENTIAL']));
    assertTrue(strpos($wmOut, 'Watermarked previews are not available') === false, '415 branch is gone');
    assertTrue(substr($wmOut, 0, 4) === 'RIFF' && substr($wmOut, 8, 4) === 'WEBP', 'an image is served, got (hex): ' . bin2hex(substr($wmOut, 0, 24)));
    $plainOut = $run($mint(null));
    assertTrue(substr($plainOut, 0, 4) === 'RIFF', 'control: unwatermarked token also served');
    assertTrue($wmOut !== $plainOut, 'the watermarked bytes differ from the plain transform (the overlay was composited)');
});

// ── A-N6: UI page headers ──────────────────────────────────────────────────
test('A-N6: /public/index.html sends Referrer-Policy no-referrer and frame-ancestors', function () {
    $h = withConfig(['fluxfiles.allowed_origins' => ['https://cms.example']], fn () => (new FluxFilesController())->publicIndex()->headers);
    assertEqual('no-referrer', $h['referrer-policy'] ?? null, 'Referrer-Policy');
    assertEqual("frame-ancestors 'self' https://cms.example", $h['content-security-policy'] ?? null, 'falls back to allowed_origins');
    $h = withConfig(['fluxfiles.frame_ancestors' => "'self' https://a.example"], fn () => (new FluxFilesController())->publicIndex()->headers);
    assertEqual("frame-ancestors 'self' https://a.example", $h['content-security-policy'] ?? null, 'explicit list wins');
    $h = withConfig(['fluxfiles.frame_ancestors' => "x; script-src *"], fn () => (new FluxFilesController())->publicIndex()->headers);
    assertTrue(!isset($h['content-security-policy']), 'an unsafe value is not emitted');
});

// ── B2: version purger/mover independent of the acting token's claim ──────
test('B2: version purger + mover are wired whenever versioning is installed+licensed (shared factory); only the keeper is claim-gated', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../core/api/FileManagerFactory.php');
    $start = strpos($src, "if (ModuleRegistry::installed('versioning')");
    assertTrue($start !== false, 'outer gate is installed+licensed only');
    $block = substr($src, $start, strpos($src, '// Virus scan (paid module)', $start) - $start);
    $cond = substr($block, 0, strpos($block, '{'));
    assertTrue(strpos($cond, 'allowVersioning') === false, 'outer condition does not test the claim');
    $keeperGate = strpos($block, 'if ($claims->allowVersioning) {');
    assertTrue($keeperGate !== false && strpos($block, 'setVersionKeeper(') > $keeperGate, 'keeper stays claim-gated');
    $keeperEnd = strpos($block, '}', strpos($block, '});', $keeperGate) + 3);
    assertTrue(strpos($block, 'setVersionPurger(') > $keeperEnd, 'purger is outside the claim gate');
    assertTrue(strpos($block, 'setVersionMover(') > $keeperEnd, 'mover is outside the claim gate');
});

// ── N3: audit purge gets the repository the controller already has ─────────
test('N3: auditPurge passes the MetadataRepositoryInterface (works in DB mode), nothing assumes StorageMetadataHandler', function () {
    $src = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(strpos($src, '$module->purge($this->metaRepo, $claims, $disk, $before)') !== false, 'purge() gets $this->metaRepo');
    $prop = new \ReflectionProperty(FluxFilesController::class, 'metaRepo');
    assertEqual(\FluxFiles\MetadataRepositoryInterface::class, (string) $prop->getType(), 'typed as the interface');
    assertEqual(1, substr_count($src, 'new StorageMetadataHandler('), 'StorageMetadataHandler only built as the json backend');
    assertTrue(!preg_match('/StorageMetadataHandler\s+\$/', $src), 'no parameter/property typed StorageMetadataHandler');
});

// ══ 2026-10-24 live Laravel paid-module rig (core/tests/modules-live/laravel) ══
// Each of these broke a real `composer require fluxfiles/laravel` install on Linux
// and was invisible to the macOS-hosted adapter e2e.

/** Read the local disk's audit.jsonl rows (the smoke's JSON backend). */
function smokeAuditRows(string $root): array
{
    $f = $root . '/_fluxfiles/audit.jsonl';
    // Rows are {ts, action, context:{user_id, file_key, detail…}} — flattened here.
    return is_file($f) ? array_values(array_filter(array_map(
        static fn ($l) => is_array($r = json_decode($l, true)) ? ['action' => $r['action'] ?? ''] + (array) ($r['context'] ?? []) : null,
        preg_split('/\R/', (string) file_get_contents($f)) ?: []
    ))) : [];
}

test('L1: every src/ class autoloads on a CASE-SENSITIVE filesystem (the <x-fluxfiles> component was unloadable on Linux)', function () {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
    $src = realpath(__DIR__ . '/../src');
    $psr4 = $composer['autoload']['psr-4'] ?? [];
    assertEqual(['FluxFiles\\Laravel\\' => 'src/'], $psr4, 'psr-4 root');
    $classmapDirs = array_map(static fn ($d) => realpath(__DIR__ . '/../' . $d), (array) ($composer['autoload']['classmap'] ?? []));
    // Exact-case existence check: walk each segment with scandir() (is_file() is
    // case-insensitive on the default macOS filesystem, which is how this hid).
    $existsExactCase = static function (string $base, string $relative): bool {
        $dir = $base;
        foreach (explode('/', $relative) as $seg) {
            if (!is_dir($dir) || !in_array($seg, scandir($dir) ?: [], true)) {
                return false;
            }
            $dir .= '/' . $seg;
        }
        return true;
    };
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
    $checked = 0;
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (!preg_match('/^namespace\s+([^;]+);/m', $code, $ns) || !preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $code, $cl)) {
            continue;
        }
        $fqcn = trim($ns[1]) . '\\' . $cl[1];
        assertTrue(str_starts_with($fqcn, 'FluxFiles\\Laravel\\'), "$fqcn under the psr-4 root");
        $expected = str_replace('\\', '/', substr($fqcn, strlen('FluxFiles\\Laravel\\'))) . '.php';
        $inClassmap = false;
        foreach ($classmapDirs as $d) {
            if ($d && str_starts_with(realpath($file->getPathname()), $d . '/')) {
                $inClassmap = true;
            }
        }
        assertTrue($existsExactCase($src, $expected) || $inClassmap, "$fqcn: neither src/$expected (exact case) nor a classmap entry");
        $checked++;
    }
    assertTrue($checked >= 8, "scanned the adapter's classes ($checked)");
    assertTrue(in_array(realpath(__DIR__ . '/../src/Views/components'), $classmapDirs, true), 'the Blade component dir is classmapped');
});

test('L2: /fluxfiles.js resolves on a default Composer install (core ships no fluxfiles.js) and the bundled copy matches packages/sdk', function () use ($ffTmp) {
    $bundled = realpath(__DIR__ . '/../resources/fluxfiles.js');
    assertTrue($bundled !== false, 'adapter ships resources/fluxfiles.js');
    @mkdir($ffTmp . '/core-split', 0775, true);   // a core install: no fluxfiles.js, no ../sdk sibling
    assertEqual($bundled, realpath((string) FluxFilesController::resolveSdkPath($ffTmp . '/core-split')), 'falls back to the bundled copy');
    file_put_contents($ffTmp . '/core-split/fluxfiles.js', '/* operator copy */');
    assertEqual($ffTmp . '/core-split/fluxfiles.js', FluxFilesController::resolveSdkPath($ffTmp . '/core-split'), 'an operator copy in the base path wins');
    @unlink($ffTmp . '/core-split/fluxfiles.js');
    $sdk = __DIR__ . '/../../sdk/fluxfiles.js';
    if (is_file($sdk)) {   // monorepo: keep the shipped copy in lock-step with the SDK source
        assertTrue(hash_file('sha256', $sdk) === hash_file('sha256', $bundled), 'packages/laravel/resources/fluxfiles.js is stale — cp packages/sdk/fluxfiles.js packages/laravel/resources/');
    }
    $resp = withConfig(['fluxfiles.base_path' => $ffTmp . '/core-split'], fn () => (new FluxFilesController())->sdkJs());
    assertEqual(200, $resp->getStatusCode(), 'sdkJs() serves it');
    assertTrue(str_contains((string) $resp->content, 'FluxFiles'), 'SDK body');
});

test('L3: /public/index.html carries the translations (embedded UI rendered raw i18n keys without them)', function () {
    $html = (string) (new FluxFilesController())->publicIndex()->content;
    assertTrue(str_contains($html, 'window.__FM_LOCALE__ = { locale: "en"'), 'English injected by default');
    assertTrue(!str_contains($html, "messages: {} }"), 'the empty placeholder is replaced');
    assertTrue(preg_match('/"new_folder":"New folder"/', $html) === 1, 'real labels present');
    $vi = withConfig(['fluxfiles.locale' => 'vi'], fn () => (string) (new FluxFilesController())->publicIndex()->content);
    assertTrue(str_contains($vi, '<html lang="vi" dir="ltr" translate="no"') && str_contains($vi, 'locale: "vi"'), 'fluxfiles.locale picks the locale (and the <html> tag follows)');
    $bad = FluxFilesController::injectLocale('<html lang="en"><script>x</script>', __DIR__ . '/nonexistent-lang', 'en');
    assertEqual('<html lang="en"><script>x</script>', $bad, 'no lang dir → page untouched');
});

test('L4: FLUXFILES_* server env survives config:cache — captured into config, re-seeded into $_ENV, never overwriting', function () {
    $_ENV['FLUXFILES_ZZ_SMOKE_CAPTURE'] = 'yes';
    $_ENV['ZZ_SMOKE_NOT_OURS'] = 'no';
    $cfg = require __DIR__ . '/../config/fluxfiles.php';
    unset($_ENV['FLUXFILES_ZZ_SMOKE_CAPTURE'], $_ENV['ZZ_SMOKE_NOT_OURS']);
    assertEqual('yes', $cfg['server_env']['FLUXFILES_ZZ_SMOKE_CAPTURE'] ?? null, 'FLUXFILES_* captured');
    assertTrue(!array_key_exists('ZZ_SMOKE_NOT_OURS', $cfg['server_env']), 'non-FLUXFILES_ env not captured');

    $_ENV['FLUXFILES_ZZ_PRESENT'] = 'real-env';
    $n = FluxFilesManager::seedServerEnv([
        'FLUXFILES_ZZ_SEEDED'  => 'from-cache',
        'FLUXFILES_ZZ_PRESENT' => 'stale-cache',
        'APP_KEY'              => 'must-not-leak',
        'FLUXFILES_ZZ_ARRAY'   => ['nope'],
    ]);
    assertEqual(1, $n, 'one key filled');
    assertEqual('from-cache', $_ENV['FLUXFILES_ZZ_SEEDED'] ?? null, 'absent key seeded');
    assertEqual('real-env', $_ENV['FLUXFILES_ZZ_PRESENT'], 'a real value always wins');
    assertTrue(!isset($_ENV['APP_KEY']) || $_ENV['APP_KEY'] !== 'must-not-leak', 'only FLUXFILES_* keys');
    unset($_ENV['FLUXFILES_ZZ_SEEDED'], $_ENV['FLUXFILES_ZZ_PRESENT']);
    // What this is for: the license gate reads $_ENV, in the adapter AND in core's PublicLinks.php.
    assertTrue(!\FluxFiles\LicenseManager::fromEnv(['FLUXFILES_LICENSE_KEY' => null])->licensed('share'), 'sanity: no key → unlicensed');
    $prov = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    assertTrue(str_contains($prov, "FluxFilesManager::seedServerEnv((array) config('fluxfiles.server_env', []))"), 'provider seeds at register()');
});

test('L5: an upload is audited under the uploaded FILE key, not its destination folder', function () use ($ffTmp) {
    $ctrl = freshController();
    $hdr = bearer(['perms' => ['read', 'write'], 'prefix' => 'aud-up', 'rate_write' => 50]);
    $tmp = tempnam($ffTmp, 'up');
    file_put_contents($tmp, 'audit-me-' . uniqid());
    $file = new class($tmp) {
        public function __construct(private string $tmp) {}
        public function getClientOriginalName(): string { return 'evidence.txt'; }
        public function getMimeType(): string { return 'text/plain'; }
        public function getRealPath(): string { return $this->tmp; }
        public function getError(): int { return UPLOAD_ERR_OK; }
        public function getSize(): int { return (int) filesize($this->tmp); }
    };
    $res = $ctrl->upload(new Request('POST', ['disk' => 'local', 'path' => ''], $hdr, null, 'https://app.example.com', ['file' => $file], 'api/fm/upload'))->getData();
    assertTrue(empty($res['error']), 'upload ok: ' . json_encode($res));
    $rows = array_values(array_filter(smokeAuditRows($ffTmp . '/disk'), static fn ($r) => ($r['action'] ?? '') === 'upload' && ($r['user_id'] ?? '') === 'u1'));
    $keys = array_column($rows, 'file_key');
    assertTrue(in_array('aud-up/evidence.txt', $keys, true), 'file key logged, got ' . json_encode($keys));
    assertTrue(!in_array('aud-up', $keys, true) && !in_array('', $keys, true), 'never the bare folder');
});

test('N11: renaming a missing file is a 404 not_found envelope, not a 500 (core parity)', function () {
    $ctrl = freshController();
    $hdr = bearer(['perms' => ['read', 'write'], 'prefix' => 'rn-missing', 'rate_write' => 50]);
    $res = $ctrl->rename(new Request('POST', ['disk' => 'local', 'path' => 'ghost.txt', 'name' => 'x.txt'], $hdr, null, 'https://app.example.com', [], 'api/fm/rename'));
    assertEqual(404, $res->getStatusCode(), 'status');
    $d = $res->getData();
    assertEqual('not_found', $d['error_code'] ?? null, 'error_code: ' . json_encode($d));
});

test('L6: refused writes leave virus_blocked / dlp_blocked / legal_hold_blocked audit lines (core parity)', function () use ($ffTmp) {
    $ctrl = freshController();
    $claims = \FluxFiles\JwtMiddleware::handle(
        (new FluxFilesManager())->token('refuser', ['perms' => ['read', 'write'], 'prefix' => 'refused']),
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret']
    );
    (new \ReflectionProperty(FluxFilesController::class, 'lastClaims'))->setValue($ctrl, $claims);
    (new \ReflectionProperty(FluxFilesController::class, 'lastRequest'))->setValue($ctrl, new Request('POST', []));
    $error = new \ReflectionMethod(FluxFilesController::class, 'error');
    $error->invoke($ctrl, 'virus', 422, 'virus_detected', ['name' => 'eicar.txt', 'threat' => 'Eicar-Signature']);
    $error->invoke($ctrl, 'pii', 422, 'pii_detected', ['name' => 'card.txt', 'entities' => ['CREDIT_CARD']]);
    $resp = $error->invoke($ctrl, 'held', 403, 'legal_hold_active', ['hold_id' => 'h-1', 'path' => 'refused/held.txt']);
    assertEqual('legal_hold_active', $resp->getData()['error_code'] ?? null, 'response unchanged');
    $mine = array_values(array_filter(smokeAuditRows($ffTmp . '/disk'), static fn ($r) => ($r['user_id'] ?? '') === 'refuser'));
    $by = [];
    foreach ($mine as $r) {
        $by[$r['action']][] = $r;
    }
    assertEqual('refused/eicar.txt', $by['virus_blocked'][0]['file_key'] ?? null, 'virus_blocked, scoped key');
    assertEqual('refused/card.txt', $by['dlp_blocked'][0]['file_key'] ?? null, 'dlp_blocked, scoped key');
    assertEqual('CREDIT_CARD', $by['dlp_blocked'][0]['detail'] ?? null, 'entity TYPE only');
    assertEqual('refused/held.txt', $by['legal_hold_blocked'][0]['file_key'] ?? null, 'legal_hold_blocked');
    assertEqual('h-1', $by['legal_hold_blocked'][0]['detail'] ?? null, 'hold id as detail');
    assertTrue(!isset($by['access_denied']), 'a hold refusal is not ALSO logged as access_denied');
    $error->invoke($ctrl, 'nope', 403, 'permission_denied', []);
    $mine = array_filter(smokeAuditRows($ffTmp . '/disk'), static fn ($r) => ($r['user_id'] ?? '') === 'refuser' && ($r['action'] ?? '') === 'access_denied');
    assertEqual(1, count($mine), 'other 403s still audited as access_denied');
});

test('L7: audit/export errors use the standard envelope (error_code/error_params), not code/params', function () {
    $ctrl = freshController();
    $resp = $ctrl->auditExport(new Request('GET', ['format' => 'ndjson'], bearer(['perms' => ['read']])));
    assertEqual(403, $resp->getStatusCode(), 'no audit perm → 403');
    $body = $resp->getData();
    assertTrue(array_key_exists('data', $body) && $body['data'] === null, 'data:null');
    assertEqual('forbidden', $body['error_code'] ?? null, 'error_code present');
    assertTrue(!array_key_exists('code', $body), 'legacy `code` key gone');
});

// ── proxy-parity fixes: X-Accel encoding, audit names, blocked-write key, 403 codes, asset caching ──
if (!function_exists('request')) {
    /** Current-request stub: tests park a Request in $GLOBALS['SMOKE_REQ']; null otherwise. */
    function request() { return $GLOBALS['SMOKE_REQ'] ?? null; }
}

test('L8: the X-Accel target is percent-encoded per segment by the shared endpoint', function () {
    assertEqual('/internal/a%20b/50%25%20off%3F%23.pdf', \FluxFiles\MediaEndpoint::xaccelUri('/internal/', 'a b/50% off?#.pdf'), 'segments encoded');
    $src = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(!str_contains($src, 'X-Accel-Redirect'), 'the adapter no longer builds the header itself');
    assertTrue(str_contains($src, "FLUXFILES_XACCEL"), 'the X-Accel base still comes from FLUXFILES_XACCEL');
});

test('L9: a code-editor save is audited and webhooked as content_update (the name the UI labels)', function () use ($ffTmp) {
    $ctrl = freshController();
    $prefix = 'cu-' . uniqid();
    $hdr = bearer(['perms' => ['read', 'write'], 'allow_code_edit' => true, 'prefix' => $prefix], 'cu-user');
    @mkdir($ffTmp . '/disk/' . $prefix, 0775, true);
    file_put_contents($ffTmp . '/disk/' . $prefix . '/conf.txt', 'old');
    $res = $ctrl->putContent(new Request('PUT', ['disk' => 'local', 'path' => 'conf.txt', 'content' => 'new'], $hdr, null, 'https://app.example.com', [], 'api/fm/content'))->getData();
    assertTrue(empty($res['error']), 'save ok: ' . json_encode($res));
    $actions = array_column(array_filter(smokeAuditRows($ffTmp . '/disk'), static fn ($r) => ($r['user_id'] ?? '') === 'cu-user'), 'action');
    assertTrue(in_array('content_update', $actions, true), 'content_update logged: ' . json_encode($actions));
    assertTrue(!in_array('content_edit', $actions, true), 'legacy name gone');
    $src = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(!str_contains($src, "'content_edit'"), 'legacy name not in the adapter either');
});

/** A multipart-style request: the fields are form data ($_POST), the body is not JSON. */
class SmokeFormRequest extends Request
{
    public function getContent(): string { return ''; }
    /** @return array<string,mixed> */
    public function post(): array { return $this->all(); }
}

test('L10: a blocked upload into a subfolder is audited under folder/name (core blockedWriteKey)', function () use ($ffTmp) {
    $ctrl = freshController();
    $claims = \FluxFiles\JwtMiddleware::handle(
        (new FluxFilesManager())->token('bw-user', ['perms' => ['read', 'write'], 'prefix' => 'bw']),
        $GLOBALS['LARAVEL_CONFIG']['fluxfiles.secret']
    );
    (new \ReflectionProperty(FluxFilesController::class, 'lastClaims'))->setValue($ctrl, $claims);
    (new \ReflectionProperty(FluxFilesController::class, 'lastRequest'))->setValue($ctrl, new SmokeFormRequest('POST', ['disk' => 'local', 'path' => '/docs/in/']));
    $error = new \ReflectionMethod(FluxFilesController::class, 'error');
    $error->invoke($ctrl, 'virus', 422, 'virus_detected', ['name' => 'eicar.txt', 'threat' => 'X']);
    $error->invoke($ctrl, 'virus', 422, 'virus_detected', ['entry' => 'inner/zip.txt']);   // extract entry stays relative
    $keys = array_column(array_filter(smokeAuditRows($ffTmp . '/disk'), static fn ($r) => ($r['user_id'] ?? '') === 'bw-user' && ($r['action'] ?? '') === 'virus_blocked'), 'file_key');
    assertTrue(in_array('bw/docs/in/eicar.txt', $keys, true), 'folder + name, scoped: ' . json_encode($keys));
    assertTrue(in_array('bw/inner/zip.txt', $keys, true), 'extract entry relative: ' . json_encode($keys));
});

test('L11: 403s carry the codes core uses (disk_denied / permission_denied) and none is codeless', function () {
    $ctrl = freshController();
    $r = $ctrl->saveMetadata(new Request('PUT', ['disk' => 'local', 'key' => 'a.txt'], bearer(['perms' => ['read']])));
    assertEqual(403, $r->getStatusCode(), 'write needed');
    assertEqual('permission_denied', $r->getData()['error_code'] ?? null, 'permission_denied');
    $r = $ctrl->search(new Request('GET', ['disk' => 'nope', 'q' => 'x'], bearer(['perms' => ['read', 'write']])));
    assertEqual(403, $r->getStatusCode(), 'disk refused');
    assertEqual('disk_denied', $r->getData()['error_code'] ?? null, 'disk_denied');
    $src = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(preg_match_all('/new ApiException\((?:[^;]*?),\s*403\s*\)/s', $src, $m) === 0, 'codeless 403 left: ' . json_encode($m[0] ?? []));
});

test('L12: assets are immutable when versioned (?v=), else ETag + a real 304', function () use ($ffTmp) {
    @mkdir($ffTmp . '/core-assets/assets', 0775, true);
    file_put_contents($ffTmp . '/core-assets/assets/fm.js', 'console.log(1);');
    $get = fn (array $q, array $h = []) => withConfig(['fluxfiles.base_path' => $ffTmp . '/core-assets'], fn () => (new FluxFilesController())->asset('fm.js', new Request('GET', $q, $h)));
    $good = substr(md5('console.log(1);'), 0, 10);
    $v = $get(['v' => $good]);
    assertEqual('public, max-age=31536000, immutable', $v->headers['cache-control'] ?? null, 'immutable when v matches the served file');
    // A v that does NOT name the served bytes (stale node mid-deploy, hand-typed) must not pin them.
    assertEqual('public, max-age=300, must-revalidate', $get(['v' => 'abc12345ef'])->headers['cache-control'] ?? null, 'mismatched v is not immutable');
    assertEqual('public, max-age=300, must-revalidate', $get(['v' => 'abc123'])->headers['cache-control'] ?? null, 'junk v is not immutable');
    // Lazy vendor files carry their vendor tree's hash (fm.js FM_VENDOR_VER).
    @mkdir($ffTmp . '/core-assets/assets/vendor/codemirror', 0775, true);
    file_put_contents($ffTmp . '/core-assets/assets/vendor/codemirror/a.js', 'A');
    file_put_contents($ffTmp . '/core-assets/assets/vendor/codemirror/b.css', 'B');
    $tree = substr(md5("a.js\0A\0b.css\0B\0"), 0, 10);
    $vget = fn (string $q) => withConfig(['fluxfiles.base_path' => $ffTmp . '/core-assets'], fn () => (new FluxFilesController())->asset('vendor/codemirror/a.js', new Request('GET', ['v' => $q])));
    assertEqual('public, max-age=31536000, immutable', $vget($tree)->headers['cache-control'] ?? null, 'vendor tree hash is immutable');
    assertEqual('public, max-age=300, must-revalidate', $vget(substr($tree, 1) . '0')->headers['cache-control'] ?? null, 'wrong tree hash is not');
    $plain = $get([]);
    assertEqual(200, $plain->getStatusCode(), '200 without validator');
    $etag = $plain->headers['etag'] ?? '';
    assertTrue($etag !== '', 'ETag sent');
    assertEqual('public, max-age=300, must-revalidate', $plain->headers['cache-control'] ?? null, 'revalidating cache');
    $nm = $get([], ['If-None-Match' => $etag]);
    assertEqual(304, $nm->getStatusCode(), 'matching If-None-Match -> 304');
    assertEqual('', (string) $nm->content, '304 has no body');
    assertEqual(200, $get([], ['If-None-Match' => '"stale"'])->getStatusCode(), 'stale ETag -> 200');
});

test('L13: UI html points at fm.min.* when the core ships them, else (or in debug) the sources', function () use ($ffTmp) {
    $dir = $ffTmp . '/core-min/assets';
    @mkdir($dir . '/vendor/alpine', 0775, true);
    foreach (['fm.js', 'fm.css'] as $f) {
        file_put_contents("$dir/$f", "/* $f */");
    }
    $html = '<link href="../assets/fm.css"><script defer src="../assets/fm.js"></script><script defer src="../assets/vendor/alpine/alpine.min.js"></script>';
    $old = FluxFilesController::rewriteUiAssets($html, $dir, false);
    assertTrue(str_contains($old, '../assets/fm.js?v=') && str_contains($old, '../assets/fm.css?v='), 'no min files -> sources');
    foreach (['fm.min.js', 'fm.min.css', 'vendor/alpine/alpine.min.js'] as $f) {
        file_put_contents("$dir/$f", "/* $f */");
    }
    $new = FluxFilesController::rewriteUiAssets($html, $dir, false);
    assertTrue(str_contains($new, '../assets/fm.min.js?v=') && str_contains($new, '../assets/fm.min.css?v='), 'min preferred');
    assertTrue(str_contains($new, 'alpine.min.js?v='), 'alpine versioned');
    $dbg = FluxFilesController::rewriteUiAssets($html, $dir, true);
    assertTrue(str_contains($dbg, '../assets/fm.js?v=') && !str_contains($dbg, 'fm.min'), 'debug -> sources');
});

test('fileManager() really wires the hooks per claim: fail-closed scanners only on the claim, AI tagger only on ai_auto_tag', function () use ($secret) {
    $ctrl = new \FluxFiles\Laravel\Http\Controllers\FluxFilesController();
    $build = new \ReflectionMethod($ctrl, 'fileManager');
    $prop = static fn (object $fm, string $name) => (new \ReflectionProperty(\FluxFiles\FileManager::class, $name))->getValue($fm);
    $claimsFor = static fn (array $o) => \FluxFiles\JwtMiddleware::handle((new FluxFilesManager())->token('wire', $o), $secret);

    $plain = $build->invoke($ctrl, $claimsFor([]));
    assertTrue($prop($plain, 'virusScanner') === null && $prop($plain, 'dlpScanner') === null, 'no scanner without the claim');
    assertTrue($prop($plain, 'quotaManager') !== null, 'quota manager wired');
    assertEqual($secret, $prop($plain, 'streamSecret'), 'stream secret wired (gated-local / img_base URLs)');
    assertTrue($prop($plain, 'aiTagger') === null, 'no AI tagger without ai_auto_tag');

    $scanned = $build->invoke($ctrl, $claimsFor(['allow_virus_scan' => true, 'allow_dlp_scan' => true]));
    assertTrue($prop($scanned, 'virusScanner') instanceof \Closure, 'virus scanner wired on the claim');
    assertTrue($prop($scanned, 'dlpScanner') instanceof \Closure, 'dlp scanner wired on the claim');
    // Fail-closed: the module gate runs INSIDE the callback, so an uninstalled/unlicensed
    // module surfaces as an ApiException on the write, never as an unscanned file.
    $threw = null;
    try {
        ($prop($scanned, 'virusScanner'))('/dev/null');
    } catch (\FluxFiles\ApiException $e) {
        $threw = $e->getHttpCode();
    }
    assertTrue(in_array($threw, [501, 402, 403], true), 'virus gate resolves inside the callback (got ' . json_encode($threw) . ')');

    // ai_auto_tag + a provider -> tagger wired; ai_auto_tag alone (no provider) -> still none.
    $GLOBALS['LARAVEL_CONFIG']['fluxfiles.ai_provider'] = 'openai';
    try {
        assertTrue($prop($build->invoke($ctrl, $claimsFor(['ai_auto_tag' => true])), 'aiTagger') !== null, 'tagger wired for ai_auto_tag + provider');
        assertTrue($prop($build->invoke($ctrl, $claimsFor([])), 'aiTagger') === null, 'provider alone does NOT wire the tagger (adapter policy)');
    } finally {
        unset($GLOBALS['LARAVEL_CONFIG']['fluxfiles.ai_provider']);
    }
});

// ── round 3: shared core services (rate limiter backend, media limits, audit URI) ──
test('rate limiter: db mode selects the DB limiter; a missing/unusable DB falls back to the file limiter instead of 500ing', function () {
    $src = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(str_contains($src, 'RateLimiterFactory::makeDb(') && str_contains($src, 'RateLimiterFactory::makeFile('), 'both backends come from the core factory');
    assertTrue(!str_contains($src, 'new RateLimiterFileStorage('), 'no hand-built file limiter');

    // No Illuminate DB in this smoke, so the DB limiter cannot run: it must degrade, still enforce the limit.
    require_once __DIR__ . '/../src/LaravelPdoConnection.php';
    require_once __DIR__ . '/../src/LaravelDbMetadataHandler.php';
    $prevLog = ini_set('error_log', '/dev/null');
    try {
        withConfig(['fluxfiles.storage_backend' => 'db'], function () {
            $ctrl = freshController();
            $hdr = bearer(['perms' => ['read', 'write'], 'rate_write' => 1], 'rl-db');
            $codes = [];
            for ($i = 0; $i < 2; $i++) {
                // Missing `path`: a 400 after the limiter ran, without touching the (absent) DB metadata store.
                $codes[] = $ctrl->mkdir(new Request('POST', ['disk' => 'local'], $hdr, null, 'https://app.example.com', [], 'api/fm/mkdir'))->getStatusCode();
            }
            assertEqual(400, $codes[0], 'first call reaches the handler');
            assertEqual(429, $codes[1], 'the fallback limiter still enforces rate_write=1: ' . json_encode($codes));
        });
    } finally {
        ini_set('error_log', (string) $prevLog);
    }
});

test('media rate limits use core defaults (stream 300 / img 120) unless the adapter config overrides them', function () {
    // Unset in the smoke config: the callable falls back to the core defaults it is handed.
    $ctrl = freshController();
    $rl = (new \ReflectionProperty(\FluxFiles\MediaEndpoint::class, 'rateLimit'))->getValue((new \ReflectionMethod($ctrl, 'mediaEndpoint'))->invoke($ctrl));
    $hits = 0;
    while ($rl('rl-default', 'stream', 300) && $hits < 400) { $hits++; }
    assertEqual(300, $hits, 'stream allows 300/min by default');
    $hits = 0;
    while ($rl('rl-default', 'img', 120) && $hits < 400) { $hits++; }
    assertEqual(120, $hits, 'img allows 120/min by default');
    $cfg = require __DIR__ . '/../config/fluxfiles.php';
    assertEqual(300, $cfg['rate_limit_stream'], 'published config default = core default (stream)');
    assertEqual(120, $cfg['rate_limit_img'], 'published config default = core default (img)');

    // A tight override is honoured, 0 disables.
    withConfig(['fluxfiles.rate_limit_img' => 1], function () {
        $ctrl = freshController();
        $rl = (new \ReflectionProperty(\FluxFiles\MediaEndpoint::class, 'rateLimit'))->getValue((new \ReflectionMethod($ctrl, 'mediaEndpoint'))->invoke($ctrl));
        assertTrue($rl('rl-img-sub', 'img', 120) === true, 'first request passes');
        assertTrue($rl('rl-img-sub', 'img', 120) === false, 'second is limited by the adapter override');
    });
    withConfig(['fluxfiles.rate_limit_img' => 0], function () {
        $ctrl = freshController();
        $rl = (new \ReflectionProperty(\FluxFiles\MediaEndpoint::class, 'rateLimit'))->getValue((new \ReflectionMethod($ctrl, 'mediaEndpoint'))->invoke($ctrl));
        for ($i = 0; $i < 5; $i++) {
            assertTrue($rl('rl-off', 'img', 120) === true, 'disabled bucket never limits');
        }
    });
});

test('the audit route is derived from the request path relative to route_prefix (a custom prefix cannot change the action)', function () {
    $ctrl = new FluxFilesController();
    $uri = new \ReflectionMethod($ctrl, 'coreUri');
    assertEqual('/api/fm/delete', $uri->invoke($ctrl, new Request('DELETE', [], [], null, 'https://x', [], 'api/fm/delete')), 'default prefix');
    assertEqual('/api/fm/delete', withConfig(['fluxfiles.route_prefix' => 'upload/files'], fn () => $uri->invoke($ctrl, new Request('DELETE', [], [], null, 'https://x', [], 'upload/files/delete'))), 'a prefix that contains "upload" does not turn a delete into an upload');
});

test('a gated-local disk without a root falls back to core\'s default root, not the filesystem root', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../core/api/MediaEndpoint.php');
    assertTrue(str_contains($src, "storage/uploads"), 'MediaEndpoint owns the default root');
    $ctrl = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/FluxFilesController.php');
    assertTrue(!str_contains($ctrl, "realpath((string) (\$config['root']"), 'the adapter no longer resolves the root itself');
});

// ── Doctor: web exposure + config drift + fluxfiles:doctor ────────────────
require_once __DIR__ . '/../src/Doctor.php';

namespace_stub_console();
function namespace_stub_console(): void
{
    if (class_exists('Illuminate\Console\Command', false)) {
        return;
    }
    eval('namespace Illuminate\Console; class Command {
        const SUCCESS = 0; const FAILURE = 1;
        public static array $opts = []; public array $out = [];
        public function option($k) { return static::$opts[$k] ?? false; }
        public function line($m) { $this->out[] = $m; } public function warn($m) { $this->out[] = $m; }
        public function error($m) { $this->out[] = $m; } public function info($m) { $this->out[] = $m; }
    }');
}
if (!function_exists('public_path')) {
    function public_path($p = '') { return $GLOBALS['SMOKE_PUBLIC'] . ($p !== '' ? '/' . $p : ''); }
}
$GLOBALS['SMOKE_PUBLIC'] = $ffTmp . '/site/public';
@mkdir($GLOBALS['SMOKE_PUBLIC'] . '/uploads', 0775, true);
@mkdir($ffTmp . '/site/storage/app/public/fx', 0775, true);
@mkdir($ffTmp . '/site/storage/app/private', 0775, true);

test('doctor: a local root inside public/ warns; one outside does not', function () use ($ffTmp) {
    $pub = $GLOBALS['SMOKE_PUBLIC'];
    $in  = \FluxFiles\Laravel\Doctor::exposureChecks(['local' => ['driver' => 'local', 'root' => $pub . '/uploads/missing-yet']], $pub);
    assertEqual('warn', $in[0]['status'], 'inside public/ warns (even if the root is not created yet)');
    assertTrue(str_contains($in[0]['fix'], '_fluxfiles') && str_contains($in[0]['message'], '/uploads/missing-yet'), 'fix + url path');
    assertTrue(!str_contains($in[0]['message'], $pub), 'no absolute path unless showRoot');
    $out = \FluxFiles\Laravel\Doctor::exposureChecks(['local' => ['driver' => 'local', 'root' => $ffTmp . '/site/storage/app/private']], $pub);
    assertEqual('ok', $out[0]['status'], 'outside the web root is ok');
    $s3 = \FluxFiles\Laravel\Doctor::exposureChecks(['s3' => ['driver' => 's3']], $pub);
    assertEqual('ok', $s3[0]['status'], 's3 disks are ignored');
});

test('doctor: storage/app/public only counts when public/storage links to it', function () use ($ffTmp) {
    $pub  = $GLOBALS['SMOKE_PUBLIC'];
    $disk = ['local' => ['driver' => 'local', 'root' => $ffTmp . '/site/storage/app/public/fx']];
    assertEqual('ok', \FluxFiles\Laravel\Doctor::exposureChecks($disk, $pub)[0]['status'], 'no storage:link yet -> not served');
    symlink($ffTmp . '/site/storage/app/public', $pub . '/storage');
    $w = \FluxFiles\Laravel\Doctor::exposureChecks($disk, $pub);
    assertEqual('warn', $w[0]['status'], 'after storage:link it is served');
    assertTrue(str_contains($w[0]['message'], '/storage/fx'), 'URL path goes through the symlink');
    assertEqual(null, \FluxFiles\Laravel\Doctor::webPath($ffTmp . '/site/storage/app/private', $pub), 'private stays private');
});

test('doctor: config drift lists missing and unknown top-level keys', function () {
    $d = \FluxFiles\Laravel\Doctor::configDrift(['secret' => 1, 'legacy' => 1], ['secret' => 1, 'claims_resolver' => null, 'storage_backend' => 'json']);
    assertEqual(['claims_resolver', 'storage_backend'], $d['missing'], 'missing');
    assertEqual(['legacy'], $d['unknown'], 'unknown');
    $c = \FluxFiles\Laravel\Doctor::driftChecks(['secret' => 1, 'legacy' => 1], ['secret' => 1, 'claims_resolver' => null]);
    assertEqual(['warn', 'info'], array_column($c, 'status'), 'missing warns, unknown is info');
    assertTrue(str_contains($c[0]['message'], 'claims_resolver'), 'names the key');
    assertEqual('ok', \FluxFiles\Laravel\Doctor::driftChecks(['a' => 1], ['a' => 1])[0]['status'], 'no drift is ok');
    assertEqual('warn', \FluxFiles\Laravel\Doctor::summarize($c), 'summary');
});

test('doctor: diskDoctor endpoint keeps its shape and appends the host checks', function () use ($ffTmp) {
    $r = withConfig(['fluxfiles.disks' => ['local' => ['driver' => 'local', 'root' => $GLOBALS['SMOKE_PUBLIC'] . '/uploads', 'url' => '/uploads']]], function () {
        return freshController()->diskDoctor(new Request('GET', ['disk' => 'local'], bearer(['perms' => ['read', 'write']])));
    });
    $d = $r->getData()['data'];
    assertTrue(isset($d['disk'], $d['driver'], $d['summary'], $d['checks']), 'original keys');
    $ids = array_column($d['checks'], 'id');
    assertTrue(in_array('write', $ids, true), 'BucketDoctor checks kept');
    assertTrue(in_array('web_exposure:local', $ids, true), 'exposure warning added');
    assertEqual('warn', $d['summary'], 'summary reflects the warning');
});

test('doctor: fluxfiles:doctor is registered and exits non-zero on a problem', function () use ($ffTmp) {
    $prov = (string) file_get_contents(__DIR__ . '/../src/FluxFilesServiceProvider.php');
    assertTrue(str_contains($prov, 'Console\DoctorCommand::class'), 'registered in the provider');
    require_once __DIR__ . '/../src/Console/DoctorCommand.php';
    $cmd = new \FluxFiles\Laravel\Console\DoctorCommand();
    assertTrue(str_contains((new ReflectionProperty($cmd, 'signature'))->getValue($cmd), 'fluxfiles:doctor'), 'signature');
    \Illuminate\Console\Command::$opts = ['no-probe' => true];
    $bad = withConfig(['fluxfiles.disks' => ['local' => ['driver' => 'local', 'root' => $GLOBALS['SMOKE_PUBLIC'] . '/uploads']]], fn () => $cmd->handle());
    assertEqual(1, $bad, 'exposed root -> FAILURE');
    $cmd2 = new \FluxFiles\Laravel\Console\DoctorCommand();
    // Clean disk, but the stubbed app config lacks most package keys -> still a (drift) problem.
    $ok = withConfig(['fluxfiles.disks' => ['local' => ['driver' => 'local', 'root' => $ffTmp . '/site/storage/app/private']]], fn () => $cmd2->handle());
    assertEqual(1, $ok, 'config drift also fails');
    assertTrue(!empty(array_filter($cmd2->out, fn ($l) => str_contains($l, 'config_missing_keys'))), 'drift reported');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

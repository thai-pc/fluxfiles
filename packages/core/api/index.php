<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\BucketDoctor;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiterFileStorage;
use FluxFiles\StorageMetadataHandler;

// Load .env
$envDirs = [
    // Package root (default when installed via composer)
    realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'),
    // Monorepo root (developer checkout): packages/core/api -> repo root
    realpath(__DIR__ . '/../../..') ?: (__DIR__ . '/../../..'),
];

foreach ($envDirs as $dir) {
    if (is_string($dir) && file_exists(rtrim($dir, '/') . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($dir);
        $dotenv->safeLoad();
        break;
    }
}

// Trusted SFTP/import hosts allowed past the SSRF public-IP requirement. Legit
// use: an operator whose SFTP server lives on their own private/reserved network
// (a VPS behind a VPN). Comma-separated host[:port]; empty = full SSRF protection.
$ssrfAllow = array_filter(array_map('trim', explode(',', $_ENV['FLUXFILES_SSRF_ALLOW_HOSTS'] ?? '')));
if ($ssrfAllow !== []) {
    \FluxFiles\SsrfGuard::$allowTestHosts = array_map('strtolower', $ssrfAllow);
}

// CORS
$allowedOrigins = array_filter(
    array_map('trim', explode(',', $_ENV['FLUXFILES_ALLOWED_ORIGINS'] ?? ''))
);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// CSRF protection: verify the Origin header on mutating requests. Browsers always
// send Origin on cross-site mutating requests, so a forged one can be rejected.
// When no allowlist is configured we fall back to same-origin only — never "allow
// all" (the previous behaviour silently skipped the check on an empty allowlist).
// Requests with no Origin (non-browser API clients) are unaffected: they carry the
// JWT bearer token and are not a CSRF vector.
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) {
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($reqOrigin !== '') {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $selfOrigin = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $allowed = in_array($reqOrigin, $allowedOrigins, true) || $reqOrigin === $selfOrigin;
        if (!$allowed) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => null, 'error' => 'Origin not allowed', 'error_code' => 'origin_denied']);
            exit;
        }
    }
}

header('Content-Type: application/json; charset=utf-8');

// I18n — initialize before routing
$envLocale = $_ENV['FLUXFILES_LOCALE'] ?? '';
$i18n = new \FluxFiles\I18n(__DIR__ . '/../lang', $envLocale !== '' ? $envLocale : null);
header('Content-Language: ' . $i18n->locale());

// Parse URI early for lang routes (no auth required)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Serve UI with pre-injected locale (no flash)
if ($method === 'GET' && ($uri === '/public/index.html' || $uri === '/public' || $uri === '/public/')) {
    header('Content-Type: text/html; charset=utf-8');
    // The HTML is dynamic (locale + asset hashes) and must always be revalidated,
    // otherwise a cached page would keep pointing at the old ?v= asset URLs.
    header('Cache-Control: no-cache, must-revalidate');
    // Security: this page may be opened with a `?token=<JWT>` (standalone mode), so
    // never leak the URL (token included) via the Referer header to any sub-resource
    // or outbound link. Does NOT affect the Origin header → CSRF same-origin still works.
    header('Referrer-Policy: no-referrer');
    $localeJson = $i18n->toJson();
    $locale = $i18n->locale();
    $dir = $i18n->direction();
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    // Cache-bust the UI assets with a short content hash, so a core update is never
    // served from a stale browser/proxy cache (the static fm.js/fm.css URLs carry no
    // version of their own). Per-file hash → each invalidates only when it changes.
    $assetVer = static function (string $file): string {
        $p = __DIR__ . '/../assets/' . $file;
        return is_file($p) ? substr(md5_file($p), 0, 10) : (string) time();
    };
    $html = str_replace(
        ['../assets/fm.css"', '../assets/fm.js"'],
        ['../assets/fm.css?v=' . $assetVer('fm.css') . '"', '../assets/fm.js?v=' . $assetVer('fm.js') . '"'],
        $html
    );
    // HEX flags keep these safe to embed inside the inline <script> (no </script> breakout).
    $jsFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $injection = "window.__FM_LOCALE__ = { locale: " . json_encode($locale, $jsFlags) . ", dir: " . json_encode($dir, $jsFlags) . ", messages: {$localeJson} };";
    $html = str_replace(
        "window.__FM_LOCALE__ = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };",
        $injection,
        $html
    );
    $html = str_replace('<html lang="en">', '<html lang="' . htmlspecialchars($locale) . '" dir="' . htmlspecialchars($dir) . '">', $html);

    // Public demo mode (FLUXFILES_DEMO=1): inject a hardened, per-visitor demo token as
    // window.__FM_BOOT__ so an embedded iframe lets anonymous visitors upload for real
    // inside their own `demo/<id>/` sandbox — the token never reaches the marketing site.
    if (\FluxFiles\DemoMode::enabled()) {
        require_once __DIR__ . '/../embed.php';
        $stateDir = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
        $boot = \FluxFiles\DemoMode::bootConfig($stateDir);
        // Only inject when a token was issued. A throttled IP (too many fresh sandboxes
        // this hour) gets none → the UI shows the auth-required state, which for the demo
        // reads as "try again later" and stops one abuser spinning up unlimited sandboxes.
        if (!empty($boot['token'])) {
            $bootJson = json_encode($boot, $jsFlags);
            $html = str_replace('</head>', "<script>window.__FM_BOOT__ = {$bootJson};</script>\n</head>", $html);
        }
        // Opportunistic purge (~5% of loads): TTL sweep + global size-budget enforcement.
        if (random_int(1, 20) === 1) {
            $localRoot = $_ENV['FLUXFILES_LOCAL_ROOT'] ?? ($stateDir . '/uploads');
            \FluxFiles\DemoMode::purge($localRoot);
        }
    }

    echo $html;
    exit;
}

// Language routes — public, no auth needed
if ($method === 'GET' && $uri === '/api/fm/lang') {
    $files = glob(__DIR__ . '/../lang/*.json');
    $result = [];
    foreach ($files as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (!is_array($data)) continue;
        $code = $data['_meta']['locale'] ?? basename($f, '.json');
        $result[] = [
            'code' => $code,
            'name' => $data['_meta']['name'] ?? $code,
            'dir'  => $data['_meta']['direction'] ?? 'ltr',
        ];
    }
    echo json_encode(['data' => $result, 'error' => null]);
    exit;
}

if ($method === 'GET' && preg_match('#^/api/fm/lang/([a-z]{2,5})$#', $uri, $m)) {
    $locale = $m[1];
    $path = __DIR__ . "/../lang/{$locale}.json";
    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['data' => null, 'error' => 'Locale not found']);
        exit;
    }
    $data = json_decode(file_get_contents($path), true);
    echo json_encode(['data' => [
        'locale'   => $data['_meta']['locale'] ?? $locale,
        'dir'      => $data['_meta']['direction'] ?? 'ltr',
        'messages' => $data,
    ], 'error' => null]);
    exit;
}

// Gated local media stream — authenticated by a per-file stream token in the query
// string (a <video>/<audio> element can't send an Authorization header), NOT by the
// main access JWT. Only ever serves files on a local disk marked `private => true`.
if ($method === 'GET' && $uri === '/api/fm/stream') {
    handleMediaStream();
    exit;
}

// On-demand WebP transform — authenticated by a per-file image token in the query
// string (an <img> can't send an Authorization header). Serves a resized WebP from
// (or into) the file's _variants/ cache. Never raw bytes of an arbitrary file.
if ($method === 'GET' && $uri === '/api/fm/img') {
    handleImageTransform();
    exit;
}

// Intake / Upload Portals — PUBLIC endpoints (no main JWT; authenticated by the
// portal token itself, like /img and /stream). `info` = the landing page's data;
// `upload` = an anonymous visitor dropping a file into the operator's storage.
// The create/manage side is authed + gated under the main routes below.
if ($uri === '/api/fm/intake/info' || $uri === '/api/fm/intake/upload') {
    handleIntakePublic($method, $uri);
    exit;
}

// Share landing — PUBLIC endpoints (no main JWT; authenticated by the share token
// itself, like intake/img/stream). `info` = the landing card, `unlock` = exchange a
// password for a short-lived grant, `file` = the bytes (or a presigned redirect).
// The create/manage side is authed + gated under the main routes below.
if ($uri === '/api/fm/share/info' || $uri === '/api/fm/share/unlock' || $uri === '/api/fm/share/file') {
    handleSharePublic($method, $uri);
    exit;
}

try {
    // Auth
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
        throw new ApiException('FLUXFILES_SECRET is not configured', 500);
    }

    $token = JwtMiddleware::extractToken();
    $claims = JwtMiddleware::handle($token, $secret);

    // Dependencies
    $diskConfigs = require __DIR__ . '/../config/disks.php';
    // Public demo mode: hard-strip every non-local disk so the demo can never touch
    // S3/R2/SFTP (no egress cost, no BYOB) — defense-in-depth over the local-only token.
    if (\FluxFiles\DemoMode::enabled()) {
        $diskConfigs = \FluxFiles\DemoMode::forceLocalDisks($diskConfigs);
    }
    $diskManager = new DiskManager($diskConfigs);

    // Register BYOB (Bring Your Own Bucket) disks from JWT
    foreach ($claims->byobDisks as $byobName => $byobConfig) {
        $diskManager->registerByobDisk($byobName, $byobConfig);
    }
    // Runtime-state dir (rate-limit counter). Overridable so read-only/immutable
    // deployments can point it at a writable volume; defaults to packages/core/storage.
    $storagePath = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
    $metaRepo = new StorageMetadataHandler($diskManager);
    $fm = new FileManager($diskManager, $claims, $metaRepo);
    $fm->setQuotaManager(new QuotaManager($diskManager));
    // Enables gated-local media: file URLs on a `private => true` local disk become
    // tokened /api/fm/stream links (served by handleMediaStream, above).
    $fm->setStreamSecret($secret);

    // AI Tagger (optional)
    $aiProvider = $_ENV['FLUXFILES_AI_PROVIDER'] ?? '';
    if ($aiProvider !== '') {
        $aiTagger = new \FluxFiles\AiTagger(
            $aiProvider,
            $_ENV['FLUXFILES_AI_API_KEY'] ?? '',
            $_ENV['FLUXFILES_AI_MODEL'] ?? null
        );
        $fm->setAiTagger($aiTagger);
    }

    // On-upload auto-optimize (FREE/core). Wire the optimizer hook when the token
    // asks for it (`auto_optimize`). The module does the work; FileManager records
    // the savings + renames to .webp.
    if ($claims->autoOptimize) {
        $optimizeModule = new \FluxFiles\OptimizeModule();
        $fm->setUploadOptimizer(static function (string $bytes, int $quality) use ($optimizeModule) {
            return $optimizeModule->optimizeBytes($bytes, $quality);
        });
    }

    // File versioning (paid module). Wire the version keeper ONLY when the token asks
    // (`allow_versioning`) AND the module is installed + licensed — so the free core
    // keeps no versions. FileManager calls it before overwriting an existing file.
    if ($claims->allowVersioning
        && \FluxFiles\ModuleRegistry::installed('versioning')
        && \FluxFiles\LicenseManager::fromEnv()->licensed('versioning')) {
        $versioning = new \FluxFiles\Versioning\VersioningModule();
        $fm->setVersionKeeper(static function (string $d, string $key, $fs) use ($versioning, $claims) {
            $versioning->keep($fs, $key, $claims);
        });
    }

    // Webhooks (paid module). Fire a signed HTTP POST on file events. Wired only when
    // the token carries a `webhook_url` + `allow_webhooks` AND the module is installed
    // + licensed. Event-driven = stateless: it fires on the request that caused the
    // event (no scheduler/queue). Best-effort — a webhook failure never breaks the op.
    $webhookDispatcher = null;
    $webhookEvent = null;
    if ($claims->allowWebhooks && $claims->webhookUrl !== ''
        && \FluxFiles\ModuleRegistry::installed('webhooks')
        && \FluxFiles\LicenseManager::fromEnv()->licensed('webhooks')) {
        $webhooks = new \FluxFiles\Webhooks\WebhooksModule();
        $webhookDispatcher = static function (string $event, array $context) use ($webhooks, $claims, $secret) {
            $webhooks->dispatch($claims, $secret, $event, $context);
        };
    }

    // Rate limiting (JSON file). Per-tenant `rate_read`/`rate_write` claims override
    // the server defaults when set (> 0); otherwise inherit the env limits.
    $rateLimiter = new RateLimiterFileStorage(
        $storagePath . '/rate_limit.json',
        $claims->rateRead > 0 ? $claims->rateRead : (int) ($_ENV['FLUXFILES_RATE_LIMIT_READ'] ?? 60),
        $claims->rateWrite > 0 ? $claims->rateWrite : (int) ($_ENV['FLUXFILES_RATE_LIMIT_WRITE'] ?? 10),
        60
    );
    $isWriteAction = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $rateLimiter->check($claims->userId, $isWriteAction ? 'write' : 'read');

    // URL import is an outbound HTTP request from the server — give it its own,
    // tighter bucket (default 10/min) so it can't be abused at the upload rate.
    if ($uri === '/api/fm/import-url') {
        $importLimit = $claims->importRateLimit > 0
            ? $claims->importRateLimit
            : (int) ($_ENV['FLUXFILES_IMPORT_RATE_LIMIT'] ?? 10);
        (new RateLimiterFileStorage($storagePath . '/rate_limit.json', $importLimit, $importLimit, 60))
            ->check($claims->userId, 'import');
    }

    // A forced usage recompute lists the whole prefix — give it its own tight
    // bucket (2/min) so it can't be abused to hammer the storage with ListObjects.
    if ($uri === '/api/fm/usage' && ($_GET['refresh'] ?? '') === 'true') {
        (new RateLimiterFileStorage($storagePath . '/rate_limit.json', 2, 2, 60))
            ->check($claims->userId, 'usage_refresh');
    }

    // Audit log (lưu trong user storage)
    $auditLog = new AuditLogStorage($metaRepo, $claims->allowedDisks);
    $chunker = new \FluxFiles\ChunkUploader($diskManager);
    $quotaManager = new QuotaManager($diskManager);

    // Routing
    $data = routeRequest($method, $uri, $fm, $metaRepo, $diskManager, $claims, $auditLog, $chunker, $quotaManager);

    // Log write actions
    if ($isWriteAction && $data !== null) {
        $auditAction = resolveAuditAction($uri);
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true) ?: [];
        $auditKey = $body['path'] ?? $body['key'] ?? $body['from'] ?? $body['src_path'] ?? '';
        // Multipart uploads carry no JSON body, so the key isn't in $body — fall
        // back to the operation result (the uploaded file's key) so the entry
        // isn't blank. Only fills when empty, so other actions are unchanged.
        if ($auditKey === '' && is_array($data) && isset($data['key'])) {
            $auditKey = (string) $data['key'];
        }
        $auditDisk = $body['disk'] ?? $body['src_disk'] ?? $_POST['disk'] ?? 'local';
        $auditLog->log($claims->userId, $auditAction, $auditDisk, (string) $auditKey);

        // Capture the webhook event; it's DISPATCHED AFTER the response is flushed
        // (below) so a slow/unreachable endpoint never adds to the client's latency.
        if ($webhookDispatcher !== null) {
            $webhookEvent = [$auditAction, [
                'disk' => (string) $auditDisk,
                'path' => (string) $auditKey,
                'name' => is_array($data) ? (string) ($data['name'] ?? basename((string) $auditKey)) : '',
            ]];
        }
    }

    echo json_encode(['data' => $data, 'error' => null]);

    // Fire the webhook (paid module; best-effort) AFTER the response. Under php-fpm
    // fastcgi_finish_request() flushes the response to the client first, so the sync
    // HTTP POST is off the request's critical path; elsewhere it runs sync (bounded
    // by a short timeout in the module).
    if (!empty($webhookEvent) && $webhookDispatcher !== null) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $webhookDispatcher($webhookEvent[0], $webhookEvent[1]);
    }
} catch (ApiException $e) {
    http_response_code($e->getHttpCode());
    $errResp = ['data' => null, 'error' => $e->getMessage()];
    if ($e->getErrorCode() !== null) {
        $errResp['error_code'] = $e->getErrorCode();
    }
    if ($e->getErrorParams()) {
        $errResp['error_params'] = $e->getErrorParams();
    }
    echo json_encode($errResp);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['data' => null, 'error' => 'Internal server error', 'error_code' => 'server_error']);
    error_log('FluxFiles Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

// --- Helper functions ---

/**
 * Route the request to the appropriate handler.
 *
 * @return mixed
 */
function routeRequest(
    string $method,
    string $uri,
    FileManager $fm,
    StorageMetadataHandler $metaRepo,
    DiskManager $diskManager,
    \FluxFiles\Claims $claims,
    AuditLogStorage $auditLog,
    \FluxFiles\ChunkUploader $chunker,
    \FluxFiles\QuotaManager $quotaManager
) {
    // File operations
    if ($method === 'GET' && $uri === '/api/fm/list') {
        return $fm->list(
            $_GET['disk'] ?? 'local',
            $_GET['path'] ?? '',
            max(0, (int) ($_GET['limit'] ?? 0)),
            (string) ($_GET['cursor'] ?? '')
        );
    }

    if ($method === 'POST' && $uri === '/api/fm/upload') {
        if (!isset($_FILES['file'])) {
            throw new ApiException('No file uploaded', 400, 'no_file');
        }
        return $fm->upload(
            $_POST['disk'] ?? 'local',
            $_POST['path'] ?? '',
            $_FILES['file'],
            filter_var($_POST['force_upload'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }

    // Upload from URL — gated by the `allow_url_import` claim + SSRF guard inside.
    if ($method === 'POST' && $uri === '/api/fm/import-url') {
        if (!$claims->hasPerm('write')) {
            throw new ApiException('Permission denied: write', 403, 'permission_denied');
        }
        $b = json_decode(file_get_contents('php://input'), true);
        if (!is_array($b) || empty($b['url'])) {
            throw new ApiException('Missing required field: url', 400, 'missing_param');
        }
        return (new \FluxFiles\UrlImporter($claims, $fm))->import(
            (string) ($b['disk'] ?? 'local'),
            (string) $b['url'],
            [
                'path'      => (string) ($b['path'] ?? ''),
                'filename'  => isset($b['filename']) ? (string) $b['filename'] : null,
                'overwrite' => filter_var($b['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]
        );
    }

    if ($method === 'DELETE' && $uri === '/api/fm/delete') {
        return $fm->delete(...jsonBody('disk', 'path'));
    }

    // Trash / restore (soft-delete) — gated by the 'delete' permission inside FM.
    if ($method === 'POST' && $uri === '/api/fm/trash') {
        return $fm->trash(...jsonBody('disk', 'path'));
    }
    if ($method === 'POST' && $uri === '/api/fm/trash/restore') {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!is_array($b) || !isset($b['disk'], $b['trash_id'])) {
            throw new ApiException('Missing required field: disk/trash_id', 400, 'missing_param');
        }
        return $fm->restore((string) $b['disk'], (string) $b['trash_id'], $b['path'] ?? null);
    }
    if ($method === 'GET' && $uri === '/api/fm/trash/list') {
        return $fm->listTrash($_GET['disk'] ?? 'local');
    }
    if ($method === 'POST' && $uri === '/api/fm/trash/purge') {
        return $fm->purgeTrash(...jsonBody('disk', 'trash_id'));
    }
    if ($method === 'POST' && $uri === '/api/fm/trash/empty') {
        return $fm->emptyTrash(...jsonBody('disk'));
    }

    if ($method === 'POST' && $uri === '/api/fm/rename') {
        return $fm->rename(...jsonBody('disk', 'path', 'name'));
    }

    if ($method === 'POST' && $uri === '/api/fm/move') {
        return $fm->move(...jsonBody('disk', 'from', 'to'));
    }

    if ($method === 'POST' && $uri === '/api/fm/copy') {
        return $fm->copy(...jsonBody('disk', 'from', 'to'));
    }

    if ($method === 'POST' && $uri === '/api/fm/mkdir') {
        return $fm->mkdir(...jsonBody('disk', 'path'));
    }

    if ($method === 'POST' && $uri === '/api/fm/cross-copy') {
        return $fm->crossCopy(...jsonBody('src_disk', 'src_path', 'dst_disk', 'dst_path'));
    }

    if ($method === 'POST' && $uri === '/api/fm/cross-move') {
        return $fm->crossMove(...jsonBody('src_disk', 'src_path', 'dst_disk', 'dst_path'));
    }

    if ($method === 'POST' && $uri === '/api/fm/presign') {
        return handlePresign($fm);
    }

    if ($method === 'POST' && $uri === '/api/fm/crop') {
        return handleCrop($fm);
    }

    // Watermark editor (free) — burn a logo/text watermark into an image at a
    // free position/size. Write perm; logo arrives as base64 in the JSON body.
    if ($method === 'POST' && $uri === '/api/fm/watermark') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $disk = (string) ($body['disk'] ?? 'local');
        $path = (string) ($body['path'] ?? '');
        if ($path === '') {
            throw new ApiException('Missing path', 400, 'missing_param');
        }
        $wm = [
            'type'      => ($body['type'] ?? 'logo') === 'text' ? 'text' : 'logo',
            'text'      => (string) ($body['text'] ?? ''),
            'x'         => (float) ($body['x'] ?? 0.7),
            'y'         => (float) ($body['y'] ?? 0.85),
            'scale'     => (float) ($body['scale'] ?? 0.25),
            'opacity'   => (float) ($body['opacity'] ?? 0.6),
            'font_size' => (int) ($body['font_size'] ?? 24),
            'color'     => (string) ($body['color'] ?? '#ffffff'),
        ];
        if (!empty($body['logo_data'])) {
            // data:URI or bare base64 → binary.
            $b64 = preg_replace('#^data:[^,]+,#', '', (string) $body['logo_data']);
            $bin = base64_decode((string) $b64, true);
            if ($bin === false || $bin === '') {
                throw new ApiException('Invalid logo data', 400, 'bad_request');
            }
            $wm['logo_data'] = $bin;
        }
        $dest = isset($body['dest']) && $body['dest'] !== '' ? (string) $body['dest'] : null;
        return $fm->applyWatermark($disk, $path, $wm, $dest);
    }

    // Remove an in-place burned-in watermark by restoring the kept original.
    if ($method === 'POST' && $uri === '/api/fm/watermark/remove') {
        return $fm->removeWatermark(...jsonBody('disk', 'path'));
    }

    if ($method === 'POST' && $uri === '/api/fm/ai-tag') {
        return $fm->aiTag(...jsonBody('disk', 'path'));
    }

    if ($method === 'GET' && $uri === '/api/fm/meta') {
        $path = $_GET['path'] ?? null;
        if ($path === null) {
            throw new ApiException('Missing path parameter', 400, 'missing_param');
        }
        return $fm->fileMeta($_GET['disk'] ?? 'local', $path);
    }

    if ($method === 'GET' && $uri === '/api/fm/metadata') {
        return handleGetMetadata($metaRepo, $claims, $fm);
    }
    if ($method === 'PUT' && $uri === '/api/fm/metadata') {
        return handleSaveMetadata($metaRepo, $diskManager, $claims, $fm);
    }
    if ($method === 'DELETE' && $uri === '/api/fm/metadata') {
        return handleDeleteMetadata($metaRepo, $claims, $fm);
    }

    // License — the server's commercial edition/status (non-sensitive summary),
    // so a dashboard can show edition + expiry. Free core → {edition:'free'}.
    if ($method === 'GET' && $uri === '/api/fm/license') {
        return \FluxFiles\LicenseManager::fromEnv()->info();
    }

    // Optimization (FREE/core) — recompress images to WebP + compress PDFs. Opt-in
    // per token via `allow_optimize` (it replaces/deletes originals, so it's a
    // deliberate capability, not on by default); the module itself enforces write.
    if ($method === 'POST' && $uri === '/api/fm/optimize') {
        if (!$claims->allowOptimize) {
            throw new ApiException('This token may not use optimization', 403, 'optimize_forbidden');
        }
        $module = new \FluxFiles\OptimizeModule();
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->run($fm, $diskManager, new \FluxFiles\ImageOptimizer(), $claims, $body);
    }

    // ── Other paid modules — same 3-layer gate (501/402/403). Free core ships none
    // of these packages → class_exists false → 501 module_not_installed. ───────────
    if ($method === 'POST' && $uri === '/api/fm/share') {
        $module = \FluxFiles\ModuleRegistry::require('share', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $res = $module->createShare($fm, $diskManager, $claims, (string) ($_ENV['FLUXFILES_SECRET'] ?? ''), $body);
        // The recipient URL. The module builds it from `share_base_url` when the
        // token carries one; otherwise fall back to this request's origin + the
        // core landing page. The token is returned ONCE and never stored — a listed
        // share can only be revoked, not re-linked (same posture as an API key).
        if (empty($res['url']) && !empty($res['token'])) {
            $res['url'] = ff_request_origin() . '/public/share.html?token=' . rawurlencode((string) $res['token']);
        }
        return $res;
    }
    // Share list/revoke — revocation is the only kill switch for a link that is
    // genuinely public, so it ships with the landing. Same 3-layer gate.
    if ($method === 'GET' && $uri === '/api/fm/share/list') {
        $module = \FluxFiles\ModuleRegistry::require('share', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->listShares($diskManager, $claims, ff_str_param($_GET, 'disk', 'local'));
    }
    if ($method === 'POST' && $uri === '/api/fm/share/revoke') {
        $module = \FluxFiles\ModuleRegistry::require('share', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->revokeShare($diskManager, $claims, (string) ($body['disk'] ?? 'local'), (string) ($body['jti'] ?? ''));
    }
    // Intake / Upload Portals — operator create + manage (public info/upload are
    // handled before auth). Same 3-layer gate (501/402/403).
    if ($method === 'POST' && $uri === '/api/fm/intake') {
        $module = \FluxFiles\ModuleRegistry::require('intake', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->createPortal($fm, $diskManager, $claims, (string) ($_ENV['FLUXFILES_SECRET'] ?? ''), $body);
    }
    if ($method === 'GET' && $uri === '/api/fm/intake/list') {
        $module = \FluxFiles\ModuleRegistry::require('intake', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->listPortals($diskManager, $claims, ff_str_param($_GET, 'disk', 'local'));
    }
    if ($method === 'POST' && $uri === '/api/fm/intake/revoke') {
        $module = \FluxFiles\ModuleRegistry::require('intake', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->revokePortal($diskManager, $claims, (string) ($body['disk'] ?? 'local'), (string) ($body['jti'] ?? ''));
    }
    // File versioning — list prior versions of a file / restore one. Same 3-layer gate.
    if ($method === 'GET' && $uri === '/api/fm/versions') {
        $module = \FluxFiles\ModuleRegistry::require('versioning', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->listVersions($fm, $diskManager, $claims, ff_str_param($_GET, 'disk', 'local'), ff_str_param($_GET, 'path'));
    }
    if ($method === 'POST' && $uri === '/api/fm/versions/restore') {
        $module = \FluxFiles\ModuleRegistry::require('versioning', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->restore($fm, $diskManager, $claims, (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''), (string) ($body['version_id'] ?? ''));
    }
    // Webhooks — send a test ping to the configured endpoint so operators can verify it.
    if ($method === 'POST' && $uri === '/api/fm/webhooks/test') {
        $module = \FluxFiles\ModuleRegistry::require('webhooks', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->test($claims, (string) ($_ENV['FLUXFILES_SECRET'] ?? ''));
    }
    if ($method === 'POST' && $uri === '/api/fm/ai-vision') {
        $module = \FluxFiles\ModuleRegistry::require('ai', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->run($fm, $diskManager, new \FluxFiles\ImageOptimizer(), $claims, $body);
    }
    if ($method === 'POST' && $uri === '/api/fm/ocr') {
        $module = \FluxFiles\ModuleRegistry::require('ocr', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->run($fm, $diskManager, $claims, $body);
    }
    if ($method === 'POST' && $uri === '/api/fm/backup') {
        $module = \FluxFiles\ModuleRegistry::require('backup', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->run($fm, $diskManager, $claims, $body);
    }
    if ($method === 'POST' && $uri === '/api/fm/c2pa') {
        $module = \FluxFiles\ModuleRegistry::require('c2pa', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->verify($fm, $diskManager, $claims, $body);
    }
    if ($method === 'POST' && $uri === '/api/fm/c2pa/sign') {
        $module = \FluxFiles\ModuleRegistry::require('c2pa', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->sign($fm, $diskManager, $claims, $body);
    }

    // Config / code editor — read a file's text content, or overwrite it.
    if ($method === 'GET' && $uri === '/api/fm/content') {
        return $fm->getContent($_GET['disk'] ?? 'local', $_GET['path'] ?? '');
    }
    if ($method === 'PUT' && $uri === '/api/fm/content') {
        [$disk, $path, $content] = jsonBody('disk', 'path', 'content');
        return $fm->putContent((string) $disk, (string) $path, (string) $content);
    }

    // Zip download — stream a zip of the selected files/folders. Read op; bypasses
    // the JSON encoder (ZipStream sends its own headers + body, then we exit). A
    // guard/size violation in zipManifest throws before any byte → normal JSON error.
    if ($method === 'POST' && $uri === '/api/fm/zip') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $fm->streamZip(
            (string) ($body['disk'] ?? 'local'),
            is_array($body['paths'] ?? null) ? $body['paths'] : [],
            isset($body['name']) ? (string) $body['name'] : null
        );
        exit;
    }

    // Extract a zip in place (returns JSON; guarded against slip/bomb/quota/dangerous-ext).
    if ($method === 'POST' && $uri === '/api/fm/extract') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $fm->extractZip(
            (string) ($body['disk'] ?? 'local'),
            (string) ($body['path'] ?? ''),
            isset($body['dest']) ? (string) $body['dest'] : null
        );
    }

    // SFTP file permissions (chmod). Read the current mode, or set a new one.
    if ($method === 'GET' && $uri === '/api/fm/chmod') {
        return $fm->getMode($_GET['disk'] ?? '', $_GET['path'] ?? '');
    }
    if ($method === 'POST' && $uri === '/api/fm/chmod') {
        if (!$claims->allowChmod) {
            throw new ApiException('Changing permissions is not allowed', 403, 'chmod_forbidden');
        }
        [$disk, $path, $mode] = jsonBody('disk', 'path', 'mode');
        return $fm->setMode((string) $disk, (string) $path, (string) $mode);
    }

    // SSH terminal (command-runner) — SFTP disks only. Stateless: one command per
    // request; the cwd is threaded back to the client. Hard-gated: a server
    // kill-switch, the allow_terminal claim, write perm, an SFTP disk that
    // actually grants a shell, and a double-confirm for catastrophic commands.
    if ($method === 'POST' && $uri === '/api/fm/terminal') {
        if (($_ENV['FLUXFILES_TERMINAL_DISABLED'] ?? '') === 'true') {
            throw new ApiException('The terminal is disabled on this server', 403, 'terminal_disabled');
        }
        if (!$claims->allowTerminal) {
            throw new ApiException('Terminal access is not allowed', 403, 'terminal_forbidden');
        }
        $body = jsonBodyAll();
        if (!$claims->hasPerm('write')) {
            throw new ApiException('Permission denied: write', 403, 'permission_denied');
        }
        $disk = (string) ($body['disk'] ?? '');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
        if (($diskManager->config($disk)['driver'] ?? '') !== 'sftp') {
            throw new ApiException('The terminal only works on an SFTP disk', 400, 'terminal_unsupported');
        }
        $cmd = trim((string) ($body['cmd'] ?? ''));
        if ($cmd === '') {
            throw new ApiException('Missing command', 400, 'missing_param');
        }
        // Catastrophic-command guardrail: double-confirm unless the host opted out.
        $confirmOff = ($_ENV['FLUXFILES_TERMINAL_CONFIRM'] ?? '') === 'false';
        if (!$confirmOff && empty($body['confirm']) && \FluxFiles\SshTerminal::isDangerous($cmd)) {
            throw new ApiException('This command looks dangerous — confirm to run it', 409, 'terminal_confirm_required');
        }
        [$conn, $root] = $diskManager->sftpConnection($disk);
        // Resolve cwd against the SFTP ROOT (not the SSH login home) — a relative
        // path from the client ("html") must be anchored to $root, or `cd html`
        // runs from /root and every command 404s.
        $cwd = \FluxFiles\SshTerminal::resolveCwd((string) ($body['cwd'] ?? ''), $root);
        $timeout = (int) ($_ENV['FLUXFILES_TERMINAL_TIMEOUT'] ?? 30);
        $result = \FluxFiles\SshTerminal::run($conn, $cmd, $cwd, $timeout);
        // No separate shell probe (it doubled the SSH round-trips per command):
        // run() prints a cwd marker on any real shell, so its absence means the
        // host forces a command / allows SFTP only.
        if (empty($result['shell_ok'])) {
            throw new ApiException('This host does not allow a shell (SFTP-only)', 400, 'terminal_no_shell');
        }
        return $result;
    }

    // Search
    if ($method === 'GET' && $uri === '/api/fm/search') {
        $q = $_GET['q'] ?? null;
        if ($q === null) {
            throw new ApiException('Missing search query', 400, 'missing_param');
        }
        $disk = $_GET['disk'] ?? 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied: read', 403, 'permission_denied');
        }
        $rows = $metaRepo->search($disk, $q, (int) ($_GET['limit'] ?? 50), $claims->pathPrefix, $claims->showHidden);
        // Strip the tenant prefix so search keys are relative to the client root,
        // matching list()/navigation (the prefix stays an internal detail).
        foreach ($rows as &$row) {
            if (isset($row['file_key'])) {
                $row['file_key'] = $claims->unscopePath((string) $row['file_key']);
            }
        }
        unset($row);
        return $rows;
    }

    // Search folders (directory index)
    if ($method === 'GET' && $uri === '/api/fm/search-folders') {
        $q = $_GET['q'] ?? null;
        if ($q === null) {
            throw new ApiException('Missing search query', 400, 'missing_param');
        }
        $disk = $_GET['disk'] ?? 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied: read', 403, 'permission_denied');
        }
        $rows = $metaRepo->searchFolders($disk, $q, (int) ($_GET['limit'] ?? 50), $claims->pathPrefix, $claims->showHidden);
        foreach ($rows as &$row) {
            if (isset($row['dir_key'])) {
                $row['dir_key'] = $claims->unscopePath((string) $row['dir_key']);
            }
        }
        unset($row);
        return $rows;
    }

    // Quota
    if ($method === 'GET' && $uri === '/api/fm/quota') {
        return $quotaManager->getQuotaInfo(
            $_GET['disk'] ?? 'local',
            $claims->pathPrefix,
            $claims->maxStorageMb
        );
    }

    if ($method === 'GET' && $uri === '/api/fm/usage') {
        return handleUsage($quotaManager, $diskManager, $claims);
    }

    // Audit log — users can only view their own logs
    if ($method === 'GET' && $uri === '/api/fm/audit') {
        // Reading the activity log is gated behind an explicit 'audit' permission
        // (off by default) so an ordinary read token cannot see who did what.
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        return $auditLog->list(
            (int) ($_GET['limit'] ?? 100),
            (int) ($_GET['offset'] ?? 0),
            ($_GET['actor'] ?? null) ?: null,
            $claims,
            [
                'action' => $_GET['action'] ?? null,
                'from'   => $_GET['from'] ?? null,
                'to'     => $_GET['to'] ?? null,
                'path'   => $_GET['path'] ?? null,
            ]
        );
    }

    // Bucket Doctor — diagnose a disk's storage backend (creds, permissions,
    // CORS, presign). Requires write (it writes/deletes a probe object) on a
    // disk the token may access; the host can run it on an ephemeral BYOB token
    // to validate credentials before issuing a long-lived one.
    if ($method === 'GET' && $uri === '/api/fm/disk/doctor') {
        $disk = $_GET['disk'] ?? 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        if (!$claims->hasPerm('write')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_GET['origin'] ?? null);
        return (new BucketDoctor($diskManager))->diagnose($disk, $origin ?: null);
    }

    // Chunk upload
    if ($method === 'POST' && $uri === '/api/fm/chunk/init') {
        return handleChunkInit($chunker, $claims, $fm, $quotaManager);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/presign') {
        return handleChunkPresign($chunker, $claims, $fm);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/complete') {
        return handleChunkComplete($chunker, $claims, $fm, $metaRepo);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/abort') {
        return handleChunkAbort($chunker, $claims, $fm);
    }

    throw new ApiException('Not found', 404, 'not_found');
}

function resolveAuditAction(string $uri): string
{
    $map = [
        '/trash/restore' => 'restore',
        '/trash/purge'   => 'purge',
        '/trash/empty'   => 'empty_trash',
        '/trash'      => 'trash',
        '/import-url' => 'url_import',
        '/upload'     => 'upload',
        '/rename'     => 'rename',
        '/delete'     => 'delete',
        '/ai-tag'     => 'ai_tag',
        '/crop'       => 'crop',
        '/cross-move' => 'cross_move',
        '/cross-copy' => 'cross_copy',
        '/move'       => 'move',
        '/copy'       => 'copy',
        '/mkdir'      => 'mkdir',
        '/metadata'   => 'metadata_update',
        '/chunk'      => 'chunk_upload',
        '/watermark/remove' => 'watermark_remove',
        '/watermark'  => 'watermark',
        '/optimize'   => 'optimize',
        '/extract'    => 'extract',
        '/chmod'      => 'chmod',
        '/content'    => 'content_update',
        '/terminal'   => 'terminal',
    ];

    foreach ($map as $needle => $action) {
        if (strpos($uri, $needle) !== false) {
            return $action;
        }
    }

    return 'unknown';
}

/** The full decoded JSON body (for routes with optional fields). */
function jsonBodyAll(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400, 'invalid_json');
    }
    return $body;
}

function jsonBody(string ...$keys): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400, 'invalid_json');
    }

    $result = [];
    foreach ($keys as $key) {
        if (!isset($body[$key])) {
            throw new ApiException("Missing required field: {$key}", 400);
        }
        $result[] = $body[$key];
    }

    return $result;
}

function handleGetMetadata(StorageMetadataHandler $metaRepo, \FluxFiles\Claims $claims, FileManager $fm): ?array
{
    $disk = $_GET['disk'] ?? null;
    $key  = $_GET['key'] ?? null;
    if ($disk === null) {
        throw new ApiException('Missing disk parameter', 400, 'missing_param');
    }
    if ($key === null) {
        throw new ApiException('Missing key parameter', 400, 'missing_param');
    }
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }
    if (!$claims->hasPerm('read')) {
        throw new ApiException('Permission denied: read', 403, 'permission_denied');
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    $fm->validateScopedPath($key);
    return $metaRepo->get($disk, $key);
}

function handleSaveMetadata(StorageMetadataHandler $metaRepo, DiskManager $diskManager, \FluxFiles\Claims $claims, FileManager $fm): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400, 'invalid_json');
    }

    $disk = $body['disk'] ?? null;
    $key  = $body['key'] ?? null;
    if ($disk === null) {
        throw new ApiException('Missing disk', 400, 'missing_param');
    }
    if ($key === null) {
        throw new ApiException('Missing key', 400, 'missing_param');
    }
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403, 'permission_denied');
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    $fm->assertCanModifyScopedPath($disk, $key);

    $data = [
        'title'    => $body['title'] ?? null,
        'alt_text' => $body['alt_text'] ?? null,
        'caption'  => $body['caption'] ?? null,
        'tags'     => $body['tags'] ?? null,
    ];

    $metaRepo->save($disk, $key, $data);
    $metaRepo->syncToS3Tags($disk, $key, $data, $diskManager);

    return ['saved' => true];
}

function handleDeleteMetadata(StorageMetadataHandler $metaRepo, \FluxFiles\Claims $claims, FileManager $fm): array
{
    [$disk, $key] = jsonBody('disk', 'key');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403, 'permission_denied');
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    $fm->assertCanModifyScopedPath($disk, $key);
    $metaRepo->delete($disk, $key);
    return ['deleted' => true];
}

/**
 * Serve one file on a gated (private) local disk, authenticated by a per-file
 * stream token in the query string. Honours HTTP Range so a <video>/<audio> can
 * seek without re-reading from the start. Emits raw bytes, not JSON.
 */
function handleMediaStream(): void
{
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    // Mirror the main app's guard: a misconfigured/placeholder secret must not
    // verify stream tokens either (consistency — the rest of the API is disabled).
    if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'FLUXFILES_SECRET is not configured';
        return;
    }
    try {
        $scope = \FluxFiles\StreamToken::verify(ff_str_param($_GET, 'token'), $secret);
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
        return;
    }

    $disk = $scope['disk'];
    $path = $scope['path'];

    // Reject any traversal in the (signed) path defensively.
    if ($path === '' || strpos($path, "\0") !== false
        || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid path';
        return;
    }

    $diskConfigs = require __DIR__ . '/../config/disks.php';
    $config = $diskConfigs[$disk] ?? null;
    $driver = is_array($config) ? ($config['driver'] ?? '') : '';

    // Two servable cases: a gated (private) local disk, or any SFTP disk (which
    // has no static/presigned URL, so it must be streamed through the app). S3/R2
    // use presigned URLs the browser fetches directly, so they never reach here.
    $isGatedLocal = $driver === 'local' && !empty($config['private']);
    $isSftp = $driver === 'sftp';
    if (!$isGatedLocal && !$isSftp) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Streaming not available for this disk';
        return;
    }

    // MIME by extension (don't trust on-disk sniffing for active types).
    $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
        ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    // Inline only for media/image/pdf; everything else is forced to download so a
    // stray .html/.svg can't execute in the FluxFiles origin.
    $inlineOk = (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment')
        . '; filename="' . rawurlencode(basename($path)) . '"');

    if ($isGatedLocal) {
        $root = realpath($config['root'] ?? (__DIR__ . '/../storage/uploads'));
        $abs  = realpath(($root ?: '') . '/' . $path);
        // The resolved file must stay inside the disk root (symlink / traversal guard).
        if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        // Production fast path: hand the bytes to nginx (native Range, no PHP copy).
        $xaccel = $_ENV['FLUXFILES_XACCEL'] ?? '';
        if ($xaccel !== '') {
            header('Content-Type: ' . $mime);
            header('X-Accel-Buffering: no');
            header('X-Accel-Redirect: ' . rtrim($xaccel, '/') . '/' . $path);
            return;
        }
        \FluxFiles\RangeStreamer::stream($abs, $mime, $_SERVER['HTTP_RANGE'] ?? null);
        return;
    }

    // SFTP: read through Flysystem and stream the bytes (no presign, no static URL).
    // SFTP can't do byte-range natively, so Range is not advertised — the whole
    // file is sent. SFTP is for browsing/editing VPS files, not media seeking.
    try {
        $dm = new DiskManager($diskConfigs);
        $fs = $dm->disk($disk);
        if (!$fs->fileExists($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        header('Content-Type: ' . $mime);
        $stream = $fs->readStream($path);
        while (!feof($stream)) {
            echo fread($stream, 8192);
            @flush();
        }
        if (is_resource($stream)) { fclose($stream); }
    } catch (\Throwable $e) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Stream failed';
    }
}

/**
 * Snap a requested quality to the nearest allowed step (default 80). A fixed set
 * of steps bounds the number of cache variants a single file can spawn. (Kept as
 * a local array, not a top-level const, since this file's route dispatch runs
 * before the file finishes loading — consts aren't hoisted like functions.)
 */
function ff_snap_quality($raw): int
{
    $allowedSteps = [60, 75, 80, 90];
    $q = (int) $raw;
    if ($q <= 0) {
        return 80;
    }
    $best = 80;
    $bestDiff = PHP_INT_MAX;
    foreach ($allowedSteps as $allowed) {
        $d = abs($allowed - $q);
        if ($d < $bestDiff) {
            $bestDiff = $d;
            $best = $allowed;
        }
    }
    return $best;
}

/**
 * One string request parameter, safe against an array-shaped param.
 *
 * `?token[]=a` makes `$_GET['token']` an ARRAY, and `(string) $array` emits a PHP
 * warning — which, with display_errors on, lands ahead of the JSON envelope and
 * discloses the absolute server path. Anything non-scalar collapses to the default
 * instead, so a hostile query string can only ever produce a normal 4xx.
 *
 * @param array<string,mixed> $src $_GET / $_POST / a decoded JSON body
 */
function ff_str_param(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? null;
    return is_scalar($v) ? (string) $v : $default;
}

/**
 * Public Intake endpoints (no main JWT — authenticated by the portal token itself).
 *   GET  /api/fm/intake/info?token=…       → the landing page's data (label/caps).
 *   POST /api/fm/intake/upload {token,password?} + file → anonymous file drop.
 * Emits the same JSON envelope as the main app and exits.
 */
function handleIntakePublic(string $method, string $uri): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
        if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
            throw new ApiException('FLUXFILES_SECRET is not configured', 500, 'server_error');
        }
        // Gate: the module must be installed + licensed (the portal token is the
        // per-request auth — there is no main JWT / claim on a public request).
        if (!\FluxFiles\ModuleRegistry::installed('intake')) {
            throw new ApiException("The 'intake' module is not installed on this server", 501, 'module_not_installed', ['module' => 'intake']);
        }
        if (!\FluxFiles\LicenseManager::fromEnv()->licensed('intake')) {
            throw new ApiException("The 'intake' module requires a valid license", 402, 'license_required', ['module' => 'intake']);
        }
        $module = new \FluxFiles\Intake\IntakeModule();
        $dm = new DiskManager(require __DIR__ . '/../config/disks.php');

        if ($method === 'GET' && $uri === '/api/fm/intake/info') {
            $token = ff_str_param($_GET, 'token');
            echo json_encode(['data' => $module->portalInfo($dm, $secret, $token), 'error' => null]);
            return;
        }

        if ($method === 'POST' && $uri === '/api/fm/intake/upload') {
            $token = ff_str_param($_POST, 'token');
            $password = isset($_POST['password']) ? ff_str_param($_POST, 'password') : null;
            if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ApiException('No file uploaded', 400, 'no_file');
            }
            $file = [
                'name'     => (string) $_FILES['file']['name'],
                'tmp_name' => (string) $_FILES['file']['tmp_name'],
                'size'     => (int) $_FILES['file']['size'],
                'type'     => (string) ($_FILES['file']['type'] ?? ''),
                'error'    => (int) $_FILES['file']['error'],
            ];
            // Build a FileManager from the portal token's own (write-scoped) claims.
            try {
                $payload = \FluxFiles\JwtCompat::decode($token, $secret);
            } catch (\Throwable $e) {
                throw new ApiException('Invalid or expired portal link', 403, 'intake_invalid');
            }
            $portalClaims = \FluxFiles\Claims::fromJwtPayload($payload);
            $portalFm = new FileManager($dm, $portalClaims, new StorageMetadataHandler($dm));
            $portalFm->setStreamSecret($secret);
            $res = $module->receiveUpload($portalFm, $dm, $secret, $token, $file, $password);
            echo json_encode(['data' => $res, 'error' => null]);
            return;
        }

        throw new ApiException('Not found', 404, 'not_found');
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        $r = ['data' => null, 'error' => $e->getMessage()];
        if ($e->getErrorCode() !== null) { $r['error_code'] = $e->getErrorCode(); }
        if ($e->getErrorParams()) { $r['error_params'] = $e->getErrorParams(); }
        echo json_encode($r);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['data' => null, 'error' => 'Internal server error', 'error_code' => 'server_error']);
    }
}

/**
 * Public Share endpoints (no main JWT — authenticated by the share token itself).
 *   GET  /api/fm/share/info?token=…            → the landing card (counts a view).
 *   POST /api/fm/share/unlock {token,password} → card + a short-lived grant token.
 *   GET  /api/fm/share/file?token=…[&g=][&dl=] → the bytes (or a presigned 302).
 *
 * Gated on installed + licensed only: a public request carries no main JWT, so
 * there is no claim to check (the share token IS the per-request auth). Every
 * enforcement decision — password, expiry, cap, revocation, the grant — lives in
 * the paid module; core only routes, rate-limits, and moves bytes.
 */
function handleSharePublic(string $method, string $uri): void
{
    // Set per branch, not up front: the info/unlock branches emit JSON, while
    // `file` emits bytes (or a 302) and picks its own type. The error paths below
    // set it themselves, so an early throw is still a JSON envelope.
    try {
        $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
        if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
            throw new ApiException('FLUXFILES_SECRET is not configured', 500, 'server_error');
        }
        if (!\FluxFiles\ModuleRegistry::installed('share')) {
            throw new ApiException("The 'share' module is not installed on this server", 501, 'module_not_installed', ['module' => 'share']);
        }
        if (!\FluxFiles\LicenseManager::fromEnv()->licensed('share')) {
            throw new ApiException("The 'share' module requires a valid license", 402, 'license_required', ['module' => 'share']);
        }
        $module = new \FluxFiles\Share\ShareModule();
        $diskConfigs = require __DIR__ . '/../config/disks.php';
        $dm = new DiskManager($diskConfigs);

        if ($method === 'GET' && $uri === '/api/fm/share/info') {
            $token = ff_str_param($_GET, 'token');
            ff_share_rate_limit($token, 'view');
            $out = $module->shareInfo($dm, $secret, $token);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => ff_share_payload($out, $token, $secret), 'error' => null]);
            return;
        }

        if ($method === 'POST' && $uri === '/api/fm/share/unlock') {
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($body)) { $body = []; }   // a scalar JSON body is not a form
            $token = ff_str_param($body, 'token', ff_str_param($_POST, 'token'));
            $password = ff_str_param($body, 'password', ff_str_param($_POST, 'password'));
            // Tighter bucket than info/file: this is the password brute-force surface.
            ff_share_rate_limit($token, 'unlock');
            $out = $module->unlockShare($dm, $secret, $token, $password);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => ff_share_payload($out, $token, $secret), 'error' => null]);
            return;
        }

        if ($method === 'GET' && $uri === '/api/fm/share/file') {
            $token = ff_str_param($_GET, 'token');
            ff_share_rate_limit($token, 'view');
            // Fixed order: gate → rate limit → enforce + count → THEN bytes. The
            // module verifies the grant, the expiry and the cap and increments the
            // download counter; nothing is served if it throws.
            $res = $module->resolveShare($dm, $secret, $token, null, true, isset($_GET['g']) ? ff_str_param($_GET, 'g') : null);
            ff_share_send_bytes($dm, $diskConfigs, $res, ($_GET['dl'] ?? '') === '1');
            return;
        }

        throw new ApiException('Not found', 404, 'not_found');
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        header('Content-Type: application/json; charset=utf-8');
        $r = ['data' => null, 'error' => $e->getMessage()];
        if ($e->getErrorCode() !== null) { $r['error_code'] = $e->getErrorCode(); }
        if ($e->getErrorParams()) { $r['error_params'] = $e->getErrorParams(); }
        echo json_encode($r);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => null, 'error' => 'Internal server error', 'error_code' => 'server_error']);
    }
}

/**
 * Turn the module's info/unlock result into the public JSON payload, minting the
 * preview URL core-side (the module decides IF a preview is allowed; core owns the
 * route shapes). Images preview through /api/fm/img — a bounded transform, never
 * the original bytes, and it never touches the download counter. PDFs preview
 * through the share bytes route itself, which the module only offers on an
 * uncapped share (an <iframe> of the real bytes IS a download).
 *
 * @param array{info:array<string,mixed>,preview:array<string,mixed>|null} $out
 * @return array<string,mixed>
 */
function ff_share_payload(array $out, string $token, string $secret): array
{
    $info = is_array($out['info'] ?? null) ? $out['info'] : [];
    $prev = is_array($out['preview'] ?? null) ? $out['preview'] : null;
    if ($prev === null) {
        return $info;
    }

    $url = null;
    $kind = (string) ($prev['kind'] ?? '');
    $ttl = max(1, (int) ($prev['ttl'] ?? \FluxFiles\ShareGrant::DEFAULT_TTL));
    if ($kind === 'image') {
        $imgToken = \FluxFiles\ImageToken::mint(
            (string) ($prev['disk'] ?? ''),
            (string) ($prev['path'] ?? ''),
            (string) ($prev['sub'] ?? 'share'),
            $ttl,
            $secret,
            1600
        );
        $url = '/api/fm/img?token=' . rawurlencode($imgToken) . '&width=1200';
    } elseif ($kind === 'pdf') {
        $url = '/api/fm/share/file?token=' . rawurlencode($token);
        if (!empty($info['grant'])) {
            $url .= '&g=' . rawurlencode((string) $info['grant']);
        }
    }
    if ($url === null) {
        return $info;
    }

    if (isset($info['file']) && is_array($info['file'])) {
        $info['file']['preview_url'] = $url;
    }
    if (isset($info['files'][0]) && is_array($info['files'][0])) {
        $info['files'][0]['preview_url'] = $url;
    }
    return $info;
}

/**
 * Emit the shared file's bytes. Dispatch is on the DISK DRIVER, never on the
 * file's `url`: a public local disk has a static URL that would bypass the
 * password/cap entirely, so local always streams through the app.
 *
 *   s3 (S3 + R2)          → 302 to a short-TTL presigned URL (no app egress)
 *   local (private/public)→ streamed here (Range-capable; never the static URL)
 *   sftp                  → streamed here (no presign, no static URL)
 *
 * @param array<string,mixed> $diskConfigs
 * @param array<string,mixed> $res resolveShare() result (disk/path/name/mime/url_ttl)
 */
function ff_share_send_bytes(DiskManager $dm, array $diskConfigs, array $res, bool $forceDownload): void
{
    $disk = (string) ($res['disk'] ?? '');
    $path = (string) ($res['path'] ?? '');
    // The path comes from the SIGNED record, but reject a traversal defensively.
    if ($path === '' || strpos($path, "\0") !== false
        || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
        throw new ApiException('Invalid share', 403, 'share_invalid');
    }
    $name = basename($path);
    // MIME by extension (never sniffed — an .html that sniffs as text/html must not
    // become active content in the FluxFiles origin).
    $mime = (string) ($res['mime'] ?? '');
    if ($mime === '') {
        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }
    // Inline only for the same safe set as handleMediaStream; everything else (and
    // an explicit ?dl=1) is forced to attachment.
    $inline = !$forceDownload && (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);
    $disposition = ff_content_disposition($name, $inline);

    $cfg = is_array($diskConfigs[$disk] ?? null) ? $diskConfigs[$disk] : [];
    $driver = (string) ($cfg['driver'] ?? '');

    if ($driver === 's3') {
        // Documented limitation: on S3/R2 the cap counts GRANTS, not downloads — a
        // handed-out presigned URL stays fetchable until it expires. share_url_ttl
        // (default 60s, clamped 10–300) bounds that window.
        $url = $dm->presignGetUrl($disk, $path, max(10, (int) ($res['url_ttl'] ?? 60)), $disposition);
        if ($url === null) {
            throw new ApiException('This file is temporarily unavailable', 502, 'share_unavailable');
        }
        ff_share_headers($disposition);
        header('Location: ' . $url, true, 302);
        return;
    }

    if ($driver === 'local') {
        $root = realpath($cfg['root'] ?? (__DIR__ . '/../storage/uploads'));
        $abs  = realpath(($root ?: '') . '/' . $path);
        // The resolved file must stay inside the disk root (symlink / traversal guard).
        if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
            throw new ApiException('This file is no longer available', 404, 'share_gone');
        }
        ff_share_headers($disposition);
        // Production fast path for a gated (private) local disk — the same nginx
        // internal location /api/fm/stream uses. Public local disks stream through
        // PHP (their root isn't the X-Accel location).
        $xaccel = $_ENV['FLUXFILES_XACCEL'] ?? '';
        if ($xaccel !== '' && !empty($cfg['private'])) {
            header('Content-Type: ' . $mime);
            header('X-Accel-Buffering: no');
            header('X-Accel-Redirect: ' . rtrim($xaccel, '/') . '/' . $path);
            return;
        }
        \FluxFiles\RangeStreamer::stream($abs, $mime, $_SERVER['HTTP_RANGE'] ?? null);
        return;
    }

    if ($driver === 'sftp') {
        $stream = null;
        try {
            $fs = $dm->disk($disk);
            if (!$fs->fileExists($path)) {
                throw new ApiException('This file is no longer available', 404, 'share_gone');
            }
            $size = (int) ($res['size'] ?? 0);
            $stream = $fs->readStream($path);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Nothing has been written yet, so a JSON envelope is still correct.
            throw new ApiException('This file is temporarily unavailable', 502, 'share_unavailable');
        }
        ff_share_headers($disposition);
        header('Content-Type: ' . $mime);
        // Content-Length so a truncated transfer is detectable as truncated (no
        // Range support over SFTP here — say so rather than imply it).
        if ($size > 0) { header('Content-Length: ' . $size); }
        header('Accept-Ranges: none');
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) { break; }
                echo $chunk;
                @flush();
            }
        } catch (\Throwable $e) {
            // Mid-stream: the headers and part of the body are already out, so an
            // exception here would append a JSON envelope to partial FILE BYTES (and
            // warn about headers already sent). Stop instead — the short body against
            // the declared Content-Length is the client's signal.
            error_log('FluxFiles share sftp stream failed: ' . $e->getMessage());
        } finally {
            if (is_resource($stream)) { fclose($stream); }
        }
        return;
    }

    throw new ApiException('This file is temporarily unavailable', 502, 'share_unavailable');
}

/** Common response headers for the share bytes route (and its presigned redirect). */
function ff_share_headers(string $disposition): void
{
    header('Content-Disposition: ' . $disposition);
    header('X-Content-Type-Options: nosniff');
    // A shared .html/.svg must not run in the FluxFiles origin, and the token must
    // not leak to a sub-resource or an outbound link.
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
}

/** RFC-5987 Content-Disposition from a display filename (never the storage key). */
function ff_content_disposition(string $name, bool $inline): string
{
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'file';
    $ascii = str_replace(['"', '\\'], '_', $ascii);
    return ($inline ? 'inline' : 'attachment')
        . '; filename="' . $ascii . '"'
        . "; filename*=UTF-8''" . rawurlencode($name);
}

/**
 * Per-share rate limit for the public share routes, using the existing JSON-file
 * limiter: `view` (info + file) and the tighter `unlock` (password attempts, keyed
 * by share + client IP). The share id is read from the UNVERIFIED payload — it's
 * only a bucket key, and tampering it invalidates the signature anyway, so it
 * can't be used to escape the bucket with a working token.
 */
function ff_share_rate_limit(string $token, string $kind): void
{
    $storagePath = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
    $jti = ff_share_token_jti($token);
    $limiter = static function (int $limit) use ($storagePath): RateLimiterFileStorage {
        return new RateLimiterFileStorage($storagePath . '/rate_limit.json', $limit, $limit, 60);
    };

    if ($kind === 'unlock') {
        // TWO buckets, because either one alone is escapable or self-DoSing:
        //  · per share + client IP — stops one guesser, but REMOTE_ADDR is free to
        //    rotate (proxy pool, a single IPv6 /64), so it cannot be the only limit;
        //  · per share, no attacker-controlled component — the real ceiling. It must
        //    stay well above a shared-office NAT (every recipient behind one IP shares
        //    the first bucket, so a handful of people mistyping a password must not
        //    lock the link), hence a roomier default of 30/min for a whole share.
        // Both throw the already-translated `rate_limited` (429).
        $limiter(max(1, (int) ($_ENV['FLUXFILES_SHARE_UNLOCK_LIMIT'] ?? 5)))
            ->check('share_unlock:' . $jti . ':' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'read');
        $limiter(max(1, (int) ($_ENV['FLUXFILES_SHARE_UNLOCK_TOTAL'] ?? 30)))
            ->check('share_unlock_all:' . $jti, 'read');
        return;
    }

    $limiter(max(1, (int) ($_ENV['FLUXFILES_SHARE_RATE_LIMIT'] ?? 60)))->check('share:' . $jti, 'read');
}

/** The share id from a token's unverified payload; a hash of the token otherwise. */
function ff_share_token_jti(string $token): string
{
    $parts = explode('.', $token);
    if (count($parts) === 3) {
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $jti = is_array($payload) ? ($payload['jti'] ?? '') : '';
        if (is_string($jti) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti)) {
            return $jti;
        }
    }
    return substr(hash('sha256', $token), 0, 24);
}

/** This request's own origin (scheme + host), honouring a TLS-terminating proxy. */
function ff_request_origin(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($isHttps ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Serve an on-demand WebP transform of one image, cached in the file's
 * _variants/ directory. Authenticated by an image token (query string). The width
 * is rounded to 100px and clamped to the tenant's max so the number of cacheable
 * variants per file is mathematically bounded (no per-request counting needed).
 */
function handleImageTransform(): void
{
    $secret = $_ENV['FLUXFILES_SECRET'] ?? '';
    if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'FLUXFILES_SECRET is not configured';
        return;
    }
    try {
        $scope = \FluxFiles\ImageToken::verify(ff_str_param($_GET, 'token'), $secret);
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
        return;
    }

    $disk = $scope['disk'];
    $path = $scope['path'];
    if ($path === '' || strpos($path, "\0") !== false
        || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid path';
        return;
    }

    $optimizer = new \FluxFiles\ImageOptimizer();
    if (!$optimizer->isImage($path)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not an image';
        return;
    }

    // DPR: device-pixel-ratio multiplier (1–3, snapped) so a CSS width can be
    // requested while the physical pixels honor a retina screen. Applied to the
    // requested width/height BEFORE clamping to the tenant max.
    $dpr = (float) ($_GET['dpr'] ?? 1);
    $dpr = $dpr >= 2.5 ? 3.0 : ($dpr >= 1.5 ? 2.0 : 1.0);

    // Width: round to 100px, clamp to the tenant max (mw, default 2000). 0 = keep size.
    $maxWidth = $scope['maxWidth'] > 0 ? $scope['maxWidth'] : 2000;
    $reqWidth = (int) round(((int) ($_GET['width'] ?? 0)) * $dpr);
    $width = $reqWidth > 0 ? min($maxWidth, max(100, (int) round($reqWidth / 100) * 100)) : 0;
    // Height + fit (box sizing): height rounds to 100px and clamps to the same
    // dimension ceiling; fit 'cover' crops to fill, else 'contain' fits within.
    $reqHeight = (int) round(((int) ($_GET['height'] ?? 0)) * $dpr);
    $height = $reqHeight > 0 ? min($maxWidth, max(100, (int) round($reqHeight / 100) * 100)) : 0;
    $fit = (($_GET['fit'] ?? 'contain') === 'cover') ? 'cover' : 'contain';
    $defaultQuality = $scope['defaultQuality'] > 0 ? $scope['defaultQuality'] : 80;
    $quality = ff_snap_quality($_GET['quality'] ?? $defaultQuality);

    // Output format: 'avif'/'webp' force it; 'auto' (default) content-negotiates
    // from Accept — AVIF first (smallest, when the build supports it), then WebP,
    // else '' = serve the original for ancient clients (resolved below).
    $reqFormat = strtolower(ff_str_param($_GET, 'format', 'auto'));
    $avifOk = $optimizer->avifSupported();
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($reqFormat === 'avif') {
        $format = $avifOk ? 'avif' : 'webp';
    } elseif ($reqFormat === 'webp') {
        $format = 'webp';
    } elseif ($avifOk && strpos($accept, 'image/avif') !== false) {
        $format = 'avif';
    } elseif (strpos($accept, 'image/webp') !== false) {
        $format = 'webp';
    } else {
        $format = ''; // negotiation: client accepts neither modern format
    }

    $diskConfigs = require __DIR__ . '/../config/disks.php';
    try {
        $dm = new DiskManager($diskConfigs);
        $fs = $dm->disk($disk);
    } catch (\Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Disk not available';
        return;
    }

    if (!$fs->fileExists($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return;
    }

    $origMime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
        ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';

    // Resolve the effective watermark from the token (logo presence → text
    // fallback) WITHOUT reading the logo bytes yet — enough for the cache key.
    [$wmEnabled, $wmSigCfg, $logoVer, $wmLogoPath] = ff_resolve_watermark($fs, $scope['watermark'] ?? null, $scope['sub']);
    $wmSig = \FluxFiles\ImageOptimizer::watermarkSignature($wmSigCfg, $logoVer);

    // Content negotiation: a client that accepts neither AVIF nor WebP → serve
    // the original unchanged (old browsers). NEVER for a watermarked token — that
    // would hand back the clean image and defeat the watermark; force WebP there.
    if ($format === '') {
        if (!$wmEnabled) {
            ff_serve_bytes((string) $fs->read($path), $origMime);
            return;
        }
        $format = 'webp';
    }

    // Cache key is stamped with the source mtime (+ watermark signature, format,
    // height/fit) so a re-upload, config/logo change, or a different output
    // format/size never re-matches a stale image.
    $ver = (string) (@$fs->lastModified($path) ?: '0');
    $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, $wmSig, $format, $height, $fit);
    $outMime = 'image/' . $format;

    if ($fs->fileExists($cacheKey)) {
        // S3/R2: redirect to a presigned URL of the cached image so the bucket
        // serves the bytes directly (no app-server egress). Local disks read
        // cheaply, so we just serve them.
        if (($diskConfigs[$disk]['driver'] ?? '') === 's3') {
            $redirect = $dm->presignGetUrl($disk, $cacheKey, 3600);
            if ($redirect !== null) {
                header('Cache-Control: private, max-age=600');
                header('Vary: Accept');
                header('Location: ' . $redirect, true, 302);
                return;
            }
        }
        ff_serve_bytes((string) $fs->read($cacheKey), $outMime, true);
        return;
    }

    // Cache miss: build the full watermark config (now reading the logo bytes).
    $wmCfg = null;
    if ($wmEnabled) {
        $wmCfg = $wmSigCfg;
        if (($wmSigCfg['type'] ?? '') === 'logo' && $wmLogoPath !== '') {
            $wmCfg['logo_data'] = (string) $fs->read($wmLogoPath);
        }
    }

    $out = $optimizer->transform((string) $fs->read($path), $width, $quality, $wmCfg, $format, $height, $fit);
    if ($out === null) {
        // Animated GIF / SVG / non-raster / bomb. A watermarked token must not
        // leak the clean original, so refuse rather than serve it untouched.
        if ($wmEnabled) {
            http_response_code(415);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Cannot watermark this image type';
            return;
        }
        ff_serve_bytes((string) $fs->read($path), $origMime);
        return;
    }

    // Honor the format transform() actually produced (it falls back to WebP if an
    // AVIF encode isn't available at runtime) so the cache key + Content-Type can
    // never disagree with the bytes.
    $outFormat = $out['format'] ?? $format;
    if ($outFormat !== $format) {
        $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, $wmSig, $outFormat, $height, $fit);
        $outMime = 'image/' . $outFormat;
    }
    try { $fs->write($cacheKey, $out['data']); } catch (\Throwable $e) { /* best-effort cache */ }
    ff_serve_bytes($out['data'], $outMime, true);
}

/**
 * Resolve a token's watermark into a config usable for the cache key, choosing
 * the logo-vs-text fallback up front (reads only the logo's mtime, not its bytes).
 * Returns [enabled, sigConfig|null, logoVer|null, logoPath]. A missing logo file
 * falls back to a text watermark (warning header) — never to a clean image.
 *
 * @return array{0:bool,1:array|null,2:int|null,3:string}
 */
function ff_resolve_watermark($fs, ?array $wm, string $sub): array
{
    if (!is_array($wm) || empty($wm['enabled'])) {
        return [false, null, null, ''];
    }
    $position = in_array($wm['position'] ?? '', \FluxFiles\ImageOptimizer::WM_POSITIONS, true)
        ? (string) $wm['position'] : 'bottom-right';
    $opacity = max(0.0, min(1.0, (float) ($wm['opacity'] ?? 0.6)));
    $fontSize = max(8, min(200, (int) ($wm['font_size'] ?? 24)));
    $text = trim((string) ($wm['text'] ?? ''));
    $logoPath = (string) ($wm['logo_path'] ?? '');
    $logoSafe = $logoPath !== '' && strpos($logoPath, "\0") === false
        && !preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $logoPath));

    if (($wm['type'] ?? 'text') === 'logo' && $logoSafe && $fs->fileExists($logoPath)) {
        $sig = ['enabled' => true, 'type' => 'logo', 'position' => $position, 'opacity' => $opacity, 'logo_path' => $logoPath];
        return [true, $sig, (int) (@$fs->lastModified($logoPath) ?: 0), $logoPath];
    }

    if (($wm['type'] ?? 'text') === 'logo') {
        // Logo configured but missing/unsafe → fall back to text, never clean.
        header('X-FluxFiles-Warning: watermark-logo-missing');
        if ($text === '') {
            $text = $sub !== '' ? $sub : 'PREVIEW';
        }
    }
    if ($text === '') {
        $text = $sub !== '' ? $sub : 'PREVIEW';
    }
    $sig = ['enabled' => true, 'type' => 'text', 'text' => $text, 'position' => $position, 'opacity' => $opacity, 'font_size' => $fontSize];
    return [true, $sig, null, ''];
}

/** Emit image bytes with safe headers; $immutable adds a long-lived cache policy. */
function ff_serve_bytes(string $data, string $mime, bool $immutable = false): void
{
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    // Output format is content-negotiated from Accept (format=auto → avif/webp),
    // so caches must key on it to avoid serving the wrong format to a client.
    header('Vary: Accept');
    header($immutable
        ? 'Cache-Control: public, max-age=31536000, immutable'
        : 'Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($data));
    echo $data;
}

/**
 * Storage usage dashboard. One recursive pass over the prefix (via
 * getUsageBreakdown) → quota + by-type + top-folders, cached in
 * `_fluxfiles/usage.json` (per prefix, TTL = usage_cache_ttl). `?refresh=true`
 * bypasses the cache (rate-limited upstream).
 */
function handleUsage(QuotaManager $quotaManager, DiskManager $diskManager, \FluxFiles\Claims $claims): array
{
    $disk = $_GET['disk'] ?? 'local';
    $prefix = $claims->pathPrefix;
    $refresh = ($_GET['refresh'] ?? '') === 'true';
    $ttl = $claims->usageCacheTtl > 0 ? $claims->usageCacheTtl : 900;

    $fs = $diskManager->disk($disk);
    // Cache lives in the tenant's own prefix tree (so each prefix has its own
    // file — no cross-tenant contention) and is excluded from the breakdown.
    $pt = trim($prefix, '/');
    $cachePath = ($pt !== '' ? $pt . '/' : '') . '_fluxfiles/usage.json';

    // Cumulative "bytes saved" by the Optimization module — read fresh (cheap, and
    // it changes on every optimize, so it's never cached with the breakdown).
    $optimizeStats = \FluxFiles\OptimizeStats::read($fs, $prefix);

    if (!$refresh && $ttl > 0) {
        $cached = ff_usage_cache_read($fs, $cachePath, $ttl);
        if ($cached !== null) {
            $cached['cache_age_seconds'] = max(0, time() - (int) strtotime($cached['computed_at'] ?? 'now'));
            $cached['optimize'] = $optimizeStats;
            return $cached;
        }
    }

    $top = $claims->usageTopFoldersCount > 0 ? $claims->usageTopFoldersCount : 10;
    $depth = $claims->usageFolderDepth > 0 ? $claims->usageFolderDepth : 1;
    $breakdown = $quotaManager->getUsageBreakdown($disk, $prefix, $top, $depth);
    $resp = $quotaManager->usageResponse(
        $breakdown,
        $claims->maxStorageMb,
        $claims->usageWarningThreshold,
        $claims->usageCriticalThreshold
    );

    if ($ttl > 0) {
        ff_usage_cache_write($fs, $cachePath, $resp);
    }
    $resp['cache_age_seconds'] = 0;
    $resp['optimize'] = $optimizeStats;
    return $resp;
}

/** Read a fresh (< $ttl) cached usage summary at $path, or null. */
function ff_usage_cache_read($fs, string $path, int $ttl): ?array
{
    try {
        if (!$fs->fileExists($path)) {
            return null;
        }
        $entry = json_decode((string) $fs->read($path), true);
        if (!is_array($entry)) {
            return null;
        }
        $age = time() - (int) strtotime($entry['computed_at'] ?? '');
        return ($age >= 0 && $age <= $ttl) ? $entry : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Best-effort write of a usage summary to its prefix-scoped cache file. */
function ff_usage_cache_write($fs, string $path, array $resp): void
{
    try {
        $fs->write($path, (string) json_encode($resp));
    } catch (\Throwable $e) {
        /* cache is best-effort */
    }
}

function handlePresign(FileManager $fm): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400, 'invalid_json');
    }

    foreach (['disk', 'path', 'method', 'ttl'] as $key) {
        if (!isset($body[$key])) {
            throw new ApiException("Missing required field: {$key}", 400);
        }
    }

    return $fm->presign(
        (string) $body['disk'],
        (string) $body['path'],
        strtoupper((string) $body['method']),
        (int) $body['ttl'],
        (int) ($body['size'] ?? $body['size_bytes'] ?? 0)
    );
}

function handleChunkInit(
    \FluxFiles\ChunkUploader $chunker,
    \FluxFiles\Claims $claims,
    FileManager $fm,
    \FluxFiles\QuotaManager $quotaManager
): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403, 'permission_denied');
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400, 'invalid_json');
    }

    $disk = $body['disk'] ?? null;
    $path = $body['path'] ?? null;
    if ($disk === null || $path === null) {
        throw new ApiException('Missing required field: disk or path', 400);
    }

    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }

    $sizeBytes = (int) ($body['size'] ?? $body['size_bytes'] ?? 0);
    if ($sizeBytes <= 0) {
        throw new ApiException('Missing required field: size', 400, 'missing_param');
    }
    $scopedPath = $fm->validateUserPath((string) $path);
    $fm->validateUploadName(basename($scopedPath), $sizeBytes);

    if ($claims->maxStorageMb > 0 && $sizeBytes > 0) {
        $quotaManager->assertQuota($disk, $claims->pathPrefix, $sizeBytes, $claims->maxStorageMb);
    }

    if ($claims->maxFiles > 0) {
        $quotaManager->assertFileCount($disk, $claims->pathPrefix, 1, $claims->maxFiles);
    }

    return $chunker->initiate($disk, $scopedPath);
}

function handleChunkPresign(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims, FileManager $fm): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403, 'permission_denied');
    }
    [$disk, $key, $uploadId, $partNumber] = jsonBody('disk', 'key', 'upload_id', 'part_number');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    $fm->validateScopedPath($key);
    return $chunker->presignPart($disk, $key, $uploadId, (int) $partNumber);
}

function handleChunkComplete(
    \FluxFiles\ChunkUploader $chunker,
    \FluxFiles\Claims $claims,
    FileManager $fm,
    StorageMetadataHandler $metaRepo
): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403, 'permission_denied');
    }
    [$disk, $key, $uploadId, $parts] = jsonBody('disk', 'key', 'upload_id', 'parts');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    $fm->validateScopedPath($key);
    $result = $chunker->complete($disk, $key, $uploadId, $parts);
    $metaRepo->save($disk, $key, [
        'uploaded_by' => $claims->userId,
    ]);
    return $result;
}

function handleChunkAbort(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims, FileManager $fm): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403, 'permission_denied');
    }
    [$disk, $key, $uploadId] = jsonBody('disk', 'key', 'upload_id');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    $fm->validateScopedPath($key);
    return $chunker->abort($disk, $key, $uploadId);
}

function handleCrop(FileManager $fm): array
{
    [$disk, $path, $x, $y, $width, $height] = jsonBody('disk', 'path', 'x', 'y', 'width', 'height');

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $savePath = $body['save_path'] ?? null;

    return $fm->cropImage(
        $disk,
        $path,
        (int) $x,
        (int) $y,
        (int) $width,
        (int) $height,
        $savePath
    );
}

<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';
// The public Share/Intake handlers. Their own file so the WordPress plugin can serve
// the same routes without including this one — including this one would run auth and
// routing as a side effect.
require_once __DIR__ . '/PublicLinks.php';

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\BucketDoctor;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;

// Load config: real env vars first, then .env for anything still unset (EnvBootstrap)
$envDirs = [
    // Package root (default when installed via composer)
    realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'),
    // Monorepo root (developer checkout): packages/core/api -> repo root
    realpath(__DIR__ . '/../../..') ?: (__DIR__ . '/../../..'),
];

\FluxFiles\EnvBootstrap::load($envDirs);

// Trusted SFTP/import hosts allowed past the SSRF public-IP requirement. Legit
// use: an operator whose SFTP server lives on their own private/reserved network
// (a VPS behind a VPN). Comma-separated host[:port]; empty = full SSRF protection.
\FluxFiles\SsrfGuard::setAllowHosts((string) ($_ENV['FLUXFILES_SSRF_ALLOW_HOSTS'] ?? ''));

// CORS
$allowedOrigins = array_filter(
    array_map('trim', explode(',', $_ENV['FLUXFILES_ALLOWED_ORIGINS'] ?? ''))
);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, HEAD, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Tus-Resumable, Upload-Length, Upload-Metadata, Upload-Offset');
    // Resumable (tus) uploads read these from a cross-origin response.
    header('Access-Control-Expose-Headers: Location, Tus-Resumable, Tus-Version, Tus-Extension, Upload-Offset, Upload-Length, Upload-Expires, X-FluxFiles-Result');
    header('Access-Control-Max-Age: 86400');
}

// Preflight. A tus client's OPTIONS (server discovery) is answered here too —
// it carries no token, so it never reached TusServer anyway; the size cap is
// per-token and only enforced at creation.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $preflightPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($preflightPath === '/api/fm/tus' || str_starts_with($preflightPath, '/api/fm/tus/')) {
        header('Tus-Resumable: ' . \FluxFiles\TusServer::VERSION);
        header('Tus-Version: ' . \FluxFiles\TusServer::VERSION);
        header('Tus-Extension: creation,termination');
    }
    http_response_code(204);
    exit;
}

// CSRF protection: verify the Origin header on mutating requests. Browsers always
// send Origin on cross-site mutating requests, so a forged one can be rejected.
// When no allowlist is configured we fall back to same-origin only — never "allow
// all" (the previous behaviour silently skipped the check on an empty allowlist).
// Requests with no Origin (non-browser API clients) are unaffected: they carry the
// JWT bearer token and are not a CSRF vector.
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
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
    // Clickjacking: constrain who may frame the UI. There is no safe universal
    // default — the whole product is an iframe embed, so `frame-ancestors 'self'`
    // would break every working install. Precedence:
    //   1. FLUXFILES_FRAME_ANCESTORS — explicit source list, verbatim.
    //   2. FLUXFILES_ALLOWED_ORIGINS — the hosts already trusted for CORS.
    //   3. neither set → no header (unchanged behaviour), which is the case an
    //      operator should fix; docs/reference/CONFIG.md says so.
    // X-Frame-Options is deliberately NOT sent alongside: it has no multi-origin
    // form, so it would contradict an allow-list that CSP is expressing correctly.
    $frameAncestors = trim((string) ($_ENV['FLUXFILES_FRAME_ANCESTORS'] ?? ''));
    if ($frameAncestors === '' && $allowedOrigins !== []) {
        $frameAncestors = "'self' " . implode(' ', $allowedOrigins);
    }
    if ($frameAncestors !== '' && preg_match('/^[A-Za-z0-9 :\/\.\*\-\']+$/', $frameAncestors)) {
        header('Content-Security-Policy: frame-ancestors ' . $frameAncestors);
    }
    $localeJson = $i18n->toJson();
    $locale = $i18n->locale();
    $dir = $i18n->direction();
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    // Cache-bust the UI assets with a short content hash, so a core update is never
    // served from a stale browser/proxy cache (the static fm.js/fm.css URLs carry no
    // version of their own). Per-file hash → each invalidates only when it changes.
    // Prefer the pre-minified fm.min.js / fm.min.css (built by scripts/build-ui.sh and
    // committed) when present; FLUXFILES_DEBUG=1 serves the readable sources instead.
    $debugUi = in_array(strtolower((string) ($_ENV['FLUXFILES_DEBUG'] ?? '')), ['1', 'true', 'yes', 'on'], true);
    $assetFile = static function (string $file) use ($debugUi): string {
        $min = preg_replace('/\.(js|css)$/', '.min.$1', $file);
        return (!$debugUi && is_file(__DIR__ . '/../assets/' . $min)) ? $min : $file;
    };
    $assetVer = static function (string $file): string {
        $p = __DIR__ . '/../assets/' . $file;
        return is_file($p) ? substr(md5_file($p), 0, 10) : (string) time();
    };
    $jsFile = $assetFile('fm.js');
    $cssFile = $assetFile('fm.css');
    $html = str_replace(
        ['../assets/fm.css"', '../assets/fm.js"', '../assets/vendor/alpine/alpine.min.js"'],
        [
            '../assets/' . $cssFile . '?v=' . $assetVer($cssFile) . '"',
            '../assets/' . $jsFile . '?v=' . $assetVer($jsFile) . '"',
            '../assets/vendor/alpine/alpine.min.js?v=' . $assetVer('vendor/alpine/alpine.min.js') . '"',
        ],
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
    // The tag carries more attributes (translate="no"), so match the opening only.
    $html = preg_replace('/<html lang="en"/', '<html lang="' . htmlspecialchars($locale) . '" dir="' . htmlspecialchars($dir) . '"', $html, 1) ?? $html;

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

    // SSO bridge (paid module): tell the standalone UI whether to show the
    // "Sign in with SSO" button. Non-sensitive — a bool + the fixed, public login
    // path; the real IdP/client config never leaves the server.
    if (($_ENV['FLUXFILES_SSO_ENABLED'] ?? '') === 'true' && \FluxFiles\ModuleRegistry::installed('sso')) {
        $ssoJson = json_encode(['enabled' => true, 'loginUrl' => '/api/fm/sso/login'], $jsFlags);
        $html = str_replace('</head>', "<script>window.__FM_SSO__ = {$ssoJson};</script>\n</head>", $html);
    }

    // Conditional GET: the page is dynamic (locale + asset hashes) but identical
    // between visits, so let the browser revalidate for a 304 instead of
    // re-downloading ~250 KB. `no-cache` stays — it means "revalidate", not "don't
    // store". Per-request injections that make the body unique to this response:
    //   - DemoMode __FM_BOOT__ (a fresh per-visitor token) -> never ETag
    //   - the time() fallback of $assetVer when an asset file is missing (fm.js,
    //     fm.css and Alpine are all checked below)
    // Everything else (locale, asset hashes, SSO flag, frame-ancestors) is a pure
    // function of env + files, so it is covered by hashing the final HTML.
    if (!\FluxFiles\DemoMode::enabled() && is_file(__DIR__ . '/../assets/fm.js') && is_file(__DIR__ . '/../assets/fm.css')
        && is_file(__DIR__ . '/../assets/vendor/alpine/alpine.min.js')) {
        $etag = '"' . md5($html) . '"';
        header('ETag: ' . $etag);
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($inm !== '' && (trim($inm) === '*' || (preg_match_all('#(?:W/)?("[^"]*")#', $inm, $em) && in_array($etag, $em[1], true)))) {
            http_response_code(304);
            exit;
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
        // Only `_meta` is needed, and it leads every locale file — decode just that
        // object from the first KB instead of parsing all ~30 KB x 16 files.
        $head = (string) file_get_contents($f, false, null, 0, 2048);
        $meta = null;
        if (preg_match('/"_meta"\s*:\s*(\{[^{}]*\})/', $head, $mm)) {
            $meta = json_decode($mm[1], true);
        }
        if (!is_array($meta)) { // unexpected layout → full parse, as before
            $data = json_decode(file_get_contents($f), true);
            if (!is_array($data)) continue;
            $meta = $data['_meta'] ?? [];
        }
        $code = $meta['locale'] ?? basename($f, '.json');
        $result[] = [
            'code' => $code,
            'name' => $meta['name'] ?? $code,
            'dir'  => $meta['direction'] ?? 'ltr',
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
    ff_media_endpoint()->stream($_GET, \FluxFiles\MediaEndpoint::headersFromServer($_SERVER));
    exit;
}

// On-demand WebP transform — authenticated by a per-file image token in the query
// string (an <img> can't send an Authorization header). Serves a resized WebP from
// (or into) the file's _variants/ cache. Never raw bytes of an arbitrary file.
if ($method === 'GET' && $uri === '/api/fm/img') {
    ff_media_endpoint()->image($_GET, \FluxFiles\MediaEndpoint::headersFromServer($_SERVER));
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

// SSO bridge (paid module) — PUBLIC endpoints, no main JWT (there isn't one yet).
// `login` 302s to the IdP, `callback` completes the OIDC exchange and 302s back to
// the UI with a one-time bootstrap token in the URL *fragment*, `exchange` trades
// that fragment token for the real access JWT via a POST body. Gated by the
// FLUXFILES_SSO_ENABLED server flag + the module's own install/license layers,
// checked inside each handler — never by a JWT claim (there's no token yet).
if ($method === 'GET' && $uri === '/api/fm/sso/login') {
    handleSsoLogin();
    exit;
}
if ($method === 'GET' && $uri === '/api/fm/sso/callback') {
    handleSsoCallback();
    exit;
}
if ($method === 'POST' && $uri === '/api/fm/sso/exchange') {
    handleSsoExchange();
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
    $storageBackend = $_ENV['FLUXFILES_STORAGE_BACKEND'] ?? 'json';
    $dbConn = null;
    if ($storageBackend === 'db') {
        $dbConn = \FluxFiles\Db\Connection::fromEnv();
        if (($_ENV['FLUXFILES_DB_AUTO_MIGRATE'] ?? 'false') === 'true') {
            (new \FluxFiles\Db\MigrationRunner($dbConn))->migrate(__DIR__ . '/../db/migrations');
        }
        $metaRepo = new \FluxFiles\Db\DbMetadataHandler($dbConn, $diskManager);
    } else {
        $metaRepo = new StorageMetadataHandler($diskManager);
    }
    $fm = new FileManager($diskManager, $claims, $metaRepo);

    // AI Tagger (optional) — built when the operator configured a provider.
    $aiTagger = null;
    $aiProvider = $_ENV['FLUXFILES_AI_PROVIDER'] ?? '';
    if ($aiProvider !== '') {
        $aiTagger = new \FluxFiles\AiTagger(
            $aiProvider,
            $_ENV['FLUXFILES_AI_API_KEY'] ?? '',
            $_ENV['FLUXFILES_AI_MODEL'] ?? null,
            $_ENV['FLUXFILES_AI_BASE_URL'] ?? null
        );
    }

    // Quota, stream secret, optimizer, AI, versioning, virus/DLP (fail-closed — the
    // module gate lives inside the callbacks), all in one place.
    \FluxFiles\FileManagerFactory::wire($fm, $claims, $diskManager, $metaRepo, $secret, $aiTagger);

    // Webhooks (paid module): fires after the response; see FileManagerFactory.
    $webhookDispatcher = \FluxFiles\FileManagerFactory::webhookDispatcher($claims, $secret);
    $webhookEvent = null;

    // Rate limiting (JSON file). Per-tenant `rate_read`/`rate_write` claims override
    // the server defaults when set (> 0); otherwise inherit the env limits.
    $rateLimiter = \FluxFiles\RateLimiterFactory::make(
        $claims->rateRead > 0 ? $claims->rateRead : (int) ($_ENV['FLUXFILES_RATE_LIMIT_READ'] ?? 60),
        $claims->rateWrite > 0 ? $claims->rateWrite : (int) ($_ENV['FLUXFILES_RATE_LIMIT_WRITE'] ?? 10),
        60,
        $dbConn
    );
    $isWriteAction = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $isTus = $uri === '/api/fm/tus' || str_starts_with($uri, '/api/fm/tus/');
    if ($isTus && in_array($method, ['PATCH', 'HEAD'], true)) {
        // One large file = hundreds of PATCH chunks: its own roomy bucket, or the
        // general write limit (10/min) would stall every big upload.
        $tusLimit = (int) ($_ENV['FLUXFILES_TUS_RATE_LIMIT'] ?? 600);
        \FluxFiles\RateLimiterFactory::make($tusLimit, $tusLimit, 60, $dbConn)->check($claims->userId, 'tus');
    } else {
        $rateLimiter->check($claims->userId, $isWriteAction ? 'write' : 'read');
    }

    // URL import is an outbound HTTP request from the server — give it its own,
    // tighter bucket (default 10/min) so it can't be abused at the upload rate.
    if ($uri === '/api/fm/import-url') {
        $importLimit = $claims->importRateLimit > 0
            ? $claims->importRateLimit
            : (int) ($_ENV['FLUXFILES_IMPORT_RATE_LIMIT'] ?? 10);
        \FluxFiles\RateLimiterFactory::make($importLimit, $importLimit, 60, $dbConn)
            ->check($claims->userId, 'import');
    }

    // A forced usage recompute lists the whole prefix — give it its own tight
    // bucket (2/min) so it can't be abused to hammer the storage with ListObjects.
    if (($uri === '/api/fm/usage' && ($_GET['refresh'] ?? '') === 'true') || $uri === '/api/fm/duplicates') {
        \FluxFiles\RateLimiterFactory::make(2, 2, 60, $dbConn)
            ->check($claims->userId, 'usage_refresh');
    }

    // Audit log (lưu trong user storage)
    $auditLog = new AuditLogStorage($metaRepo, $claims->allowedDisks);
    $chunker = new \FluxFiles\ChunkUploader($diskManager);
    $quotaManager = new QuotaManager($diskManager);

    // Resumable uploads (tus 1.0) — not a JSON-envelope API: status + headers only.
    if ($isTus) {
        $tusHeaders = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $tusHeaders[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $tusHeaders['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $tusBody = fopen('php://input', 'rb');
        [$tusStatus, $tusOut, $tusDone] = (new \FluxFiles\TusServer($storagePath))->handle(
            $method,
            $uri === '/api/fm/tus' ? '' : substr($uri, strlen('/api/fm/tus/')),
            $tusHeaders,
            $tusBody,
            $fm,
            $claims
        );
        header_remove('Content-Type');
        http_response_code($tusStatus);
        foreach ($tusOut as $hk => $hv) {
            header($hk . ': ' . $hv);
        }
        if ($tusDone !== null) {
            // The finished file, for the client (the PATCH itself has no body).
            header('X-FluxFiles-Result: ' . base64_encode((string) json_encode($tusDone)));
            $auditLog->log($claims->userId, 'upload', (string) ($tusDone['disk'] ?? 'local'), $claims->scopePath((string) ($tusDone['key'] ?? '')));
            if ($webhookDispatcher !== null) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $webhookDispatcher('upload', ['disk' => (string) ($tusDone['disk'] ?? 'local'), 'path' => $claims->scopePath((string) ($tusDone['key'] ?? '')), 'name' => (string) ($tusDone['name'] ?? '')]);
            }
        }
        exit;
    }

    // Routing
    $data = routeRequest($method, $uri, $fm, $metaRepo, $diskManager, $claims, $auditLog, $chunker, $quotaManager, $dbConn);

    // Log write actions
    if ($isWriteAction && $data !== null) {
        $raw = file_get_contents('php://input');
        $body = \FluxFiles\AuditPolicy::decodeBody((string) $raw);
        $audit = \FluxFiles\AuditPolicy::successEntry($uri, $body, $_POST, $claims, $data);
        $auditAction = $audit['action'];
        $auditDisk = $audit['disk'];
        $auditKey = $audit['key'];
        $auditLog->log($claims->userId, $auditAction, $auditDisk, $auditKey, null, null, $audit['detail']);

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

    // Opt-in read auditing (`audit_reads`): who opened which file's bytes. Only
    // the routes that return a file's CONTENT — a listing or a metadata lookup is
    // not a read of the file. Best-effort: a log failure never fails the read.
    if (!$isWriteAction && $data !== null && $claims->auditReads) {
        $readAction = ['/api/fm/content' => 'content_read'][$uri] ?? null;
        if ($readAction !== null) {
            try {
                $auditLog->log(
                    $claims->userId,
                    $readAction,
                    (string) ($_GET['disk'] ?? 'local'),
                    $claims->scopePath(ff_str_param($_GET, 'path'))
                );
            } catch (\Throwable $ignored) {
            }
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
    // Refused writes that must leave a trace even though the write failed: an
    // infected file (virus_blocked), PII (dlp_blocked), and a destructive op on a
    // held path (legal_hold_blocked) — the audit log only records SUCCESSFUL writes.
    // Then any other authenticated 403 as access_denied (the signal an access review
    // looks for first). See AuditPolicy.
    if (isset($auditLog, $claims)) {
        $errBody = in_array($e->getErrorCode(), ['virus_detected', 'pii_detected', 'legal_hold_active'], true)
            || $e->getHttpCode() === 403
            ? \FluxFiles\AuditPolicy::decodeBody((string) file_get_contents('php://input'))
            : [];
        \FluxFiles\AuditPolicy::recordRefusedWrite($auditLog, $claims, $e, $errBody, $_POST);
        \FluxFiles\AuditPolicy::recordDenied($auditLog, $claims, $e, $method, $uri, $errBody, $_POST, $_GET);
    }
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
    MetadataRepositoryInterface $metaRepo,
    DiskManager $diskManager,
    \FluxFiles\Claims $claims,
    AuditLogStorage $auditLog,
    \FluxFiles\ChunkUploader $chunker,
    \FluxFiles\QuotaManager $quotaManager,
    ?\FluxFiles\Db\Connection $dbConn = null
) {
    // File operations
    if ($method === 'GET' && $uri === '/api/fm/list') {
        return $fm->list(
            $_GET['disk'] ?? 'local',
            $_GET['path'] ?? '',
            max(0, (int) ff_str_param($_GET, 'limit', '0')),
            ff_str_param($_GET, 'cursor'),
            ff_str_param($_GET, 'sort'),
            ff_str_param($_GET, 'order', 'asc'),
            ff_str_param($_GET, 'type', 'all')
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
        $claims->requirePerm('write');
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
        return handlePresign($fm, $claims);
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

    // Metadata export/import (FREE/core, DB backend only) — bulk backup/restore
    // for FLUXFILES_STORAGE_BACKEND=db (docs/design/DB-STORAGE-MIGRATION-DESIGN.md §7).
    // Per-tenant, not admin-only: a scoped token exports/imports only its own
    // pathPrefix/owner, the same trust model as every other disk-scoped route —
    // unlike /audit/purge, this data belongs to the tenant, not the whole disk.
    if ($method === 'GET' && $uri === '/api/fm/metadata/export') {
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        if ($dbConn === null) {
            throw new ApiException('Metadata export requires FLUXFILES_STORAGE_BACKEND=db', 501, 'metadata_export_unavailable');
        }
        $disk = (string) ($_GET['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        $requestedPrefix = trim((string) ($_GET['prefix'] ?? ''), '/');
        $tenantPrefix = trim($claims->pathPrefix, '/');
        if ($requestedPrefix !== '' && !$claims->isPathInScope($requestedPrefix)) {
            throw new ApiException('Prefix out of scope', 403, 'forbidden');
        }
        // A scoped token's tenantPrefix always wins — an unscoped token may
        // additionally narrow itself via ?prefix=, but a scoped one cannot widen.
        $effectivePrefix = $tenantPrefix !== '' ? $tenantPrefix : $requestedPrefix;
        $ownerFilter = $claims->ownerOnly ? $claims->userId : null;
        $format = ((string) ($_GET['format'] ?? 'ndjson')) === 'csv' ? 'csv' : 'ndjson';

        $exporter = new \FluxFiles\Db\MetadataExporter($dbConn);
        $filename = 'metadata-export-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $disk) . '.' . $format;
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/x-ndjson') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        $exporter->streamTo($out, $disk, $format, $effectivePrefix, $ownerFilter);
        fclose($out);
        exit;
    }

    if ($method === 'POST' && $uri === '/api/fm/metadata/import') {
        if (!$claims->hasPerm('write')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        if ($dbConn === null) {
            throw new ApiException('Metadata import requires FLUXFILES_STORAGE_BACKEND=db', 501, 'metadata_import_unavailable');
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $disk = (string) ($body['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
        if ($entries === []) {
            throw new ApiException('entries must be a non-empty array', 400, 'missing_param');
        }
        // Canonicalize each row's path before it's scope-checked/hashed/stored —
        // isPathInScope() only validates, it doesn't sanitize (see Claims::
        // normalizeKey()'s doc-comment), so an un-normalized "a/../b" would
        // both pass a naive check and land in file_metadata under a path_hash
        // that no normal GET /metadata lookup (which now normalizes too) could
        // ever reach again.
        foreach ($entries as &$entry) {
            if (is_array($entry) && isset($entry['path'])) {
                $entry['path'] = $claims->normalizeKey((string) $entry['path']);
            }
        }
        unset($entry);
        $importer = new \FluxFiles\Db\MetadataImporter($dbConn);
        $result = $importer->import($disk, $entries, function (string $path) use ($claims, $fm, $disk): bool {
            if (!$claims->isPathInScope($path)) {
                return false;
            }
            try {
                $fm->assertCanModifyScopedPath($disk, $path);
                return true;
            } catch (ApiException $e) {
                return false;
            }
        }, $claims->ownerOnly ? $claims->userId : null);
        if ($result['errors'] !== []) {
            throw new ApiException('Import rejected — one or more rows are out of scope', 422, 'metadata_import_rejected', ['errors' => $result['errors']]);
        }
        return $result;
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
            $res['url'] = ff_public_link_url('share.html', (string) $res['token']);
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
    // Share analytics — per-event view/download/unlock_fail log, opt-in via
    // share_analytics (baked into the share record at create time). Same 3-layer
    // gate; owner_only enforced inside analytics() like revokeShare().
    if ($method === 'GET' && $uri === '/api/fm/share/analytics') {
        $module = \FluxFiles\ModuleRegistry::require('share', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->analytics(
            $diskManager,
            $claims,
            ff_str_param($_GET, 'disk', 'local'),
            ff_str_param($_GET, 'jti'),
            max(1, min(500, (int) ($_GET['limit'] ?? 100))),
            max(0, (int) ($_GET['offset'] ?? 0)),
            isset($_GET['event']) && $_GET['event'] !== '' ? ff_str_param($_GET, 'event') : null
        );
    }
    // Intake / Upload Portals — operator create + manage (public info/upload are
    // handled before auth). Same 3-layer gate (501/402/403).
    if ($method === 'POST' && $uri === '/api/fm/intake') {
        $module = \FluxFiles\ModuleRegistry::require('intake', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $res = $module->createPortal($fm, $diskManager, $claims, (string) ($_ENV['FLUXFILES_SECRET'] ?? ''), $body);
        // The recipient URL, exactly like Share above: the module builds it from
        // `intake_base_url` when the token carries one; otherwise fall back to this
        // request's origin + the core landing page. The token is returned ONCE and
        // never stored — a listed portal can only be revoked, not re-linked.
        if (empty($res['url']) && !empty($res['token'])) {
            $res['url'] = ff_public_link_url('intake.html', (string) $res['token']);
        }
        return $res;
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
    // Intake analytics — per-event received/rejected log, opt-in via intake_analytics
    // (baked into the portal record at create time). Same 3-layer gate; owner_only
    // enforced inside analytics() like revokePortal().
    if ($method === 'GET' && $uri === '/api/fm/intake/analytics') {
        $module = \FluxFiles\ModuleRegistry::require('intake', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->analytics(
            $diskManager,
            $claims,
            ff_str_param($_GET, 'disk', 'local'),
            ff_str_param($_GET, 'jti'),
            max(1, min(500, (int) ($_GET['limit'] ?? 100))),
            max(0, (int) ($_GET['offset'] ?? 0)),
            isset($_GET['event']) && $_GET['event'] !== '' ? ff_str_param($_GET, 'event') : null
        );
    }
    // File versioning — list prior versions of a file / restore one. Same 3-layer gate.
    if ($method === 'GET' && $uri === '/api/fm/versions') {
        $module = \FluxFiles\ModuleRegistry::require('versioning', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->listVersions($fm, $diskManager, $claims, ff_str_param($_GET, 'disk', 'local'), ff_str_param($_GET, 'path'), $metaRepo);
    }
    if ($method === 'POST' && $uri === '/api/fm/versions/restore') {
        $module = \FluxFiles\ModuleRegistry::require('versioning', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        return $module->restore($fm, $diskManager, $claims, (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''), (string) ($body['version_id'] ?? ''), $metaRepo);
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
    // On-demand scan of an already-stored file (paid `virus` module, 3-layer gate).
    if ($method === 'POST' && $uri === '/api/fm/virus/scan') {
        /** @var \FluxFiles\Virus\VirusScanModule $virus */
        $virus = \FluxFiles\ModuleRegistry::require('virus', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $body = jsonBodyAll();
        return $fm->scanForViruses(
            (string) ($body['disk'] ?? 'local'),
            (string) ($body['path'] ?? ''),
            static fn (string $p): array => $virus->scanPath($p)
        );
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
        $zipDisk = (string) ($body['disk'] ?? 'local');
        $zipPaths = is_array($body['paths'] ?? null) ? $body['paths'] : [];
        $fm->streamZip(
            $zipDisk,
            $zipPaths,
            isset($body['name']) ? (string) $body['name'] : null,
            true,
            // A bulk download is the classic exfiltration shape, and this route
            // exits before the generic write audit below ever runs — so record it
            // here, once every guard has passed and before the first byte is sent.
            static function (array $manifest) use ($auditLog, $claims, $zipDisk, $zipPaths): void {
                try {
                    $auditLog->log(
                        $claims->userId,
                        'zip_download',
                        $zipDisk,
                        $claims->scopePath((string) ($zipPaths[0] ?? '')),
                        null,
                        null,
                        \FluxFiles\AuditPolicy::zipDetail($manifest)
                    );
                } catch (\Throwable $ignored) {
                }
            }
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

    // Search
    if ($method === 'GET' && $uri === '/api/fm/search') {
        $q = $_GET['q'] ?? null;
        if ($q === null) {
            throw new ApiException('Missing search query', 400, 'missing_param');
        }
        $disk = $_GET['disk'] ?? 'local';
        $claims->requireDisk($disk);
        $claims->requirePerm('read');
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
        $claims->requireDisk($disk);
        $claims->requirePerm('read');
        $rows = $metaRepo->searchFolders($disk, $q, (int) ($_GET['limit'] ?? 50), $claims->pathPrefix, $claims->showHidden);
        foreach ($rows as &$row) {
            if (isset($row['dir_key'])) {
                $row['dir_key'] = $claims->unscopePath((string) $row['dir_key']);
            }
        }
        unset($row);
        return $rows;
    }

    // Quota / usage. QuotaManager talks to DiskManager::disk() directly and has no
    // hasDisk/assertDisk of its own, and DiskManager builds any CONFIGURED disk
    // regardless of claims — so the ACL has to be enforced here, like every other
    // disk-taking route does (FileManager::assertDisk → 'disk_denied'). Without it a
    // token scoped to `local` could read total_size/file_count/by_type and the top
    // folder PATHS of a disk it has no access to, and ?refresh would then WRITE
    // _fluxfiles/usage.json onto that foreign disk.
    if ($method === 'GET' && ($uri === '/api/fm/quota' || $uri === '/api/fm/usage')) {
        $disk = (string) ($_GET['disk'] ?? 'local');
        $claims->requireDisk($disk);
        $claims->requirePerm('read');
        if ($uri === '/api/fm/usage') {
            return handleUsage($quotaManager, $diskManager, $claims, $metaRepo);
        }
        return $quotaManager->getQuotaInfo($disk, $claims->pathPrefix, $claims->maxStorageMb);
    }

    // Duplicate finder (usage dashboard). Lists + hashes the prefix, so it shares
    // the forced-usage-recompute bucket below (2/min) rather than the read budget.
    if ($method === 'GET' && $uri === '/api/fm/duplicates') {
        return $fm->findDuplicates(
            (string) ($_GET['disk'] ?? 'local'),
            max(1, min(200, (int) ($_GET['limit'] ?? 50)))
        );
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

    // Compliance Readiness Scorecard (free/core, docs/design/COMPLIANCE-SCORECARD-DESIGN.md) —
    // a read-only capability checklist (virus scan / C2PA / audit export / SSO / DLP /
    // legal hold), gated by the same 'audit' perm as the activity log (introspection
    // over the tenant's own configuration, not a new capability). No module/license
    // gate on the VIEW itself — every paid row simply reads `available: false` on an
    // unlicensed server so free-core operators still see the full checklist.
    if ($method === 'GET' && $uri === '/api/fm/compliance/scorecard') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        return \FluxFiles\ComplianceScorecard::build($claims, \FluxFiles\LicenseManager::fromEnv());
    }

    // Audit export (paid module) — a full, unpaginated download of the tenant's
    // audit history (live + archived), as NDJSON or CSV. Bypasses the JSON encoder
    // like /api/fm/zip: the module streams its own headers + body, then we exit.
    if ($method === 'GET' && $uri === '/api/fm/audit/export') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        /** @var \FluxFiles\AuditExport\AuditExportModule $module */
        $module = \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $module->export($auditLog, $claims, [
            'action' => $_GET['action'] ?? null,
            'from'   => $_GET['from'] ?? null,
            'to'     => $_GET['to'] ?? null,
            'path'   => $_GET['path'] ?? null,
            'actor'  => $_GET['actor'] ?? null,
        ], (string) ($_GET['format'] ?? 'ndjson'));
        exit;
    }

    // Retention rules (legal-hold module — the same compliance bundle; a hold always
    // wins). `dry_run` (default true) only reports. Every file actually trashed/
    // deleted gets its own audit line, since this one request can remove many.
    if ($method === 'POST' && $uri === '/api/fm/retention/run') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $disk = (string) ($body['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $dryRun = filter_var($body['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $report = $fm->runRetention($disk, $dryRun);
        if (!$dryRun) {
            foreach ($report['items'] as $it) {
                if (in_array($it['result'], ['trashed', 'deleted'], true)) {
                    try {
                        $auditLog->log($claims->userId, 'retention_' . $it['action'], $disk, $claims->scopePath($it['path']),
                            null, null, 'age_days=' . $it['age_days']);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
        }
        return $report;
    }

    // Fixity / integrity check (Audit Export module — the compliance bundle). Per
    // tenant root: the baseline lives in the tenant's own _fluxfiles/. Each
    // UNEXPLAINED change (no FluxFiles write/delete since the last check) is
    // written to the audit log as its own `fixity_mismatch` entry.
    if ($method === 'POST' && $uri === '/api/fm/fixity/check') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $disk = (string) ($body['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
        $report = $fm->runFixityCheck($disk, static fn (?int $since): array =>
            $since !== null && method_exists($metaRepo, 'readAuditWindow')
                ? $metaRepo->readAuditWindow($disk, $since)
                : $metaRepo->readAudit($disk));
        foreach (['changed_unexplained' => 'changed', 'missing_unexplained' => 'missing'] as $list => $what) {
            foreach ($report[$list] as $key) {
                try {
                    $auditLog->log($claims->userId, 'fixity_mismatch', $disk, $claims->scopePath((string) $key), null, null, $what);
                } catch (\Throwable $ignored) {
                }
            }
        }
        return $report;
    }

    // Audit chain verification (Audit Export module, Enterprise) — recompute every
    // entry's HMAC and walk the prev links (live log + archives) to detect edits,
    // inserted/reordered lines and entries deleted from the middle. Admin-only like
    // purge: the log is per-DISK, so a scoped tenant would learn about other
    // tenants' entries (counts, breaks) from the report.
    if ($method === 'GET' && $uri === '/api/fm/audit/verify') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        if (trim($claims->pathPrefix, '/') !== '') {
            throw new ApiException('Audit verification requires an unscoped (admin) token', 403, 'forbidden');
        }
        $disk = (string) ($_GET['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return \FluxFiles\AuditChain::verify(array_merge($metaRepo->readAuditArchive($disk), $metaRepo->readAudit($disk)));
    }

    // Audit purge (paid module) — destructive, so admin-only: the audit log is
    // stored per-DISK, not per-tenant, so a path-scoped token could otherwise purge
    // lines belonging to other tenants sharing the same disk.
    if ($method === 'POST' && $uri === '/api/fm/audit/purge') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        if (trim($claims->pathPrefix, '/') !== '') {
            throw new ApiException('Audit purge requires an unscoped (admin) token', 403, 'forbidden');
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $disk = (string) ($body['disk'] ?? 'local');
        // pathPrefix and allowedDisks are independent claims — an unscoped token can
        // still be limited to specific disks, so the tenant-prefix check above does
        // NOT imply disk access. Same check every other disk-taking route makes.
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        $before = isset($body['before']) && $body['before'] !== ''
            ? (int) $body['before']
            : ($claims->auditRetentionDays > 0 ? time() - ($claims->auditRetentionDays * 86400) : 0);
        if ($before <= 0) {
            throw new ApiException('An explicit `before` cutoff (or a token audit_retention_days) is required', 400, 'audit_purge_no_cutoff');
        }
        /** @var \FluxFiles\AuditExport\AuditExportModule $module */
        $module = \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->purge($metaRepo, $claims, $disk, $before);
    }

    // Legal hold — STATUS is free/core (visibility, no license/claim check):
    // reads the same 'audit' bucket used elsewhere in this file only to decide
    // whether to include the (potentially sensitive) reason/placed_by/placed_at
    // detail, not to gate access to the endpoint itself.
    if ($method === 'GET' && $uri === '/api/fm/hold/status') {
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $disk = (string) ($_GET['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        $path = (string) ($_GET['path'] ?? '');
        $hold = $metaRepo->holdCovering($disk, $claims->scopePath($path));
        if ($hold === null) {
            return ['on_hold' => false];
        }
        $out = ['on_hold' => true, 'hold_id' => $hold['hold_id'] ?? null];
        if ($claims->hasPerm('audit')) {
            $out['reason'] = $hold['reason'] ?? null;
            $out['placed_by'] = $hold['placed_by'] ?? null;
            $out['placed_at'] = $hold['placed_at'] ?? null;
        }
        return $out;
    }

    // Legal hold — PLACE/RELEASE/LIST are the paid-gated management half (see
    // docs/design/RETENTION-LEGAL-HOLD-DESIGN.md §2). Enforcement itself — actually
    // blocking delete/trash/rename/move on a held path — is free/core and
    // license-independent, wired unconditionally into FileManager::assertNoActiveHold();
    // it keeps working even if this module is uninstalled or the license lapses.
    if ($method === 'POST' && $uri === '/api/fm/hold') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $b = jsonBodyAll();
        $disk = (string) ($b['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        if (!isset($b['path'])) {
            throw new ApiException('Missing required field: path', 400, 'missing_param');
        }
        /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
        $module = \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->place($metaRepo, $diskManager, $claims, $disk, (string) $b['path'], (string) ($b['reason'] ?? ''));
    }

    if ($method === 'POST' && $uri === '/api/fm/hold/release') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $b = jsonBodyAll();
        $disk = (string) ($b['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        if (!isset($b['hold_id'])) {
            throw new ApiException('Missing required field: hold_id', 400, 'missing_param');
        }
        /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
        $module = \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->release($metaRepo, $claims, $disk, (string) $b['hold_id'], (string) ($b['reason'] ?? ''));
    }

    if ($method === 'GET' && $uri === '/api/fm/hold/list') {
        if (!$claims->hasPerm('audit')) {
            throw new ApiException('Permission denied', 403, 'forbidden');
        }
        $disk = (string) ($_GET['disk'] ?? 'local');
        if (!$claims->hasDisk($disk)) {
            throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
        }
        $includeReleased = filter_var($_GET['include_released'] ?? false, FILTER_VALIDATE_BOOLEAN);
        /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
        $module = \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);
        return $module->list($metaRepo, $claims, $disk, $includeReleased);
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
        $report = (new BucketDoctor($diskManager))->diagnose($disk, $origin ?: null);
        // A GET, but it writes and deletes a probe object on the bucket — record it
        // like any other write so the activity log shows who touched the storage.
        try {
            $auditLog->log($claims->userId, 'disk_doctor', $disk, '', null, null, null);
        } catch (\Throwable $ignored) {
        }
        return $report;
    }

    // Chunk upload. S3 multipart sends the bytes browser→S3 on presigned URLs, so they
    // never reach this server and CANNOT be scanned. A tenant that asked for virus
    // scanning must not have an unscannable side door — refuse the route rather than
    // let it quietly become the way around the scanner.
    if ($claims->allowVirusScan && str_starts_with($uri, '/api/fm/chunk/')) {
        throw new ApiException(
            'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
            409,
            'virus_unscannable'
        );
    }
    // Same unscannable-side-door problem, same resolution, for DLP: S3-multipart
    // bytes go browser→S3 and never reach this server, so a PII scan can never run
    // on them. Independent `if` — allow_virus_scan and allow_dlp_scan are orthogonal
    // claims, either (or both) can be on, and each must 409 on its own.
    if ($claims->allowDlpScan && str_starts_with($uri, '/api/fm/chunk/')) {
        throw new ApiException(
            'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
            409,
            'dlp_unscannable'
        );
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/init') {
        return handleChunkInit($chunker, $claims, $fm, $quotaManager);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/presign') {
        return handleChunkPresign($chunker, $claims, $fm);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/complete') {
        return handleChunkComplete($chunker, $claims, $fm, $metaRepo, $diskManager, $quotaManager);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/abort') {
        return handleChunkAbort($chunker, $claims, $fm);
    }

    throw new ApiException('Not found', 404, 'not_found');
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

function handleGetMetadata(MetadataRepositoryInterface $metaRepo, \FluxFiles\Claims $claims, FileManager $fm): ?array
{
    $disk = $_GET['disk'] ?? null;
    $key  = $_GET['key'] ?? null;
    if ($disk === null) {
        throw new ApiException('Missing disk parameter', 400, 'missing_param');
    }
    if ($key === null) {
        throw new ApiException('Missing key parameter', 400, 'missing_param');
    }
    $claims->requireDisk($disk);
    $claims->requirePerm('read');
    // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
    // internal detail), so a prefixed tenant used to get 403 path_denied on every
    // metadata read/save/delete. scopePath() prefixes it — idempotent for an
    // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
    $key = $claims->scopePath((string) $key);
    // Normalize BEFORE building any downstream path — isPathInScope() only
    // validates, it doesn't sanitize. See validateScopedPath()'s doc-comment.
    $key = $fm->validateScopedPath($key);
    return $metaRepo->get($disk, $key);
}

function handleSaveMetadata(MetadataRepositoryInterface $metaRepo, DiskManager $diskManager, \FluxFiles\Claims $claims, FileManager $fm): array
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
    $claims->requireDisk($disk);
    $claims->requirePerm('write');
    // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
    // internal detail), so a prefixed tenant used to get 403 path_denied on every
    // metadata read/save/delete. scopePath() prefixes it — idempotent for an
    // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
    $key = $claims->scopePath((string) $key);
    // Normalize BEFORE building any downstream path — see validateScopedPath().
    $key = $fm->assertCanModifyScopedPath($disk, $key);

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

function handleDeleteMetadata(MetadataRepositoryInterface $metaRepo, \FluxFiles\Claims $claims, FileManager $fm): array
{
    [$disk, $key] = jsonBody('disk', 'key');
    $claims->requireDisk($disk);
    $claims->requirePerm('write');
    // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
    // internal detail), so a prefixed tenant used to get 403 path_denied on every
    // metadata read/save/delete. scopePath() prefixes it — idempotent for an
    // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
    $key = $claims->scopePath((string) $key);
    // Normalize BEFORE building any downstream path — see validateScopedPath().
    $key = $fm->assertCanModifyScopedPath($disk, $key);
    $metaRepo->delete($disk, $key);
    return ['deleted' => true];
}

/**
 * SSO bridge, step 1: start a login round-trip. Validates the return path,
 * signs an SsoStateToken, and delegates to the paid module to build the IdP's
 * authorization URL (from OidcDiscovery) and 302 there. Emits plain text on
 * failure — this is a browser navigation, not a fetch(), so there's no JSON
 * envelope to return errors in (same posture as MediaEndpoint).
 */
function handleSsoLogin(): void
{
    try {
        if (($_ENV['FLUXFILES_SSO_ENABLED'] ?? '') !== 'true') {
            throw new ApiException('SSO is not enabled on this server', 403, 'sso_disabled');
        }
        ff_sso_rate_limit('login');
        /** @var \FluxFiles\Sso\SsoModule $module */
        $module = \FluxFiles\ModuleRegistry::requireServer('sso', \FluxFiles\LicenseManager::fromEnv());

        // Open-redirect guard lives entirely in SsoModule::login() (via its
        // private sanitizeRedirect()) — passing the raw value through here
        // rather than re-validating keeps the check in exactly one place, so
        // a future refinement can't fix one copy and miss the other.
        $redirect = ff_str_param($_GET, 'redirect', '/public/index.html');
        $module->login($redirect);
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Internal server error';
    }
}

/**
 * SSO bridge, step 2: the IdP redirects back here with `code`/`state`. Delegates
 * to the paid module, which verifies `state`, exchanges `code` at the token
 * endpoint, verifies the `id_token` via JWKS, resolves claims, mints the real
 * access JWT, wraps it in a 60s SsoBootToken, and 302s back to
 * `/public/index.html#boot=<token>` — a URL *fragment*, never a query string, so
 * the real JWT's carrier never reaches server access logs.
 */
function handleSsoCallback(): void
{
    try {
        if (($_ENV['FLUXFILES_SSO_ENABLED'] ?? '') !== 'true') {
            throw new ApiException('SSO is not enabled on this server', 403, 'sso_disabled');
        }
        ff_sso_rate_limit('callback');
        /** @var \FluxFiles\Sso\SsoModule $module */
        $module = \FluxFiles\ModuleRegistry::requireServer('sso', \FluxFiles\LicenseManager::fromEnv());

        $module->callback(ff_str_param($_GET, 'code'), ff_str_param($_GET, 'state'));
    } catch (ApiException $e) {
        http_response_code($e->getHttpCode());
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Internal server error';
    }
}

/**
 * SSO bridge, step 3: the browser's boot script trades the fragment's one-time
 * SsoBootToken for the real access JWT, via a POST body — the only way the real
 * JWT is ever transmitted. Standard JSON envelope (this IS a fetch(), unlike
 * login/callback above), same try/catch shape as handleIntakePublic/handleSharePublic.
 */
function handleSsoExchange(): void
{
    try {
        if (($_ENV['FLUXFILES_SSO_ENABLED'] ?? '') !== 'true') {
            throw new ApiException('SSO is not enabled on this server', 403, 'sso_disabled');
        }
        ff_sso_rate_limit('exchange');
        /** @var \FluxFiles\Sso\SsoModule $module */
        $module = \FluxFiles\ModuleRegistry::requireServer('sso', \FluxFiles\LicenseManager::fromEnv());

        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $result = $module->exchange(ff_str_param($body, 'token'));
        echo json_encode(['data' => $result, 'error' => null]);
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
 * The /stream + /img endpoint (see MediaEndpoint) wired for the standalone app:
 * the configured disks, FLUXFILES_SECRET, the env-driven per-sub rate limit, the
 * optional nginx X-Accel location, and the operator's metadata backend for
 * `audit_reads`. These routes run before auth, so there is no Claims here — the
 * disk list is the unfiltered config, exactly as before.
 */
function ff_media_endpoint(): \FluxFiles\MediaEndpoint
{
    $dm = new DiskManager(require __DIR__ . '/../config/disks.php');
    return new \FluxFiles\MediaEndpoint(
        $dm,
        (string) ($_ENV['FLUXFILES_SECRET'] ?? ''),
        \FluxFiles\MediaEndpoint::defaultRateLimit(),
        (string) ($_ENV['FLUXFILES_XACCEL'] ?? ''),
        static fn () => ff_public_meta_repo($dm)
    );
}

/**
 * This request's own origin (scheme + host), honouring a TLS-terminating proxy.
 *
 * `Host` (and `X-Forwarded-Proto`) are client-influenced — behind a misconfigured
 * or cache-poisoning-prone proxy a forged `Host` would otherwise make
 * ff_public_link_url() hand an operator back a recipient link on an attacker's
 * domain. When the operator has declared trusted origins via
 * `FLUXFILES_ALLOWED_ORIGINS` (the same allowlist the CORS/CSRF check above
 * reuses for "origins this deployment trusts"), an origin outside that list is
 * refused in favour of the first declared one instead of trusted blindly. An
 * empty allowlist (the default) keeps prior behaviour unchanged — operators who
 * haven't set it get no new restriction, only those who opt in via CORS config.
 */
function ff_request_origin(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $origin = ($isHttps ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $allowedOrigins = array_values(array_filter(
        array_map('trim', explode(',', $_ENV['FLUXFILES_ALLOWED_ORIGINS'] ?? ''))
    ));
    if ($allowedOrigins !== [] && !in_array($origin, $allowedOrigins, true)) {
        return $allowedOrigins[0];
    }

    return $origin;
}

/**
 * Public recipient link for a one-shot module token, when the module built none
 * (the tenant set no `share_base_url` / `intake_base_url`). Derived from the
 * request's own origin, i.e. from the `Host` header — behind a proxy/CDN the
 * operator should set the base-URL claim explicitly instead.
 */
function ff_public_link_url(string $page, string $token): string
{
    return ff_request_origin() . '/public/' . $page . '?token=' . rawurlencode($token);
}

/**
 * Storage usage dashboard. One recursive pass over the prefix (via
 * getUsageBreakdown) → quota + by-type + top-folders, cached in
 * `_fluxfiles/usage.json` (per prefix, TTL = usage_cache_ttl). `?refresh=true`
 * bypasses the cache (rate-limited upstream).
 */
function handleUsage(QuotaManager $quotaManager, DiskManager $diskManager, \FluxFiles\Claims $claims, $metaRepo = null): array
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
            return $cached + ff_usage_index_info($metaRepo, $disk, $claims);
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
    return $resp + ff_usage_index_info($metaRepo, $disk, $claims);
}

/**
 * Size of the JSON backend's disk-wide metadata index, for operators only (an
 * unscoped token — the index spans every tenant on the disk). `warn` asks the
 * UI to suggest the database backend. Empty on the DB backend (no such file).
 *
 * @return array{metadata_index?: array{bytes:int,warn_bytes:int,warn:bool}}
 */
function ff_usage_index_info($metaRepo, string $disk, \FluxFiles\Claims $claims): array
{
    if (trim($claims->pathPrefix, '/') !== '' || !is_object($metaRepo) || !method_exists($metaRepo, 'indexBytes')) {
        return [];
    }
    $bytes = $metaRepo->indexBytes($disk);
    if ($bytes === null) {
        return [];
    }
    $warnAt = \FluxFiles\StorageMetadataHandler::indexWarnBytes();
    return ['metadata_index' => ['bytes' => $bytes, 'warn_bytes' => $warnAt, 'warn' => $warnAt > 0 && $bytes >= $warnAt]];
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

function handlePresign(FileManager $fm, \FluxFiles\Claims $claims): array
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

    // A `method:"PUT"` presign is the same unscannable side door the chunk routes
    // are refused for (see the allowVirusScan/allowDlpScan guards in
    // routeRequest()): it mints a URL the browser PUTs straight to S3/R2, so the
    // bytes never reach this server and no scan can ever run on them. Refusing
    // only /api/fm/chunk/* while leaving this open would hand a tenant who paid
    // for fail-closed scanning a one-request bypass.
    //
    // The check lives HERE rather than beside the chunk guard because this route
    // is dispatched much earlier in routeRequest(), so a guard down there is
    // never reached. Two independent `if`s, exactly like the chunk pair — the two
    // claims are orthogonal, either (or both) can be on, each 409s on its own.
    // POST (a browser form upload straight to the bucket) is the same side door.
    if (in_array(strtoupper((string) $body['method']), ['PUT', 'POST'], true)) {
        if ($claims->allowVirusScan) {
            throw new ApiException(
                'Direct-to-storage upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                409,
                'virus_unscannable'
            );
        }
        if ($claims->allowDlpScan) {
            throw new ApiException(
                'Direct-to-storage upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                409,
                'dlp_unscannable'
            );
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
    $claims->requirePerm('write');

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

    $claims->requireDisk($disk);

    $sizeBytes = (int) ($body['size'] ?? $body['size_bytes'] ?? 0);
    if ($sizeBytes <= 0) {
        throw new ApiException('Missing required field: size', 400, 'missing_param');
    }
    $scopedPath = $fm->validateUserPath((string) $path);
    $scopedPath = $fm->validateChunkUpload($disk, $scopedPath, $sizeBytes, true);

    return $chunker->initiate($disk, $scopedPath, $fm->chunkInitMetadata($disk, $scopedPath));
}

function handleChunkPresign(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims, FileManager $fm): array
{
    $claims->requirePerm('write');
    [$disk, $key, $uploadId, $partNumber] = jsonBody('disk', 'key', 'upload_id', 'part_number');
    $claims->requireDisk($disk);
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    // Normalize BEFORE building any downstream path — see validateScopedPath().
    $key = $fm->validateScopedPath($key);
    return $chunker->presignPart($disk, $key, $uploadId, (int) $partNumber);
}

function handleChunkComplete(
    \FluxFiles\ChunkUploader $chunker,
    \FluxFiles\Claims $claims,
    FileManager $fm,
    MetadataRepositoryInterface $metaRepo,
    DiskManager $diskManager,
    \FluxFiles\QuotaManager $quotaManager
): array
{
    $claims->requirePerm('write');
    [$disk, $key, $uploadId, $parts] = jsonBody('disk', 'key', 'upload_id', 'parts');
    $claims->requireDisk($disk);
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    // Normalize BEFORE building any downstream path — see validateScopedPath().
    $key = $fm->validateScopedPath($key);
    $result = $chunker->complete(
        $disk, $key, $uploadId, $parts,
        fn(int $size) => $fm->validateChunkUpload($disk, $key, $size),
        $claims->uploadCollision === 'overwrite'
    );

    $metaRepo->save($disk, $key, [
        'uploaded_by' => $claims->userId,
        'size' => $result['size'],
        'modified' => time(),
    ]);
    $metaRepo->saveHash($disk, $key, '');
    return $result;
}

function handleChunkAbort(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims, FileManager $fm): array
{
    $claims->requirePerm('write');
    [$disk, $key, $uploadId] = jsonBody('disk', 'key', 'upload_id');
    $claims->requireDisk($disk);
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403, 'path_denied');
    }
    // Normalize BEFORE building any downstream path — see validateScopedPath().
    $key = $fm->validateScopedPath($key);
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

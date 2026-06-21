<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
    $localeJson = $i18n->toJson();
    $locale = $i18n->locale();
    $dir = $i18n->direction();
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    // HEX flags keep these safe to embed inside the inline <script> (no </script> breakout).
    $jsFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $injection = "window.__FM_LOCALE__ = { locale: " . json_encode($locale, $jsFlags) . ", dir: " . json_encode($dir, $jsFlags) . ", messages: {$localeJson} };";
    $html = str_replace(
        "window.__FM_LOCALE__ = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };",
        $injection,
        $html
    );
    $html = str_replace('<html lang="en">', '<html lang="' . htmlspecialchars($locale) . '" dir="' . htmlspecialchars($dir) . '">', $html);
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
    }

    echo json_encode(['data' => $data, 'error' => null]);
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

    // Config / code editor — read a file's text content, or overwrite it.
    if ($method === 'GET' && $uri === '/api/fm/content') {
        return $fm->getContent($_GET['disk'] ?? 'local', $_GET['path'] ?? '');
    }
    if ($method === 'PUT' && $uri === '/api/fm/content') {
        [$disk, $path, $content] = jsonBody('disk', 'path', 'content');
        return $fm->putContent((string) $disk, (string) $path, (string) $content);
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
        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied: read', 403, 'permission_denied');
        }
        $rows = $metaRepo->search($disk, $q, (int) ($_GET['limit'] ?? 50), $claims->pathPrefix);
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
        $rows = $metaRepo->searchFolders($disk, $q, (int) ($_GET['limit'] ?? 50), $claims->pathPrefix);
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
    ];

    foreach ($map as $needle => $action) {
        if (strpos($uri, $needle) !== false) {
            return $action;
        }
    }

    return 'unknown';
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
        $scope = \FluxFiles\StreamToken::verify((string) ($_GET['token'] ?? ''), $secret);
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
        $scope = \FluxFiles\ImageToken::verify((string) ($_GET['token'] ?? ''), $secret);
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

    // Width: round to 100px, clamp to the tenant max (mw, default 2000). 0 = keep size.
    $maxWidth = $scope['maxWidth'] > 0 ? $scope['maxWidth'] : 2000;
    $reqWidth = (int) ($_GET['width'] ?? 0);
    $width = $reqWidth > 0 ? min($maxWidth, max(100, (int) round($reqWidth / 100) * 100)) : 0;
    $defaultQuality = $scope['defaultQuality'] > 0 ? $scope['defaultQuality'] : 80;
    $quality = ff_snap_quality($_GET['quality'] ?? $defaultQuality);
    $format = ($_GET['format'] ?? 'webp') === 'auto' ? 'auto' : 'webp';

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

    // Content negotiation: format=auto + a client that doesn't accept WebP → serve
    // the original unchanged (old browsers). NEVER for a watermarked token — that
    // would hand back the clean image and defeat the watermark.
    if (!$wmEnabled && $format === 'auto'
        && strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'image/webp') === false) {
        ff_serve_bytes((string) $fs->read($path), $origMime);
        return;
    }

    // Cache key is stamped with the source mtime (+ watermark signature) so a
    // re-upload or a config/logo change never re-matches a stale image.
    $ver = (string) (@$fs->lastModified($path) ?: '0');
    $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, $wmSig);

    if ($fs->fileExists($cacheKey)) {
        // S3/R2: redirect to a presigned URL of the cached WebP so the bucket
        // serves the bytes directly (no app-server egress). Local disks read
        // cheaply, so we just serve them.
        if (($diskConfigs[$disk]['driver'] ?? '') === 's3') {
            $redirect = $dm->presignGetUrl($disk, $cacheKey, 3600);
            if ($redirect !== null) {
                header('Cache-Control: private, max-age=600');
                header('Location: ' . $redirect, true, 302);
                return;
            }
        }
        ff_serve_bytes((string) $fs->read($cacheKey), 'image/webp', true);
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

    $out = $optimizer->transform((string) $fs->read($path), $width, $quality, $wmCfg);
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

    try { $fs->write($cacheKey, $out['data']); } catch (\Throwable $e) { /* best-effort cache */ }
    ff_serve_bytes($out['data'], 'image/webp', true);
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

    if (!$refresh && $ttl > 0) {
        $cached = ff_usage_cache_read($fs, $cachePath, $ttl);
        if ($cached !== null) {
            $cached['cache_age_seconds'] = max(0, time() - (int) strtotime($cached['computed_at'] ?? 'now'));
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

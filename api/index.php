<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiterFileStorage;
use FluxFiles\StorageMetadataHandler;

// Polyfill str_contains for PHP < 8.0
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

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
    $injection = "window.__FM_LOCALE__ = { locale: " . json_encode($locale) . ", dir: " . json_encode($dir) . ", messages: {$localeJson} };";
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
    $storagePath = __DIR__ . '/../storage';
    $metaRepo = new StorageMetadataHandler($diskManager);
    $fm = new FileManager($diskManager, $claims, $metaRepo);
    $fm->setQuotaManager(new QuotaManager($diskManager));

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

    // Rate limiting (JSON file)
    $rateLimiter = new RateLimiterFileStorage(
        $storagePath . '/rate_limit.json',
        (int) ($_ENV['FLUXFILES_RATE_LIMIT_READ'] ?? 60),
        (int) ($_ENV['FLUXFILES_RATE_LIMIT_WRITE'] ?? 10),
        60
    );
    $isWriteAction = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $rateLimiter->check($claims->userId, $isWriteAction ? 'write' : 'read');

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
        $auditDisk = $body['disk'] ?? $body['src_disk'] ?? $_POST['disk'] ?? 'local';
        $auditLog->log($claims->userId, $auditAction, $auditDisk, (string) $auditKey);
    }

    echo json_encode(['data' => $data, 'error' => null]);
} catch (ApiException $e) {
    http_response_code($e->getHttpCode());
    echo json_encode(['data' => null, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['data' => null, 'error' => 'Internal server error']);
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
        return $fm->list($_GET['disk'] ?? 'local', $_GET['path'] ?? '');
    }

    if ($method === 'POST' && $uri === '/api/fm/upload') {
        if (!isset($_FILES['file'])) {
            throw new ApiException('No file uploaded', 400);
        }
        return $fm->upload(
            $_POST['disk'] ?? 'local',
            $_POST['path'] ?? '',
            $_FILES['file'],
            (bool) ($_POST['force_upload'] ?? false)
        );
    }

    if ($method === 'DELETE' && $uri === '/api/fm/delete') {
        return $fm->delete(...jsonBody('disk', 'path'));
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
        return $fm->presign(...jsonBody('disk', 'path', 'method', 'ttl'));
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
            throw new ApiException('Missing path parameter', 400);
        }
        return $fm->fileMeta($_GET['disk'] ?? 'local', $path);
    }

    if ($method === 'GET' && $uri === '/api/fm/metadata') {
        return handleGetMetadata($metaRepo, $claims);
    }
    if ($method === 'PUT' && $uri === '/api/fm/metadata') {
        return handleSaveMetadata($metaRepo, $diskManager, $claims);
    }
    if ($method === 'DELETE' && $uri === '/api/fm/metadata') {
        return handleDeleteMetadata($metaRepo, $claims);
    }

    // Search
    if ($method === 'GET' && $uri === '/api/fm/search') {
        $q = $_GET['q'] ?? null;
        if ($q === null) {
            throw new ApiException('Missing search query', 400);
        }
        $disk = $_GET['disk'] ?? 'local';
        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403);
        }
        if (!$claims->hasPerm('read')) {
            throw new ApiException('Permission denied: read', 403);
        }
        return $metaRepo->search($disk, $q, (int) ($_GET['limit'] ?? 50), $claims->pathPrefix);
    }

    // Quota
    if ($method === 'GET' && $uri === '/api/fm/quota') {
        return $quotaManager->getQuotaInfo(
            $_GET['disk'] ?? 'local',
            $claims->pathPrefix,
            $claims->maxStorageMb
        );
    }

    // Audit log — users can only view their own logs
    if ($method === 'GET' && $uri === '/api/fm/audit') {
        return $auditLog->list(
            (int) ($_GET['limit'] ?? 100),
            (int) ($_GET['offset'] ?? 0),
            $claims->userId
        );
    }

    // Chunk upload
    if ($method === 'POST' && $uri === '/api/fm/chunk/init') {
        return handleChunkInit($chunker, $claims);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/presign') {
        return handleChunkPresign($chunker, $claims);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/complete') {
        return handleChunkComplete($chunker, $claims);
    }
    if ($method === 'POST' && $uri === '/api/fm/chunk/abort') {
        return handleChunkAbort($chunker, $claims);
    }

    throw new ApiException('Not found', 404);
}

function resolveAuditAction(string $uri): string
{
    $map = [
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
        throw new ApiException('Invalid JSON body', 400);
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

function handleGetMetadata(StorageMetadataHandler $metaRepo, \FluxFiles\Claims $claims): ?array
{
    $disk = $_GET['disk'] ?? null;
    $key  = $_GET['key'] ?? null;
    if ($disk === null) {
        throw new ApiException('Missing disk parameter', 400);
    }
    if ($key === null) {
        throw new ApiException('Missing key parameter', 400);
    }
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    if (!$claims->hasPerm('read')) {
        throw new ApiException('Permission denied: read', 403);
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403);
    }
    return $metaRepo->get($disk, $key);
}

function handleSaveMetadata(StorageMetadataHandler $metaRepo, DiskManager $diskManager, \FluxFiles\Claims $claims): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400);
    }

    $disk = $body['disk'] ?? null;
    $key  = $body['key'] ?? null;
    if ($disk === null) {
        throw new ApiException('Missing disk', 400);
    }
    if ($key === null) {
        throw new ApiException('Missing key', 400);
    }
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403);
    }

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

function handleDeleteMetadata(StorageMetadataHandler $metaRepo, \FluxFiles\Claims $claims): array
{
    [$disk, $key] = jsonBody('disk', 'key');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403);
    }
    $metaRepo->delete($disk, $key);
    return ['deleted' => true];
}

function handleChunkInit(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $path] = jsonBody('disk', 'path');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    $scopedPath = $claims->scopePath($path);
    return $chunker->initiate($disk, $scopedPath);
}

function handleChunkPresign(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $key, $uploadId, $partNumber] = jsonBody('disk', 'key', 'upload_id', 'part_number');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403);
    }
    return $chunker->presignPart($disk, $key, $uploadId, (int) $partNumber);
}

function handleChunkComplete(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $key, $uploadId, $parts] = jsonBody('disk', 'key', 'upload_id', 'parts');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403);
    }
    return $chunker->complete($disk, $key, $uploadId, $parts);
}

function handleChunkAbort(\FluxFiles\ChunkUploader $chunker, \FluxFiles\Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $key, $uploadId] = jsonBody('disk', 'key', 'upload_id');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    if (!$claims->isPathInScope($key)) {
        throw new ApiException('Access denied to path', 403);
    }
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

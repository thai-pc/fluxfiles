<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\MetadataRepository;

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

try {
    // Parse URI
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = rtrim($uri, '/');
    $method = $_SERVER['REQUEST_METHOD'];

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
    $dbPath = __DIR__ . '/../storage/fluxfiles.db';
    $metaRepo = new MetadataRepository($dbPath);
    $fm = new FileManager($diskManager, $claims, $metaRepo);
    $fm->setQuotaManager(new QuotaManager($diskManager));

    // Rate limiting
    $rateLimitDb = new \PDO("sqlite:{$dbPath}");
    $rateLimitDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $rateLimiter = new RateLimiter($rateLimitDb);
    $isWriteAction = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $rateLimiter->check($claims->userId, $isWriteAction ? 'write' : 'read');

    // Audit log
    $auditLog = new AuditLog($rateLimitDb);
    $chunker = new \FluxFiles\ChunkUploader($diskManager);
    $quotaManager = new QuotaManager($diskManager);

    // Routing
    $data = match (true) {
        $method === 'GET' && $uri === '/api/fm/list' => $fm->list(
            $_GET['disk'] ?? 'local',
            $_GET['path'] ?? ''
        ),

        $method === 'POST' && $uri === '/api/fm/upload' => $fm->upload(
            $_POST['disk'] ?? 'local',
            $_POST['path'] ?? '',
            $_FILES['file'] ?? throw new ApiException('No file uploaded', 400),
            (bool) ($_POST['force_upload'] ?? false)
        ),

        $method === 'DELETE' && $uri === '/api/fm/delete' => $fm->delete(
            ...jsonBody('disk', 'path')
        ),

        $method === 'POST' && $uri === '/api/fm/move' => $fm->move(
            ...jsonBody('disk', 'from', 'to')
        ),

        $method === 'POST' && $uri === '/api/fm/copy' => $fm->copy(
            ...jsonBody('disk', 'from', 'to')
        ),

        $method === 'POST' && $uri === '/api/fm/mkdir' => $fm->mkdir(
            ...jsonBody('disk', 'path')
        ),

        $method === 'POST' && $uri === '/api/fm/presign' => $fm->presign(
            ...jsonBody('disk', 'path', 'method', 'ttl')
        ),

        $method === 'GET' && $uri === '/api/fm/meta' => $fm->fileMeta(
            $_GET['disk'] ?? 'local',
            $_GET['path'] ?? throw new ApiException('Missing path parameter', 400)
        ),

        $method === 'GET' && $uri === '/api/fm/metadata' => handleGetMetadata($metaRepo),
        $method === 'PUT' && $uri === '/api/fm/metadata' => handleSaveMetadata($metaRepo, $diskManager),
        $method === 'DELETE' && $uri === '/api/fm/metadata' => handleDeleteMetadata($metaRepo),

        // Trash routes
        $method === 'GET' && $uri === '/api/fm/trash' => $fm->listTrash(
            $_GET['disk'] ?? 'local'
        ),
        $method === 'POST' && $uri === '/api/fm/restore' => $fm->restore(
            ...jsonBody('disk', 'path')
        ),
        $method === 'DELETE' && $uri === '/api/fm/purge' => $fm->purge(
            ...jsonBody('disk', 'path')
        ),

        // Search
        $method === 'GET' && $uri === '/api/fm/search' => $metaRepo->search(
            $_GET['disk'] ?? 'local',
            $_GET['q'] ?? throw new ApiException('Missing search query', 400),
            (int) ($_GET['limit'] ?? 50)
        ),

        // Quota
        $method === 'GET' && $uri === '/api/fm/quota' => $quotaManager->getQuotaInfo(
            $_GET['disk'] ?? 'local',
            $claims->pathPrefix,
            $claims->maxStorageMb
        ),

        // Audit log
        $method === 'GET' && $uri === '/api/fm/audit' => $auditLog->list(
            (int) ($_GET['limit'] ?? 100),
            (int) ($_GET['offset'] ?? 0),
            $_GET['user_id'] ?? null
        ),

        // Chunk upload (multipart) routes
        $method === 'POST' && $uri === '/api/fm/chunk/init' => handleChunkInit($chunker, $claims),
        $method === 'POST' && $uri === '/api/fm/chunk/presign' => handleChunkPresign($chunker, $claims),
        $method === 'POST' && $uri === '/api/fm/chunk/complete' => handleChunkComplete($chunker, $claims),
        $method === 'POST' && $uri === '/api/fm/chunk/abort' => handleChunkAbort($chunker, $claims),

        default => throw new ApiException('Not found', 404),
    };

    // Log write actions
    if ($isWriteAction && $data !== null) {
        $auditAction = match (true) {
            str_contains($uri, '/upload') => 'upload',
            str_contains($uri, '/delete') => 'delete',
            str_contains($uri, '/move') => 'move',
            str_contains($uri, '/copy') => 'copy',
            str_contains($uri, '/mkdir') => 'mkdir',
            str_contains($uri, '/restore') => 'restore',
            str_contains($uri, '/purge') => 'purge',
            str_contains($uri, '/metadata') => 'metadata_update',
            str_contains($uri, '/chunk') => 'chunk_upload',
            default => 'unknown',
        };
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true) ?: [];
        $auditKey = $body['path'] ?? $body['key'] ?? $body['from'] ?? '';
        $auditDisk = $body['disk'] ?? $_POST['disk'] ?? 'local';
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

function handleGetMetadata(MetadataRepository $metaRepo): ?array
{
    $disk = $_GET['disk'] ?? throw new ApiException('Missing disk parameter', 400);
    $key  = $_GET['key'] ?? throw new ApiException('Missing key parameter', 400);
    return $metaRepo->get($disk, $key);
}

function handleSaveMetadata(MetadataRepository $metaRepo, DiskManager $diskManager): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        throw new ApiException('Invalid JSON body', 400);
    }

    $disk = $body['disk'] ?? throw new ApiException('Missing disk', 400);
    $key  = $body['key'] ?? throw new ApiException('Missing key', 400);

    $data = [
        'title'    => $body['title'] ?? null,
        'alt_text' => $body['alt_text'] ?? null,
        'caption'  => $body['caption'] ?? null,
    ];

    $metaRepo->save($disk, $key, $data);
    $metaRepo->syncToS3Tags($disk, $key, $data, $diskManager);

    return ['saved' => true];
}

function handleDeleteMetadata(MetadataRepository $metaRepo): array
{
    [$disk, $key] = jsonBody('disk', 'key');
    $metaRepo->delete($disk, $key);
    return ['deleted' => true];
}

function handleChunkInit(\FluxFiles\ChunkUploader $chunker, Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $path] = jsonBody('disk', 'path');
    if (!$claims->hasDisk($disk)) {
        throw new ApiException("Access denied to disk: {$disk}", 403);
    }
    return $chunker->initiate($disk, $path);
}

function handleChunkPresign(\FluxFiles\ChunkUploader $chunker, Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $key, $uploadId, $partNumber] = jsonBody('disk', 'key', 'upload_id', 'part_number');
    return $chunker->presignPart($disk, $key, $uploadId, (int) $partNumber);
}

function handleChunkComplete(\FluxFiles\ChunkUploader $chunker, Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $key, $uploadId, $parts] = jsonBody('disk', 'key', 'upload_id', 'parts');
    return $chunker->complete($disk, $key, $uploadId, $parts);
}

function handleChunkAbort(\FluxFiles\ChunkUploader $chunker, Claims $claims): array
{
    if (!$claims->hasPerm('write')) {
        throw new ApiException('Permission denied: write', 403);
    }
    [$disk, $key, $uploadId] = jsonBody('disk', 'key', 'upload_id');
    return $chunker->abort($disk, $key, $uploadId);
}

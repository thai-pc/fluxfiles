<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Http\Controllers;

use FluxFiles\ApiException;
use FluxFiles\AuditLog;
use FluxFiles\ChunkUploader;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\MetadataRepository;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FluxFilesController
{
    private DiskManager $diskManager;
    private MetadataRepository $metaRepo;
    private \PDO $db;

    public function __construct()
    {
        $storagePath = config('fluxfiles.storage_path');

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $diskConfigs = config('fluxfiles.disks');
        $this->diskManager = new DiskManager($diskConfigs);

        $dbPath = $storagePath . '/fluxfiles.db';
        $this->metaRepo = new MetadataRepository($dbPath);

        $this->db = new \PDO("sqlite:{$dbPath}");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Resolve JWT claims from the request.
     */
    private function claims(Request $request): \FluxFiles\Claims
    {
        $secret = config('fluxfiles.secret');
        $token = $request->bearerToken();

        if (!$token) {
            throw new ApiException('Missing authorization token', 401);
        }

        return JwtMiddleware::handle($token, $secret);
    }

    /**
     * Build a FileManager instance for the current request.
     */
    private function fileManager(\FluxFiles\Claims $claims): FileManager
    {
        $fm = new FileManager($this->diskManager, $claims, $this->metaRepo);
        $fm->setQuotaManager(new QuotaManager($this->diskManager));
        return $fm;
    }

    /**
     * Apply rate limiting for the current request.
     */
    private function rateLimit(\FluxFiles\Claims $claims, bool $isWrite): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $rateLimiter->check($claims->userId, $isWrite ? 'write' : 'read');
    }

    /**
     * Log a write action to the audit log.
     */
    private function logAudit(
        \FluxFiles\Claims $claims,
        string $action,
        string $disk,
        string $key
    ): void {
        $auditLog = new AuditLog($this->db);
        $auditLog->log($claims->userId, $action, $disk, $key);
    }

    /**
     * Wrap a successful response.
     */
    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data, 'error' => null]);
    }

    /**
     * Wrap an error response.
     */
    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['data' => null, 'error' => $message], $status);
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    public function list(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->list(
                $request->query('disk', 'local'),
                $request->query('path', '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function upload(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $file = $request->file('file');
            if (!$file) {
                throw new ApiException('No file uploaded', 400);
            }

            // Convert UploadedFile to $_FILES-style array for FileManager
            $fileData = [
                'name'     => $file->getClientOriginalName(),
                'type'     => $file->getMimeType(),
                'tmp_name' => $file->getRealPath(),
                'error'    => $file->getError(),
                'size'     => $file->getSize(),
            ];

            $result = $fm->upload(
                $request->input('disk', 'local'),
                $request->input('path', ''),
                $fileData,
                (bool) $request->input('force_upload', false)
            );

            $this->audit(
                $claims,
                'upload',
                $request->input('disk', 'local'),
                $request->input('path', '')
            );

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function delete(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required field: disk or path', 400);
            }

            $result = $fm->delete($disk, $path);
            $this->logAudit($claims, 'delete', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function move(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $from = $request->input('from');
            $to   = $request->input('to');

            if (!$disk || !$from || !$to) {
                throw new ApiException('Missing required field: disk, from, or to', 400);
            }

            $result = $fm->move($disk, $from, $to);
            $this->logAudit($claims, 'move', $disk, $from);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function copy(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $from = $request->input('from');
            $to   = $request->input('to');

            if (!$disk || !$from || !$to) {
                throw new ApiException('Missing required field: disk, from, or to', 400);
            }

            $result = $fm->copy($disk, $from, $to);
            $this->logAudit($claims, 'copy', $disk, $from);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function mkdir(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required field: disk or path', 400);
            }

            $result = $fm->mkdir($disk, $path);
            $this->logAudit($claims, 'mkdir', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function crossCopy(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $srcDisk = $request->input('src_disk');
            $srcPath = $request->input('src_path');
            $dstDisk = $request->input('dst_disk');
            $dstPath = $request->input('dst_path');

            if (!$srcDisk || !$srcPath || !$dstDisk || !$dstPath) {
                throw new ApiException('Missing required fields: src_disk, src_path, dst_disk, dst_path', 400);
            }

            $result = $fm->crossCopy($srcDisk, $srcPath, $dstDisk, $dstPath);
            $this->logAudit($claims, 'cross_copy', $srcDisk, $srcPath);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function crossMove(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $srcDisk = $request->input('src_disk');
            $srcPath = $request->input('src_path');
            $dstDisk = $request->input('dst_disk');
            $dstPath = $request->input('dst_path');

            if (!$srcDisk || !$srcPath || !$dstDisk || !$dstPath) {
                throw new ApiException('Missing required fields: src_disk, src_path, dst_disk, dst_path', 400);
            }

            $result = $fm->crossMove($srcDisk, $srcPath, $dstDisk, $dstPath);
            $this->logAudit($claims, 'cross_move', $srcDisk, $srcPath);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function presign(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $disk   = $request->input('disk');
            $path   = $request->input('path');
            $method = $request->input('method');
            $ttl    = $request->input('ttl');

            if (!$disk || !$path || !$method || !$ttl) {
                throw new ApiException('Missing required fields', 400);
            }

            return $this->ok($fm->presign($disk, $path, $method, $ttl));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function meta(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $disk = $request->query('disk', 'local');
            $path = $request->query('path');

            if (!$path) {
                throw new ApiException('Missing path parameter', 400);
            }

            return $this->ok($fm->fileMeta($disk, $path));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function getMetadata(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $disk = $request->query('disk');
            $key  = $request->query('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key parameter', 400);
            }

            return $this->ok($this->metaRepo->get($disk, $key));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function saveMetadata(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            $disk = $request->input('disk');
            $key  = $request->input('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
            }

            $data = [
                'title'    => $request->input('title'),
                'alt_text' => $request->input('alt_text'),
                'caption'  => $request->input('caption'),
            ];

            $this->metaRepo->save($disk, $key, $data);
            $this->metaRepo->syncToS3Tags($disk, $key, $data, $this->diskManager);
            $this->logAudit($claims, 'metadata_update', $disk, $key);

            return $this->ok(['saved' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function deleteMetadata(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            $disk = $request->input('disk');
            $key  = $request->input('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
            }

            $this->metaRepo->delete($disk, $key);

            return $this->ok(['deleted' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Trash routes

    public function trash(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->listTrash($request->query('disk', 'local')));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function restore(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required field: disk or path', 400);
            }

            $result = $fm->restore($disk, $path);
            $this->logAudit($claims, 'restore', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function purge(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required field: disk or path', 400);
            }

            $result = $fm->purge($disk, $path);
            $this->logAudit($claims, 'purge', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Search

    public function search(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $disk  = $request->query('disk', 'local');
            $query = $request->query('q');

            if (!$query) {
                throw new ApiException('Missing search query', 400);
            }

            return $this->ok($this->metaRepo->search(
                $disk,
                $query,
                (int) $request->query('limit', 50)
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Quota

    public function quota(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $quotaManager = new QuotaManager($this->diskManager);

            return $this->ok($quotaManager->getQuotaInfo(
                $request->query('disk', 'local'),
                $claims->pathPrefix,
                $claims->maxStorageMb
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Audit

    public function audit(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $auditLog = new AuditLog($this->db);

            return $this->ok($auditLog->list(
                (int) $request->query('limit', 100),
                (int) $request->query('offset', 0),
                $request->query('user_id')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Chunk upload routes

    public function chunkInit(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }

            $chunker = new ChunkUploader($this->diskManager);
            $result = $chunker->initiate($disk, $path);
            $this->logAudit($claims, 'chunk_upload', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function chunkPresign(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $disk       = $request->input('disk');
            $key        = $request->input('key');
            $uploadId   = $request->input('upload_id');
            $partNumber = $request->input('part_number');

            if (!$disk || !$key || !$uploadId || !$partNumber) {
                throw new ApiException('Missing required fields', 400);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->presignPart($disk, $key, $uploadId, (int) $partNumber));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function chunkComplete(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $disk     = $request->input('disk');
            $key      = $request->input('key');
            $uploadId = $request->input('upload_id');
            $parts    = $request->input('parts');

            if (!$disk || !$key || !$uploadId || !$parts) {
                throw new ApiException('Missing required fields', 400);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->complete($disk, $key, $uploadId, $parts));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function chunkAbort(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $disk     = $request->input('disk');
            $key      = $request->input('key');
            $uploadId = $request->input('upload_id');

            if (!$disk || !$key || !$uploadId) {
                throw new ApiException('Missing required fields', 400);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->abort($disk, $key, $uploadId));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Static asset routes (proxy mode)

    public function publicIndex(): \Illuminate\Http\Response
    {
        $path = $this->fluxfilesBasePath() . '/public/index.html';

        if (!file_exists($path)) {
            abort(404, 'FluxFiles public/index.html not found');
        }

        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function sdkJs(): \Illuminate\Http\Response
    {
        $path = $this->fluxfilesBasePath() . '/fluxfiles.js';

        if (!file_exists($path)) {
            abort(404, 'FluxFiles SDK not found');
        }

        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function asset(string $file): \Illuminate\Http\Response
    {
        $basePath = $this->fluxfilesBasePath() . '/assets';
        $filePath = realpath($basePath . '/' . $file);

        // Prevent directory traversal
        if (!$filePath || !str_starts_with($filePath, realpath($basePath))) {
            abort(404);
        }

        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js'  => 'application/javascript',
            'css' => 'text/css',
            default => 'application/octet-stream',
        };

        return response(file_get_contents($filePath), 200)
            ->header('Content-Type', $mime . '; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function fluxfilesBasePath(): string
    {
        $configured = config('fluxfiles.base_path');

        if (!empty($configured)) {
            return rtrim($configured, '/');
        }

        // Default: assume FluxFiles is installed via composer
        return realpath(__DIR__ . '/../../../../..') ?: base_path('vendor/fluxfiles/laravel/../../..');
    }
}

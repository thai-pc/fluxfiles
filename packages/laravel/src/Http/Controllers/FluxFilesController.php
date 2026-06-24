<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Http\Controllers;

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\BucketDoctor;
use FluxFiles\ChunkUploader;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiterFileStorage;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FluxFilesController
{
    private DiskManager $diskManager;
    private StorageMetadataHandler $metaRepo;

    public function __construct()
    {
        $storagePath = config('fluxfiles.storage_path');

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $diskConfigs = config('fluxfiles.disks');
        $this->diskManager = new DiskManager($diskConfigs);
        $this->metaRepo = new StorageMetadataHandler($this->diskManager);
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
        foreach ($claims->byobDisks as $byobName => $byobConfig) {
            $this->diskManager->registerByobDisk($byobName, $byobConfig);
        }
        $fm = new FileManager($this->diskManager, $claims, $this->metaRepo);
        $fm->setQuotaManager(new QuotaManager($this->diskManager));
        return $fm;
    }

    /**
     * Apply rate limiting for the current request.
     */
    private function rateLimit(\FluxFiles\Claims $claims, bool $isWrite): void
    {
        $storagePath = config('fluxfiles.storage_path');
        // Per-tenant `rate_read`/`rate_write` claims override the server defaults.
        // `?? 0` tolerates a core older than 0.2.8 (property absent) — degrade to the
        // configured default rather than warn/fatal on a version mismatch.
        $readLimit  = ($claims->rateRead ?? 0) > 0 ? $claims->rateRead : (int) config('fluxfiles.rate_limit_read', 60);
        $writeLimit = ($claims->rateWrite ?? 0) > 0 ? $claims->rateWrite : (int) config('fluxfiles.rate_limit_write', 10);
        $rateLimiter = new RateLimiterFileStorage($storagePath . '/rate_limit.json', $readLimit, $writeLimit);
        $rateLimiter->check($claims->userId, $isWrite ? 'write' : 'read');
    }

    /**
     * Log a write action to the audit log (lưu trong storage của user).
     */
    private function logAudit(
        \FluxFiles\Claims $claims,
        string $action,
        string $disk,
        string $key
    ): void {
        $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
        $audit->log($claims->userId, $action, $disk, $key);
    }

    /**
     * Wrap a successful response.
     */
    /**
     * @param mixed $data
     */
    private function ok($data): JsonResponse
    {
        return response()->json(['data' => $data, 'error' => null]);
    }

    /**
     * Wrap an error response.
     */
    private function error(string $message, int $status = 400, ?string $code = null, array $params = []): JsonResponse
    {
        // Forward the core's error_code + error_params so the embedded UI can show a
        // LOCALISED message (it maps `error.<code>` via i18n). Without these the UI
        // falls back to the raw English message — the whole point of this passthrough.
        $resp = ['data' => null, 'error' => $message];
        if ($code !== null) {
            $resp['error_code'] = $code;
        }
        if ($params !== []) {
            $resp['error_params'] = $params;
        }
        return response()->json($resp, $status);
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
                $request->query('path', ''),
                max(0, (int) $request->query('limit', 0)),
                (string) $request->query('cursor', '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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

            // Cast to string: input() returns null when the field is present but
            // empty/null, and FileManager::upload() type-hints `string $path` — an
            // unguarded null there throws a TypeError (HTTP 500) before the
            // extension check even runs. '' is a valid path (upload to root).
            $disk = (string) ($request->input('disk') ?? 'local');
            $path = (string) ($request->input('path') ?? '');

            $result = $fm->upload(
                $disk !== '' ? $disk : 'local',
                $path,
                $fileData,
                (bool) $request->input('force_upload', false)
            );

            $this->logAudit($claims, 'upload', $disk !== '' ? $disk : 'local', $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        } catch (\Throwable $e) {
            // Never leak a TypeError/HTML error page from the API surface.
            return $this->error('Upload failed: ' . $e->getMessage(), 500);
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function rename(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $path = $request->input('path');
            $name = $request->input('name');

            if (!$disk || !$path || !$name) {
                throw new ApiException('Missing required field: disk, path or name', 400);
            }

            $result = $fm->rename($disk, $path, $name);
            $this->logAudit($claims, 'rename', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function importUrl(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $url = (string) $request->input('url', '');
            if ($url === '') {
                throw new ApiException('Missing required field: url', 400, 'missing_param');
            }

            $disk = (string) $request->input('disk', 'local');
            $result = (new UrlImporter($claims, $fm))->import($disk, $url, [
                'path'      => (string) $request->input('path', ''),
                'filename'  => $request->input('filename') !== null ? (string) $request->input('filename') : null,
                'overwrite' => filter_var($request->input('overwrite', false), FILTER_VALIDATE_BOOLEAN),
            ]);
            $this->logAudit($claims, 'url_import', $disk, (string) ($result['key'] ?? ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function crop(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk   = $request->input('disk');
            $path   = $request->input('path');
            $x      = $request->input('x');
            $y      = $request->input('y');
            $width  = $request->input('width');
            $height = $request->input('height');

            if (!$disk || !$path || $x === null || $y === null || !$width || !$height) {
                throw new ApiException('Missing required fields', 400);
            }

            $result = $fm->cropImage(
                $disk,
                $path,
                (int) $x,
                (int) $y,
                (int) $width,
                (int) $height,
                $request->input('save_path')
            );

            $this->logAudit($claims, 'crop', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function aiTag(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            // Configure AI tagger if available
            $aiProvider = config('fluxfiles.ai_provider', env('FLUXFILES_AI_PROVIDER', ''));
            if (empty($aiProvider)) {
                throw new ApiException('AI tagging is not configured', 400);
            }

            $aiTagger = new \FluxFiles\AiTagger(
                $aiProvider,
                config('fluxfiles.ai_api_key', env('FLUXFILES_AI_API_KEY', '')),
                config('fluxfiles.ai_model', env('FLUXFILES_AI_MODEL')) ?: null
            );
            $fm->setAiTagger($aiTagger);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields: disk, path', 400);
            }

            $result = $fm->aiTag($disk, $path);
            $this->logAudit($claims, 'ai_tag', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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

            return $this->ok($fm->presign(
                $disk,
                $path,
                strtoupper((string) $method),
                (int) $ttl,
                (int) ($request->input('size') ?? $request->input('size_bytes') ?? 0)
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function getMetadata(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $disk = $request->query('disk');
            $key  = $request->query('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key parameter', 400);
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
            $fm->validateScopedPath($key);

            return $this->ok($this->metaRepo->get($disk, $key));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function saveMetadata(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $key  = $request->input('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
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
            $fm->assertCanModifyScopedPath($disk, $key);

            $data = [
                'title'    => $request->input('title'),
                'alt_text' => $request->input('alt_text'),
                'caption'  => $request->input('caption'),
                'tags'     => $request->input('tags'),
            ];

            $this->metaRepo->save($disk, $key, $data);
            $this->metaRepo->syncToS3Tags($disk, $key, $data, $this->diskManager);
            $this->logAudit($claims, 'metadata_update', $disk, $key);

            return $this->ok(['saved' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function deleteMetadata(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $key  = $request->input('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
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
            $fm->assertCanModifyScopedPath($disk, $key);

            $this->metaRepo->delete($disk, $key);

            return $this->ok(['deleted' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }

            return $this->ok($this->metaRepo->search(
                $disk,
                $query,
                (int) $request->query('limit', 50),
                $claims->pathPrefix,
                $claims->showHidden
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function searchFolders(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $disk  = $request->query('disk', 'local');
            $query = $request->query('q');

            if (!$query) {
                throw new ApiException('Missing search query', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }

            return $this->ok($this->metaRepo->searchFolders(
                $disk,
                $query,
                (int) $request->query('limit', 50),
                $claims->pathPrefix,
                $claims->showHidden
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
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
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Storage usage dashboard: quota + per-type/-folder breakdown (one
     * listContents pass via getUsageBreakdown). Proxy mode recomputes each call
     * (no cache layer here); the standalone core endpoint adds the file cache.
     */
    public function usage(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $disk = $request->query('disk', 'local');
            $quotaManager = new QuotaManager($this->diskManager);
            $top = $claims->usageTopFoldersCount > 0 ? $claims->usageTopFoldersCount : 10;
            $depth = $claims->usageFolderDepth > 0 ? $claims->usageFolderDepth : 1;

            $breakdown = $quotaManager->getUsageBreakdown($disk, $claims->pathPrefix, $top, $depth);
            $resp = $quotaManager->usageResponse(
                $breakdown,
                $claims->maxStorageMb,
                $claims->usageWarningThreshold,
                $claims->usageCriticalThreshold
            );
            $resp['cache_age_seconds'] = 0;

            return $this->ok($resp);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Commercial edition / license status (server-wide, non-sensitive). Reads the
     * app's FLUXFILES_LICENSE_KEY env; free core → {edition:'free'}.
     */
    public function license(Request $request): JsonResponse
    {
        try {
            $this->rateLimit($this->claims($request), false);

            return $this->ok(\FluxFiles\LicenseManager::fromEnv()->info());
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Optimization (paid module). The 3-layer gate lives in ModuleRegistry: module
     * installed (501) + licensed (402) + allow_optimize claim (403). Free hosts
     * without the module package → 501.
     */
    public function optimize(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('optimize', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->run($fm, $this->diskManager, new \FluxFiles\ImageOptimizer(), $claims, $request->all());
            $this->logAudit($claims, 'optimize', (string) $request->input('disk', 'local'), (string) $request->input('path', ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Config / code editor — read a file's text content. Disk/perm/scope/size/
     * binary checks all live inside FileManager::getContent (single source of truth).
     */
    public function getContent(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->getContent(
                $request->query('disk', 'local'),
                (string) $request->query('path', '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Config / code editor — overwrite a file's text content. The allow_code_edit
     * claim gate, write perm, allowed_ext, file-must-exist, and size cap are all
     * enforced inside FileManager::putContent.
     */
    public function putContent(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $result = $fm->putContent(
                (string) $request->input('disk', 'local'),
                (string) $request->input('path', ''),
                (string) $request->input('content', '')
            );
            $this->logAudit($claims, 'content_edit', (string) $request->input('disk', 'local'), (string) $request->input('path', ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Extract a zip in place. Slip/bomb/quota/dangerous-ext guards all live inside
     * FileManager::extractZip (single source of truth).
     */
    public function extract(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $result = $fm->extractZip(
                (string) $request->input('disk', 'local'),
                (string) $request->input('path', ''),
                $request->input('dest') !== null ? (string) $request->input('dest') : null
            );
            $this->logAudit($claims, 'extract', (string) $request->input('disk', 'local'), (string) $request->input('path', ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Trash (soft-delete) — gated by the 'delete' permission inside FileManager

    public function trash(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            $path = $request->input('path');
            if (!$disk || $path === null) {
                throw new ApiException('Missing required field: disk or path', 400, 'missing_param');
            }

            $result = $fm->trash((string) $disk, (string) $path);
            $this->logAudit($claims, 'trash', (string) $disk, (string) $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function trashRestore(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk    = $request->input('disk');
            $trashId = $request->input('trash_id');
            if (!$disk || !$trashId) {
                throw new ApiException('Missing required field: disk/trash_id', 400, 'missing_param');
            }

            $result = $fm->restore((string) $disk, (string) $trashId, $request->input('path'));
            $this->logAudit($claims, 'restore', (string) $disk, (string) $trashId);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function trashList(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->listTrash((string) $request->query('disk', 'local')));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function trashPurge(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk    = $request->input('disk');
            $trashId = $request->input('trash_id');
            if (!$disk || !$trashId) {
                throw new ApiException('Missing required field: disk/trash_id', 400, 'missing_param');
            }

            $result = $fm->purgeTrash((string) $disk, (string) $trashId);
            $this->logAudit($claims, 'purge', (string) $disk, (string) $trashId);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function trashEmpty(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = $request->input('disk');
            if (!$disk) {
                throw new ApiException('Missing required field: disk', 400, 'missing_param');
            }

            $result = $fm->emptyTrash((string) $disk);
            $this->logAudit($claims, 'empty_trash', (string) $disk, '');

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Bucket Doctor — diagnose a disk backend (writes/deletes a probe object,
    // so it requires the 'write' permission on a disk the token may access).

    public function diskDoctor(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            // Build the FileManager so any BYOB disks in the token are registered
            // on the DiskManager before BucketDoctor probes them.
            $this->fileManager($claims);

            $disk = (string) $request->query('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $origin = $request->header('Origin') ?: $request->query('origin');

            return $this->ok((new BucketDoctor($this->diskManager))->diagnose($disk, $origin ?: null));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Audit

    public function audit(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);

            return $this->ok($audit->list(
                (int) $request->query('limit', 100),
                (int) $request->query('offset', 0),
                $claims->userId
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Chunk upload routes

    public function chunkInit(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

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

            $sizeBytes = (int) ($request->input('size') ?? $request->input('size_bytes') ?? 0);
            if ($sizeBytes <= 0) {
                throw new ApiException('Missing required field: size', 400);
            }
            $scopedPath = $fm->validateUserPath($path);
            $fm->validateUploadName(basename($scopedPath), $sizeBytes);
            if ($claims->maxStorageMb > 0 && $sizeBytes > 0) {
                (new QuotaManager($this->diskManager))->assertQuota(
                    $disk,
                    $claims->pathPrefix,
                    $sizeBytes,
                    $claims->maxStorageMb
                );
            }

            $chunker = new ChunkUploader($this->diskManager);
            $result = $chunker->initiate($disk, $scopedPath);
            $this->logAudit($claims, 'chunk_upload', $disk, $scopedPath);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function chunkPresign(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }
            $fm->validateScopedPath($key);

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->presignPart($disk, $key, $uploadId, (int) $partNumber));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function chunkComplete(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }
            $fm->validateScopedPath($key);

            $chunker = new ChunkUploader($this->diskManager);

            $result = $chunker->complete($disk, $key, $uploadId, $parts);
            $this->metaRepo->save($disk, $key, [
                'uploaded_by' => $claims->userId,
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function chunkAbort(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $disk     = $request->input('disk');
            $key      = $request->input('key');
            $uploadId = $request->input('upload_id');

            if (!$disk || !$key || !$uploadId) {
                throw new ApiException('Missing required fields', 400);
            }
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }
            $fm->validateScopedPath($key);

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->abort($disk, $key, $uploadId));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // Language routes (public)

    public function langList(): JsonResponse
    {
        $langPath = $this->fluxfilesBasePath() . '/lang';
        $files = glob($langPath . '/*.json');
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

        return $this->ok($result);
    }

    public function langGet(string $locale): JsonResponse
    {
        if (!preg_match('/^[a-z]{2,5}$/', $locale)) {
            return $this->error('Invalid locale', 400);
        }

        $path = $this->fluxfilesBasePath() . "/lang/{$locale}.json";

        if (!file_exists($path)) {
            return $this->error('Locale not found', 404);
        }

        $data = json_decode(file_get_contents($path), true);

        return $this->ok([
            'locale'   => $data['_meta']['locale'] ?? $locale,
            'dir'      => $data['_meta']['direction'] ?? 'ltr',
            'messages' => $data,
        ]);
    }

    // Static asset routes (proxy mode)

    public function publicIndex(): \Illuminate\Http\Response
    {
        $base = $this->fluxfilesBasePath();
        $path = $base . '/public/index.html';

        if (!file_exists($path)) {
            abort(404, 'FluxFiles public/index.html not found');
        }

        $html = file_get_contents($path);
        // Cache-bust the UI assets with a content hash, so a core update is never
        // served from a stale browser/proxy cache (the static fm.js/fm.css URLs
        // carry no version of their own).
        $ver = static function (string $file) use ($base): string {
            $p = $base . '/assets/' . $file;
            return is_file($p) ? substr(md5_file($p), 0, 10) : (string) time();
        };
        $html = str_replace(
            ['"../assets/fm.css"', '"../assets/fm.js"'],
            ['"../assets/fm.css?v=' . $ver('fm.css') . '"', '"../assets/fm.js?v=' . $ver('fm.js') . '"'],
            $html
        );

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            // Always revalidate the HTML so the ?v= asset URLs are never stale.
            ->header('Cache-Control', 'no-cache, must-revalidate');
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
        if (!$filePath || strncmp($filePath, realpath($basePath), strlen(realpath($basePath))) !== 0) {
            abort(404);
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        switch ($ext) {
            case 'js':
                $mime = 'application/javascript';
                break;
            case 'css':
                $mime = 'text/css';
                break;
            default:
                $mime = 'application/octet-stream';
                break;
        }

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

        // Default: assume FluxFiles core is installed via composer
        // (and adapter itself lives in vendor/fluxfiles/laravel)
        $coreFromVendor = base_path('vendor/fluxfiles/fluxfiles');
        if (is_dir($coreFromVendor)) {
            return $coreFromVendor;
        }

        // Fallbacks for non-Laravel contexts or unusual install layouts
        $adapterVendorRoot = realpath(__DIR__ . '/../../../../..');
        if (!empty($adapterVendorRoot)) {
            $coreSibling = realpath($adapterVendorRoot . '/../fluxfiles');
            if (!empty($coreSibling)) {
                return $coreSibling;
            }
        }

        return base_path();
    }
}

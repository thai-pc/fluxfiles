<?php

defined('ABSPATH') || exit;

use FluxFiles\ApiException;
use FluxFiles\AuditLog;
use FluxFiles\ChunkUploader;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\MetadataRepository;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiter;

/**
 * REST API controller — registers all FluxFiles endpoints under /wp-json/fluxfiles/v1/.
 */
class FluxFilesApi
{
    private DiskManager $diskManager;
    private MetadataRepository $metaRepo;
    private \PDO $db;

    public function __construct()
    {
        $storagePath = FluxFilesPlugin::storagePath();

        if (!is_dir($storagePath)) {
            wp_mkdir_p($storagePath);
        }

        $this->diskManager = new DiskManager(FluxFilesPlugin::diskConfigs());

        $dbPath = $storagePath . '/fluxfiles.db';
        $this->metaRepo = new MetadataRepository($dbPath);

        $this->db = new \PDO("sqlite:{$dbPath}");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public static function registerRoutes(): void
    {
        $api = new self();
        $ns  = 'fluxfiles/v1';

        $readArgs  = ['methods' => 'GET', 'permission_callback' => [self::class, 'checkAuth']];
        $writeArgs = ['methods' => 'POST', 'permission_callback' => [self::class, 'checkAuth']];

        // Core file operations
        register_rest_route($ns, '/list', array_merge($readArgs, [
            'callback' => [$api, 'handleList'],
        ]));
        register_rest_route($ns, '/upload', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleUpload'],
        ]);
        register_rest_route($ns, '/delete', [
            'methods'             => 'DELETE',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleDelete'],
        ]);
        register_rest_route($ns, '/move', array_merge($writeArgs, [
            'callback' => [$api, 'handleMove'],
        ]));
        register_rest_route($ns, '/copy', array_merge($writeArgs, [
            'callback' => [$api, 'handleCopy'],
        ]));
        register_rest_route($ns, '/mkdir', array_merge($writeArgs, [
            'callback' => [$api, 'handleMkdir'],
        ]));
        register_rest_route($ns, '/cross-copy', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrossCopy'],
        ]));
        register_rest_route($ns, '/cross-move', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrossMove'],
        ]));
        register_rest_route($ns, '/presign', array_merge($writeArgs, [
            'callback' => [$api, 'handlePresign'],
        ]));

        // File meta
        register_rest_route($ns, '/meta', array_merge($readArgs, [
            'callback' => [$api, 'handleMeta'],
        ]));

        // Metadata CRUD
        register_rest_route($ns, '/metadata', [
            [
                'methods'             => 'GET',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleGetMetadata'],
            ],
            [
                'methods'             => 'PUT',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleSaveMetadata'],
            ],
            [
                'methods'             => 'DELETE',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleDeleteMetadata'],
            ],
        ]);

        // Trash
        register_rest_route($ns, '/trash', array_merge($readArgs, [
            'callback' => [$api, 'handleTrash'],
        ]));
        register_rest_route($ns, '/restore', array_merge($writeArgs, [
            'callback' => [$api, 'handleRestore'],
        ]));
        register_rest_route($ns, '/purge', [
            'methods'             => 'DELETE',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handlePurge'],
        ]);

        // Search, quota, audit
        register_rest_route($ns, '/search', array_merge($readArgs, [
            'callback' => [$api, 'handleSearch'],
        ]));
        register_rest_route($ns, '/quota', array_merge($readArgs, [
            'callback' => [$api, 'handleQuota'],
        ]));
        register_rest_route($ns, '/audit', array_merge($readArgs, [
            'callback' => [$api, 'handleAudit'],
        ]));

        // Chunk upload
        register_rest_route($ns, '/chunk/init', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkInit'],
        ]));
        register_rest_route($ns, '/chunk/presign', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkPresign'],
        ]));
        register_rest_route($ns, '/chunk/complete', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkComplete'],
        ]));
        register_rest_route($ns, '/chunk/abort', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkAbort'],
        ]));
    }

    /**
     * Permission callback — user must be logged in.
     */
    public static function checkAuth(\WP_REST_Request $request): bool
    {
        return is_user_logged_in();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Resolve JWT claims — auto-generates a token from the current WP user.
     */
    private function claims(): \FluxFiles\Claims
    {
        $token  = FluxFilesPlugin::tokenForCurrentUser();
        $secret = get_option('fluxfiles_secret', '');

        return JwtMiddleware::handle($token, $secret);
    }

    private function fileManager(\FluxFiles\Claims $claims): FileManager
    {
        $fm = new FileManager($this->diskManager, $claims, $this->metaRepo);
        $fm->setQuotaManager(new QuotaManager($this->diskManager));
        return $fm;
    }

    private function rateLimit(\FluxFiles\Claims $claims, bool $isWrite): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $rateLimiter->check($claims->userId, $isWrite ? 'write' : 'read');
    }

    private function logAudit(\FluxFiles\Claims $claims, string $action, string $disk, string $key): void
    {
        $auditLog = new AuditLog($this->db);
        $auditLog->log($claims->userId, $action, $disk, $key);
    }

    private function ok(mixed $data): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $data, 'error' => null], 200);
    }

    private function error(string $message, int $status = 400): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => null, 'error' => $message], $status);
    }

    /**
     * Get JSON body from request.
     */
    private function body(\WP_REST_Request $request): array
    {
        return $request->get_json_params() ?: [];
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    public function handleList(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->list(
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? ''
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleUpload(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $files = $request->get_file_params();
            if (empty($files['file'])) {
                throw new ApiException('No file uploaded', 400);
            }

            $result = $fm->upload(
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? '',
                $files['file'],
                (bool) ($request->get_param('force_upload') ?? false)
            );

            $this->logAudit(
                $claims,
                'upload',
                $request->get_param('disk') ?? 'local',
                $request->get_param('path') ?? ''
            );

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleDelete(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

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

    public function handleMove(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $from = $body['from'] ?? null;
            $to   = $body['to'] ?? null;

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

    public function handleCopy(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $from = $body['from'] ?? null;
            $to   = $body['to'] ?? null;

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

    public function handleMkdir(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

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

    public function handleCrossCopy(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body    = $this->body($request);
            $srcDisk = $body['src_disk'] ?? null;
            $srcPath = $body['src_path'] ?? null;
            $dstDisk = $body['dst_disk'] ?? null;
            $dstPath = $body['dst_path'] ?? null;

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

    public function handleCrossMove(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body    = $this->body($request);
            $srcDisk = $body['src_disk'] ?? null;
            $srcPath = $body['src_path'] ?? null;
            $dstDisk = $body['dst_disk'] ?? null;
            $dstPath = $body['dst_path'] ?? null;

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

    public function handlePresign(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $body   = $this->body($request);
            $disk   = $body['disk'] ?? null;
            $path   = $body['path'] ?? null;
            $method = $body['method'] ?? null;
            $ttl    = $body['ttl'] ?? null;

            if (!$disk || !$path || !$method || !$ttl) {
                throw new ApiException('Missing required fields', 400);
            }

            return $this->ok($fm->presign($disk, $path, $method, $ttl));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleMeta(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            $disk = $request->get_param('disk') ?? 'local';
            $path = $request->get_param('path');

            if (!$path) {
                throw new ApiException('Missing path parameter', 400);
            }

            return $this->ok($fm->fileMeta($disk, $path));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleGetMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk = $request->get_param('disk');
            $key  = $request->get_param('key');

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key parameter', 400);
            }

            return $this->ok($this->metaRepo->get($disk, $key));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleSaveMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $key  = $body['key'] ?? null;

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
            }

            $data = [
                'title'    => $body['title'] ?? null,
                'alt_text' => $body['alt_text'] ?? null,
                'caption'  => $body['caption'] ?? null,
            ];

            $this->metaRepo->save($disk, $key, $data);
            $this->metaRepo->syncToS3Tags($disk, $key, $data, $this->diskManager);
            $this->logAudit($claims, 'metadata_update', $disk, $key);

            return $this->ok(['saved' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleDeleteMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $key  = $body['key'] ?? null;

            if (!$disk || !$key) {
                throw new ApiException('Missing disk or key', 400);
            }

            $this->metaRepo->delete($disk, $key);

            return $this->ok(['deleted' => true]);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Trash

    public function handleTrash(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->listTrash($request->get_param('disk') ?? 'local'));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleRestore(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

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

    public function handlePurge(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

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

    public function handleSearch(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk  = $request->get_param('disk') ?? 'local';
            $query = $request->get_param('q');

            if (!$query) {
                throw new ApiException('Missing search query', 400);
            }

            return $this->ok($this->metaRepo->search(
                $disk,
                $query,
                (int) ($request->get_param('limit') ?? 50)
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Quota

    public function handleQuota(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $quotaManager = new QuotaManager($this->diskManager);

            return $this->ok($quotaManager->getQuotaInfo(
                $request->get_param('disk') ?? 'local',
                $claims->pathPrefix,
                $claims->maxStorageMb
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Audit

    public function handleAudit(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $auditLog = new AuditLog($this->db);

            return $this->ok($auditLog->list(
                (int) ($request->get_param('limit') ?? 100),
                (int) ($request->get_param('offset') ?? 0),
                $request->get_param('user_id')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Chunk upload

    public function handleChunkInit(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

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

    public function handleChunkPresign(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body       = $this->body($request);
            $disk       = $body['disk'] ?? null;
            $key        = $body['key'] ?? null;
            $uploadId   = $body['upload_id'] ?? null;
            $partNumber = $body['part_number'] ?? null;

            if (!$disk || !$key || !$uploadId || !$partNumber) {
                throw new ApiException('Missing required fields', 400);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->presignPart($disk, $key, $uploadId, (int) $partNumber));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleChunkComplete(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body     = $this->body($request);
            $disk     = $body['disk'] ?? null;
            $key      = $body['key'] ?? null;
            $uploadId = $body['upload_id'] ?? null;
            $parts    = $body['parts'] ?? null;

            if (!$disk || !$key || !$uploadId || !$parts) {
                throw new ApiException('Missing required fields', 400);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->complete($disk, $key, $uploadId, $parts));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleChunkAbort(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);

            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }

            $body     = $this->body($request);
            $disk     = $body['disk'] ?? null;
            $key      = $body['key'] ?? null;
            $uploadId = $body['upload_id'] ?? null;

            if (!$disk || !$key || !$uploadId) {
                throw new ApiException('Missing required fields', 400);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->abort($disk, $key, $uploadId));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }
}

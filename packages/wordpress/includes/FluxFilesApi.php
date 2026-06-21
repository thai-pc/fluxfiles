<?php

defined('ABSPATH') || exit;

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\ChunkUploader;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\JwtMiddleware;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiterFileStorage;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;

/**
 * REST API controller — registers all FluxFiles endpoints under /wp-json/fluxfiles/v1/.
 */
class FluxFilesApi
{
    private DiskManager $diskManager;
    private StorageMetadataHandler $metaRepo;

    public function __construct()
    {
        $storagePath = FluxFilesPlugin::storagePath();

        if (!is_dir($storagePath)) {
            wp_mkdir_p($storagePath);
        }

        $this->diskManager = new DiskManager(FluxFilesPlugin::diskConfigs());
        $this->metaRepo = new StorageMetadataHandler($this->diskManager);
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /**
     * When a request to our namespace carries a valid Bearer JWT, suppress
     * WP REST's default cookie/nonce auth check so the request reaches our
     * permission_callback instead of being rejected with rest_cookie_invalid_nonce.
     */
    public static function bypassCookieAuthForBearer($result)
    {
        // Only intervene on our routes.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if (strpos($uri, '/fluxfiles/v1/') === false && strpos($query, 'fluxfiles/v1') === false) {
            return $result;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+/i', $auth)) {
            return $result;
        }
        // A Bearer is present — let permission_callback decide.
        return null;
    }

    public static function registerRoutes(): void
    {
        $api = new self();
        $ns  = 'fluxfiles/v1';

        // Stop WP's cookie/nonce auth from short-circuiting when we already have
        // a Bearer JWT. Hooked at priority 999 so it runs AFTER WP's default
        // `rest_cookie_check_errors` (priority 100) and can clear its WP_Error.
        add_filter('rest_authentication_errors', [self::class, 'bypassCookieAuthForBearer'], 999);

        // The browser SDK builds URLs as `${endpoint}/api/fm/<route>` — keep that
        // prefix here so the same fm.js works against either the standalone PHP
        // server or this WP REST proxy without conditionals.
        $p = '/api/fm';
        $readArgs  = ['methods' => 'GET', 'permission_callback' => [self::class, 'checkAuth']];
        $writeArgs = ['methods' => 'POST', 'permission_callback' => [self::class, 'checkAuth']];

        // Core file operations
        register_rest_route($ns, $p . '/list', array_merge($readArgs, [
            'callback' => [$api, 'handleList'],
        ]));
        register_rest_route($ns, $p . '/upload', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleUpload'],
        ]);
        register_rest_route($ns, $p . '/import-url', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleImportUrl'],
        ]);
        register_rest_route($ns, $p . '/delete', [
            'methods'             => 'DELETE',
            'permission_callback' => [self::class, 'checkAuth'],
            'callback'            => [$api, 'handleDelete'],
        ]);
        register_rest_route($ns, $p . '/rename', array_merge($writeArgs, [
            'callback' => [$api, 'handleRename'],
        ]));
        register_rest_route($ns, $p . '/move', array_merge($writeArgs, [
            'callback' => [$api, 'handleMove'],
        ]));
        register_rest_route($ns, $p . '/copy', array_merge($writeArgs, [
            'callback' => [$api, 'handleCopy'],
        ]));
        register_rest_route($ns, $p . '/mkdir', array_merge($writeArgs, [
            'callback' => [$api, 'handleMkdir'],
        ]));
        register_rest_route($ns, $p . '/cross-copy', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrossCopy'],
        ]));
        register_rest_route($ns, $p . '/cross-move', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrossMove'],
        ]));
        register_rest_route($ns, $p . '/crop', array_merge($writeArgs, [
            'callback' => [$api, 'handleCrop'],
        ]));
        register_rest_route($ns, $p . '/ai-tag', array_merge($writeArgs, [
            'callback' => [$api, 'handleAiTag'],
        ]));
        register_rest_route($ns, $p . '/presign', array_merge($writeArgs, [
            'callback' => [$api, 'handlePresign'],
        ]));

        // File meta
        register_rest_route($ns, $p . '/meta', array_merge($readArgs, [
            'callback' => [$api, 'handleMeta'],
        ]));

        // Metadata CRUD
        register_rest_route($ns, $p . '/metadata', [
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

        // Config / code editor (works on any disk)
        register_rest_route($ns, $p . '/content', [
            [
                'methods'             => 'GET',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handleGetContent'],
            ],
            [
                'methods'             => 'PUT',
                'permission_callback' => [self::class, 'checkAuth'],
                'callback'            => [$api, 'handlePutContent'],
            ],
        ]);

        // Extract a zip in place (works on any disk; returns JSON)
        register_rest_route($ns, $p . '/extract', array_merge($writeArgs, [
            'callback' => [$api, 'handleExtract'],
        ]));

        // Search, quota, audit
        register_rest_route($ns, $p . '/search', array_merge($readArgs, [
            'callback' => [$api, 'handleSearch'],
        ]));
        register_rest_route($ns, $p . '/search-folders', array_merge($readArgs, [
            'callback' => [$api, 'handleSearchFolders'],
        ]));
        register_rest_route($ns, $p . '/quota', array_merge($readArgs, [
            'callback' => [$api, 'handleQuota'],
        ]));
        register_rest_route($ns, $p . '/audit', array_merge($readArgs, [
            'callback' => [$api, 'handleAudit'],
        ]));

        // Chunk upload
        register_rest_route($ns, $p . '/chunk/init', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkInit'],
        ]));
        register_rest_route($ns, $p . '/chunk/presign', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkPresign'],
        ]));
        register_rest_route($ns, $p . '/chunk/complete', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkComplete'],
        ]));
        register_rest_route($ns, $p . '/chunk/abort', array_merge($writeArgs, [
            'callback' => [$api, 'handleChunkAbort'],
        ]));

        // ---------------------------------------------------------------------
        // UI / asset / locale proxy — public routes so the iframe can load HTML
        // and assets without going through the WP login flow. Auth still applies
        // to every /api/fm/ data route above.
        // ---------------------------------------------------------------------
        $publicArgs = ['methods' => 'GET', 'permission_callback' => '__return_true'];

        register_rest_route($ns, '/public/index.html', array_merge($publicArgs, [
            'callback' => [$api, 'serveUiHtml'],
        ]));
        register_rest_route($ns, '/assets/(?P<file>fm\.(?:js|css))', array_merge($publicArgs, [
            'callback' => [$api, 'serveUiAsset'],
        ]));
        register_rest_route($ns, $p . '/lang', array_merge($publicArgs, [
            'callback' => [$api, 'serveLangList'],
        ]));
        register_rest_route($ns, $p . '/lang/(?P<locale>[a-z]{2,5})', array_merge($publicArgs, [
            'callback' => [$api, 'serveLangMessages'],
        ]));
    }

    /**
     * Permission callback — accept either:
     *   1. A valid Bearer JWT (signed with FLUXFILES_SECRET), used by fm.js
     *      from inside the iframe.
     *   2. A logged-in WP user with a valid REST nonce (X-WP-Nonce header),
     *      used when the wrapping page calls our routes through wp.apiFetch.
     */
    public static function checkAuth(\WP_REST_Request $request): bool
    {
        // get_header() lowercases names, but some servers strip the
        // Authorization header before PHP sees it (Apache + suexec). Fall back
        // to common server vars that mod_rewrite preserves.
        $auth = $request->get_header('authorization')
            ?: ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            ?: ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $secret = get_option('fluxfiles_secret', '');
            if ($secret === '') {
                error_log('FluxFiles: rejecting Bearer token — fluxfiles_secret option is empty');
                return false;
            }
            try {
                JwtMiddleware::handle($m[1], $secret);
                return true;
            } catch (\Throwable $e) {
                error_log('FluxFiles: Bearer rejected — ' . $e->getMessage());
                return false;
            }
        }
        return is_user_logged_in();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Resolve JWT claims for the current request. Prefer the Bearer token sent
     * by the iframe (so request scope / permissions match what was minted at
     * shortcode render time) and fall back to a freshly-minted token tied to
     * the logged-in WP user when no Bearer is present (REST consumers using
     * cookie+nonce).
     */
    private function claims(): \FluxFiles\Claims
    {
        $secret = get_option('fluxfiles_secret', '');
        $auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return JwtMiddleware::handle($m[1], $secret);
        }
        return JwtMiddleware::handle(FluxFilesPlugin::tokenForCurrentUser(), $secret);
    }

    private function fileManager(\FluxFiles\Claims $claims): FileManager
    {
        foreach ($claims->byobDisks as $byobName => $byobConfig) {
            $this->diskManager->registerByobDisk($byobName, $byobConfig);
        }
        $fm = new FileManager($this->diskManager, $claims, $this->metaRepo);
        $fm->setQuotaManager(new QuotaManager($this->diskManager));
        return $fm;
    }

    private function rateLimit(\FluxFiles\Claims $claims, bool $isWrite): void
    {
        $storagePath = FluxFilesPlugin::storagePath();
        $rateLimiter = new RateLimiterFileStorage($storagePath . '/rate_limit.json');
        $rateLimiter->check($claims->userId, $isWrite ? 'write' : 'read');
    }

    private function logAudit(\FluxFiles\Claims $claims, string $action, string $disk, string $key): void
    {
        $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
        $audit->log($claims->userId, $action, $disk, $key);
    }

    /**
     * @param mixed $data
     */
    private function ok($data): \WP_REST_Response
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
                $request->get_param('path') ?? '',
                max(0, (int) ($request->get_param('limit') ?? 0)),
                (string) ($request->get_param('cursor') ?? '')
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

    public function handleRename(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;
            $name = $body['name'] ?? null;

            if (!$disk || !$path || !$name) {
                throw new ApiException('Missing required field: disk, path or name', 400);
            }

            $result = $fm->rename($disk, $path, $name);
            $this->logAudit($claims, 'rename', $disk, $path);

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

    public function handleImportUrl(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $url = (string) ($body['url'] ?? '');
            if ($url === '') {
                throw new ApiException('Missing required field: url', 400, 'missing_param');
            }

            $disk = (string) ($body['disk'] ?? 'local');
            $result = (new UrlImporter($claims, $fm))->import($disk, $url, [
                'path'      => (string) ($body['path'] ?? ''),
                'filename'  => isset($body['filename']) ? (string) $body['filename'] : null,
                'overwrite' => filter_var($body['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
            $this->logAudit($claims, 'url_import', $disk, (string) ($result['key'] ?? ''));

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

    public function handleCrop(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body   = $this->body($request);
            $disk   = $body['disk'] ?? null;
            $path   = $body['path'] ?? null;
            $x      = $body['x'] ?? null;
            $y      = $body['y'] ?? null;
            $width  = $body['width'] ?? null;
            $height = $body['height'] ?? null;

            if (!$disk || !$path || $x === null || $y === null || !$width || !$height) {
                throw new ApiException('Missing required fields: disk, path, x, y, width, height', 400);
            }

            $result = $fm->cropImage(
                $disk,
                $path,
                (int) $x,
                (int) $y,
                (int) $width,
                (int) $height,
                $body['save_path'] ?? null
            );
            $this->logAudit($claims, 'crop', $disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleAiTag(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $aiProvider = get_option('fluxfiles_ai_provider', '');
            if (empty($aiProvider)) {
                throw new ApiException('AI tagging is not configured', 400);
            }

            $aiTagger = new \FluxFiles\AiTagger(
                $aiProvider,
                get_option('fluxfiles_ai_api_key', ''),
                get_option('fluxfiles_ai_model', '') ?: null
            );
            $fm->setAiTagger($aiTagger);

            $body = $this->body($request);
            $disk = $body['disk'] ?? null;
            $path = $body['path'] ?? null;

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields: disk, path', 400);
            }

            $result = $fm->aiTag($disk, $path);
            $this->logAudit($claims, 'ai_tag', $disk, $path);

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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            $this->metaRepo->delete($disk, $key);

            return $this->ok(['deleted' => true]);
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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied: read', 403);
            }

            return $this->ok($this->metaRepo->search(
                $disk,
                $query,
                (int) ($request->get_param('limit') ?? 50),
                $claims->pathPrefix
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handleSearchFolders(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);

            $disk  = $request->get_param('disk') ?? 'local';
            $query = $request->get_param('q');

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
                (int) ($request->get_param('limit') ?? 50),
                $claims->pathPrefix
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Quota

    // Config / code editor — disk/perm/scope/size/binary checks live inside
    // FileManager::getContent / putContent (single source of truth).

    public function handleGetContent(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->getContent(
                $request->get_param('disk') ?? 'local',
                (string) ($request->get_param('path') ?? '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    public function handlePutContent(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $result = $fm->putContent(
                (string) ($body['disk'] ?? 'local'),
                (string) ($body['path'] ?? ''),
                (string) ($body['content'] ?? '')
            );
            $this->logAudit($claims, 'content_edit', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // Extract a zip in place — slip/bomb/quota/dangerous-ext guards live inside
    // FileManager::extractZip.

    public function handleExtract(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $claims = $this->claims();
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $body = $this->body($request);
            $result = $fm->extractZip(
                (string) ($body['disk'] ?? 'local'),
                (string) ($body['path'] ?? ''),
                isset($body['dest']) ? (string) $body['dest'] : null
            );
            $this->logAudit($claims, 'extract', (string) ($body['disk'] ?? 'local'), (string) ($body['path'] ?? ''));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

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

            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);

            return $this->ok($audit->list(
                (int) ($request->get_param('limit') ?? 100),
                (int) ($request->get_param('offset') ?? 0),
                $claims->userId
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

            $scopedPath = $claims->scopePath($path);
            $chunker = new ChunkUploader($this->diskManager);
            $result = $chunker->initiate($disk, $scopedPath);
            $this->logAudit($claims, 'chunk_upload', $disk, $scopedPath);

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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
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
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403);
            }
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403);
            }

            $chunker = new ChunkUploader($this->diskManager);

            return $this->ok($chunker->abort($disk, $key, $uploadId));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode());
        }
    }

    // -------------------------------------------------------------------------
    // UI / asset / locale serving (public — needed by the iframe)
    // -------------------------------------------------------------------------

    public function serveUiHtml(\WP_REST_Request $request)
    {
        $publicDir = FluxFilesPlugin::corePath('public');
        $langDir   = FluxFilesPlugin::corePath('lang');
        if ($publicDir === null || $langDir === null) {
            return $this->error('UI assets not bundled with this plugin install', 500);
        }
        $html = @file_get_contents($publicDir . '/index.html');
        if ($html === false) {
            return $this->error('public/index.html not found', 500);
        }

        $i18n = new \FluxFiles\I18n($langDir, $request->get_param('lang') ?: null);
        $locale = $i18n->locale();
        $dir = $i18n->direction();
        $injection = 'window.__FM_LOCALE__ = { locale: ' . json_encode($locale)
            . ', dir: ' . json_encode($dir)
            . ', messages: ' . $i18n->toJson() . ' };';
        $html = str_replace(
            "window.__FM_LOCALE__ = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };",
            $injection,
            $html
        );
        $html = str_replace(
            '<html lang="en">',
            '<html lang="' . esc_attr($locale) . '" dir="' . esc_attr($dir) . '">',
            $html
        );

        // The HTML uses `../assets/fm.css` and `../assets/fm.js`, which work when
        // served from /public/index.html on a real path but break under WP's
        // ?rest_route=… query form (browser strips the query, ../ goes to /assets/
        // at site root). Rewrite to absolute URLs against our /assets/ route and
        // append a content-hash version so browsers pick up new builds.
        $assetsBase = rest_url('fluxfiles/v1/assets');
        $assetsDir  = FluxFilesPlugin::corePath('assets') ?: '';
        $jsHash  = $assetsDir && is_file("$assetsDir/fm.js")  ? substr(md5_file("$assetsDir/fm.js"),  0, 8) : 'x';
        $cssHash = $assetsDir && is_file("$assetsDir/fm.css") ? substr(md5_file("$assetsDir/fm.css"), 0, 8) : 'x';
        $jsSep  = strpos($assetsBase, '?') !== false ? '&' : '?';
        $html = str_replace('"../assets/fm.css"', '"' . esc_url($assetsBase . '/fm.css') . $jsSep . 'v=' . $cssHash . '"', $html);
        $html = str_replace('"../assets/fm.js"',  '"' . esc_url($assetsBase . '/fm.js')  . $jsSep . 'v=' . $jsHash  . '"', $html);

        // Bypass REST JSON serialization — the browser iframe must receive raw HTML
        // with the right content type, not a JSON-wrapped string.
        $this->sendRaw($html, 'text/html; charset=utf-8', ['Content-Language: ' . $locale]);
    }

    public function serveUiAsset(\WP_REST_Request $request)
    {
        $file = (string) $request->get_param('file');
        // Regex on the route already restricts to fm.js / fm.css.
        $assetsDir = FluxFilesPlugin::corePath('assets');
        if ($assetsDir === null) {
            return $this->error('Assets not bundled', 500);
        }
        $path = $assetsDir . '/' . $file;
        $real = realpath($path);
        if ($real === false || strpos($real, realpath($assetsDir)) !== 0 || !is_file($real)) {
            return $this->error('Asset not found', 404);
        }
        $mime = substr($file, -3) === '.js' ? 'application/javascript' : 'text/css';
        // Cache busts on every file mtime so iframe never serves stale UI/JS after upgrades.
        $etag = '"' . substr(md5_file($real), 0, 16) . '"';
        $this->sendRaw(file_get_contents($real), $mime . '; charset=utf-8', [
            'Cache-Control: public, max-age=300, must-revalidate',
            'ETag: ' . $etag,
        ]);
    }

    /**
     * Send a non-JSON response from inside a REST handler. WP REST normally
     * json_encodes the response body; we need raw HTML / JS / CSS for the
     * iframe and its assets.
     */
    private function sendRaw(string $body, string $contentType, array $extraHeaders = []): void
    {
        status_header(200);
        header('Content-Type: ' . $contentType);
        foreach ($extraHeaders as $h) {
            header($h);
        }
        echo $body;
        exit;
    }

    public function serveLangList(\WP_REST_Request $request)
    {
        $langDir = FluxFilesPlugin::corePath('lang');
        if ($langDir === null) {
            return $this->ok([]);
        }
        $files = glob($langDir . '/*.json') ?: [];
        $result = [];
        foreach ($files as $f) {
            $data = json_decode((string) file_get_contents($f), true);
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

    public function serveLangMessages(\WP_REST_Request $request)
    {
        $locale = (string) $request->get_param('locale');
        $langDir = FluxFilesPlugin::corePath('lang');
        if ($langDir === null) {
            return $this->error('Locales not bundled', 500);
        }
        $path = $langDir . '/' . $locale . '.json';
        $real = realpath($path);
        if ($real === false || strpos($real, realpath($langDir)) !== 0 || !is_file($real)) {
            return $this->error('Locale not found', 404);
        }
        $data = json_decode((string) file_get_contents($real), true);
        if (!is_array($data)) {
            return $this->error('Invalid locale file', 500);
        }
        return $this->ok([
            'locale'   => $data['_meta']['locale'] ?? $locale,
            'dir'      => $data['_meta']['direction'] ?? 'ltr',
            'messages' => $data,
        ]);
    }
}

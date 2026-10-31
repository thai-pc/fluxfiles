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
use FluxFiles\Laravel\LaravelDbMetadataHandler;
use FluxFiles\Laravel\LaravelPdoConnection;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FluxFilesController
{
    private DiskManager $diskManager;
    private MetadataRepositoryInterface $metaRepo;

    public function __construct()
    {
        $storagePath = config('fluxfiles.storage_path');

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $diskConfigs = config('fluxfiles.disks');
        $this->diskManager = new DiskManager($diskConfigs);
        // Key for the tamper-evident audit chain (lives in config, not the env).
        \FluxFiles\AuditChain::configure((string) config('fluxfiles.secret'));
        $this->metaRepo = config('fluxfiles.storage_backend') === 'db'
            ? new LaravelDbMetadataHandler($this->diskManager, config('fluxfiles.db_connection'))
            : new StorageMetadataHandler($this->diskManager);
    }

    /**
     * Resolve JWT claims from the request.
     */
    private function claims(Request $request): \FluxFiles\Claims
    {
        // Reset FIRST: a controller instance can outlive one request (Octane), and a
        // 403 thrown below (token_not_access) must not be audited under the PREVIOUS
        // request's identity.
        $this->lastClaims = null;
        $this->lastRequest = $request;
        $secret = config('fluxfiles.secret');
        $token = $request->bearerToken();

        if (!$token) {
            throw new ApiException('Missing authorization token', 401);
        }

        // Remembered so error() can audit a refused (403) request — see auditDenied().
        $claims = $this->lastClaims = JwtMiddleware::handle($token, $secret);

        // Here rather than in fileManager(): search, quota and usage never build one.
        foreach ($claims->byobDisks as $byobName => $byobConfig) {
            $this->diskManager->registerByobDisk($byobName, $byobConfig);
        }

        return $claims;
    }

    /** Claims of the current request once authenticated (null before / on 401). */
    private ?\FluxFiles\Claims $lastClaims = null;

    /** The request those claims came from (what the audit helpers read the body from). */
    private ?Request $lastRequest = null;

    /**
     * Build a FileManager instance for the current request.
     *
     * Every hook is wired by the shared core factory (the same one index.php uses),
     * so virus/DLP stay fail-closed — the module gate runs inside the callbacks —
     * and a hook added to core later reaches this adapter without a change here.
     */
    private function fileManager(\FluxFiles\Claims $claims): FileManager
    {
        // The AI tagger is wired only for `ai_auto_tag` (and only when a provider is
        // configured) — unlike core, which wires it whenever a provider is set.
        $aiTagger = $claims->aiAutoTag ? $this->aiTagger() : null;

        $fm = new FileManager($this->diskManager, $claims, $this->metaRepo);
        // Minted /stream and /img links must follow a custom route_prefix.
        $fm->setApiBasePath('/' . trim((string) config('fluxfiles.route_prefix', 'api/fm'), '/'));

        return \FluxFiles\FileManagerFactory::wire(
            $fm,
            $claims,
            $this->diskManager,
            $this->metaRepo,
            (string) config('fluxfiles.secret'),
            $aiTagger
        );
    }

    /** The configured AI tagger, or null when no provider is set. */
    private function aiTagger(): ?\FluxFiles\AiTagger
    {
        $aiProvider = config('fluxfiles.ai_provider', env('FLUXFILES_AI_PROVIDER', ''));
        if (empty($aiProvider)) {
            return null;
        }
        return new \FluxFiles\AiTagger(
            $aiProvider,
            config('fluxfiles.ai_api_key', env('FLUXFILES_AI_API_KEY', '')),
            config('fluxfiles.ai_model', env('FLUXFILES_AI_MODEL')) ?: null,
            config('fluxfiles.ai_base_url', env('FLUXFILES_AI_BASE_URL')) ?: null
        );
    }

    /**
     * Run a rate-limit check against the backend the adapter is configured for:
     * the DB (`fluxfiles_rate_limits` table, connection-prefixed, over Laravel's own connection) in
     * `storage_backend = db` mode, like core, else the JSON files under storage_path.
     *
     * A DB limiter that cannot run (typically the `fluxfiles_rate_limits` migration has not
     * been run after an upgrade) must not take every API call down with a 500:
     * it falls back to the file limiter for that request (logging at most once a
     * minute per process). A genuine 429
     * is always rethrown.
     */
    private function checkRate(string $identifier, string $bucket, int $readLimit, int $writeLimit): void
    {
        $storagePath = (string) config('fluxfiles.storage_path');
        if (config('fluxfiles.storage_backend') === 'db') {
            try {
                $connName = config('fluxfiles.db_connection');
                \FluxFiles\RateLimiterFactory::makeDb(
                    new LaravelPdoConnection($connName),
                    $readLimit,
                    $writeLimit,
                    60,
                    \Illuminate\Support\Facades\DB::connection($connName)->getTablePrefix() . 'fluxfiles_rate_limits'
                )->check($identifier, $bucket);
                return;
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                static $lastLogged = 0;
                if (time() - $lastLogged >= 60) {
                    $lastLogged = time();
                    error_log('FluxFiles: DB rate limiter unavailable, using the file limiter (run `php artisan migrate`): ' . $e->getMessage());
                }
            }
        }
        \FluxFiles\RateLimiterFactory::makeFile($storagePath, $readLimit, $writeLimit)->check($identifier, $bucket);
    }

    /**
     * Apply rate limiting for the current request.
     */
    private function rateLimit(\FluxFiles\Claims $claims, bool $isWrite): void
    {
        // Per-tenant `rate_read`/`rate_write` claims override the server defaults.
        $readLimit  = $claims->rateRead > 0 ? $claims->rateRead : (int) config('fluxfiles.rate_limit_read', 60);
        $writeLimit = $claims->rateWrite > 0 ? $claims->rateWrite : (int) config('fluxfiles.rate_limit_write', 10);
        $this->checkRate($claims->userId, $isWrite ? 'write' : 'read', $readLimit, $writeLimit);
    }

    /**
     * A dedicated per-user bucket on top of the generic read/write one, for the
     * routes core gives their own tighter limit: `import` (an outbound fetch per
     * call) and `usage_refresh` (a full listing of the prefix per call —
     * /duplicates, and /usage, which this proxy always recomputes). Keyed
     * `<sub>:<bucket>`. $limit <= 0 disables it.
     */
    private function rateLimitBucket(\FluxFiles\Claims $claims, string $bucket, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }
        $this->checkRate($claims->userId, $bucket, $limit, $limit);
    }

    /**
     * The shared core class behind /stream and /img. Its limiter honours the
     * adapter's `rate_limit_stream` / `rate_limit_img` config (which default to
     * FLUXFILES_STREAM_RATE_LIMIT=300 / FLUXFILES_IMG_RATE_LIMIT=120, core's
     * defaults) and the same DB-or-file backend as every other bucket; a limiter
     * that errors fails open, only a genuine 429 blocks (MediaEndpoint's contract).
     */
    private function mediaEndpoint(): \FluxFiles\MediaEndpoint
    {
        $limiter = function (string $sub, string $bucket, int $default): bool {
            $limit = (int) config('fluxfiles.rate_limit_' . $bucket, $default);
            if ($limit <= 0) {
                return true; // explicitly disabled by the operator
            }
            try {
                $this->checkRate($sub !== '' ? $sub : 'anonymous', $bucket, $limit, $limit);
                return true;
            } catch (ApiException $e) {
                return $e->getHttpCode() !== 429;
            } catch (\Throwable $e) {
                return true;
            }
        };

        return new \FluxFiles\MediaEndpoint(
            $this->diskManager,
            (string) config('fluxfiles.secret'),
            $limiter,
            // FLUXFILES_XACCEL: $_ENV survives `config:cache` (seeded from server_env).
            (string) ($_ENV['FLUXFILES_XACCEL'] ?? env('FLUXFILES_XACCEL', '')),
            $this->metaRepo
        );
    }

    /** The audit log for this request's token. */
    private function auditLog(\FluxFiles\Claims $claims): AuditLogStorage
    {
        return new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
    }

    /**
     * Log a free-form audit entry for routes core also logs by hand (zip_download,
     * disk_doctor, per-item retention/fixity lines). $key must already be scoped.
     * A log failure never fails the request.
     */
    private function logAuditQuiet(\FluxFiles\Claims $claims, string $action, string $disk, string $key, ?string $detail = null): void
    {
        try {
            $this->auditLog($claims)->log($claims->userId, $action, $disk, $key, null, null, $detail);
        } catch (\Throwable $e) {
        }
    }

    /**
     * The route as core sees it (`/api/fm/<route>`), whatever `fluxfiles.route_prefix`
     * is, so AuditPolicy's route table matches. The raw path is not used: a custom
     * prefix containing e.g. "upload" would otherwise resolve to the wrong action.
     */
    private function coreUri(Request $request): string
    {
        $path = '/' . trim($request->path(), '/');
        $prefix = '/' . trim((string) config('fluxfiles.route_prefix', 'api/fm'), '/');
        $rel = strncmp($path, $prefix, strlen($prefix)) === 0 ? substr($path, strlen($prefix)) : $path;

        return '/api/fm/' . ltrim($rel, '/');
    }

    /**
     * The central "log write actions" step of core's index.php: every successful
     * POST/PUT/DELETE that returned data is audited through AuditPolicy (the one
     * route -> action table, key scoping and detail rules) and, when the token has
     * a webhook, dispatched with the same event. Best-effort: nothing here may fail
     * a request whose write already happened.
     *
     * @param mixed $data the operation result
     */
    private function recordWrite(Request $request, \FluxFiles\Claims $claims, $data): void
    {
        if ($data === null || !in_array($request->method(), ['POST', 'PUT', 'DELETE'], true)) {
            return;
        }
        try {
            $audit = \FluxFiles\AuditPolicy::successEntry(
                $this->coreUri($request),
                \FluxFiles\AuditPolicy::decodeBody((string) $request->getContent()),
                $request->post(),
                $claims,
                $data
            );
            $this->auditLog($claims)->log($claims->userId, $audit['action'], $audit['disk'], $audit['key'], null, null, $audit['detail']);

            $dispatch = \FluxFiles\FileManagerFactory::webhookDispatcher($claims, (string) config('fluxfiles.secret'));
            if ($dispatch !== null) {
                $dispatch($audit['action'], [
                    'disk' => $audit['disk'],
                    'path' => $audit['key'],
                    'name' => is_array($data) ? (string) ($data['name'] ?? basename($audit['key'])) : '',
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Record the audit lines for a refused request: a blocked write
     * (`virus_blocked` / `dlp_blocked` / `legal_hold_blocked`) and any other
     * authenticated 403 as `access_denied`. Both live in core's AuditPolicy.
     */
    private function recordRefusal(Request $request, \FluxFiles\Claims $claims, ApiException $e): void
    {
        $refusedWrite = in_array($e->getErrorCode(), ['virus_detected', 'pii_detected', 'legal_hold_active'], true);
        if (!$refusedWrite && $e->getHttpCode() !== 403) {
            return;
        }
        try {
            $body = \FluxFiles\AuditPolicy::decodeBody((string) $request->getContent());
            $auditLog = $this->auditLog($claims);
            \FluxFiles\AuditPolicy::recordRefusedWrite($auditLog, $claims, $e, $body, $request->post());
            \FluxFiles\AuditPolicy::recordDenied(
                $auditLog,
                $claims,
                $e,
                $request->method(),
                $this->coreUri($request),
                $body,
                $request->post(),
                $request->query()
            );
        } catch (\Throwable $ignored) {
            // Auditing must never turn a clean 4xx into a 500.
        }
    }

    /**
     * Wrap a successful response. A successful write is audited (and its webhook
     * fired) here, once, for every route — see recordWrite().
     *
     * @param mixed $data
     */
    private function ok($data): JsonResponse
    {
        if ($this->lastClaims !== null && $this->lastRequest !== null) {
            $this->recordWrite($this->lastRequest, $this->lastClaims, $data);
        }
        return response()->json(['data' => $data, 'error' => null]);
    }

    /**
     * Wrap an error response.
     */
    private function error(string $message, int $status = 400, ?string $code = null, array $params = []): JsonResponse
    {
        if ($this->lastClaims !== null && $this->lastRequest !== null) {
            $this->recordRefusal($this->lastRequest, $this->lastClaims, new ApiException($message, $status, $code, $params));
        }
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

            // Scalars only: `?sort[]=x` must be a 400 from core, not an
            // "Array to string conversion" error.
            $q = static fn (string $k, string $d = ''): string => is_scalar($v = $request->query($k)) ? (string) $v : $d;
            return $this->ok($fm->list(
                $request->query('disk', 'local'),
                $request->query('path', ''),
                max(0, (int) $q('limit', '0')),
                $q('cursor'),
                // Server-side sort/filter.
                $q('sort'),
                $q('order', 'asc'),
                $q('type', 'all')
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
                // FILTER_VALIDATE_BOOLEAN, not a (bool) cast: a multipart field is a
                // string, and (bool) "false" is TRUE (core parses it the same way).
                filter_var($request->input('force_upload', false), FILTER_VALIDATE_BOOLEAN)
            );

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
            // URL import is an outbound HTTP request from this server — its own,
            // tighter bucket (the token's `import_rate_limit`, else the configured
            // default), and the write perm is checked BEFORE anything is fetched.
            // Mirrors core's index.php.
            $this->rateLimitBucket(
                $claims,
                'import',
                $claims->importRateLimit > 0 ? (int) $claims->importRateLimit : (int) config('fluxfiles.rate_limit_import', 10)
            );
            $claims->requirePerm('write');
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


            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function watermark(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = (string) ($request->input('disk') ?? 'local');
            $path = (string) ($request->input('path') ?? '');
            if ($path === '') {
                throw new ApiException('Missing path', 400);
            }
            $wm = [
                'type'      => $request->input('type') === 'text' ? 'text' : 'logo',
                'text'      => (string) ($request->input('text') ?? ''),
                'x'         => (float) ($request->input('x') ?? 0.7),
                'y'         => (float) ($request->input('y') ?? 0.85),
                'scale'     => (float) ($request->input('scale') ?? 0.25),
                'opacity'   => (float) ($request->input('opacity') ?? 0.6),
                'font_size' => (int) ($request->input('font_size') ?? 24),
                'color'     => (string) ($request->input('color') ?? '#ffffff'),
            ];
            if ($request->input('logo_data')) {
                $b64 = preg_replace('#^data:[^,]+,#', '', (string) $request->input('logo_data'));
                $bin = base64_decode((string) $b64, true);
                if ($bin === false || $bin === '') {
                    throw new ApiException('Invalid logo data', 400);
                }
                $wm['logo_data'] = $bin;
            }
            $dest = $request->input('dest');
            $result = $fm->applyWatermark($disk, $path, $wm, $dest !== '' ? $dest : null);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function watermarkRemove(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk = (string) ($request->input('disk') ?? 'local');
            $path = (string) ($request->input('path') ?? '');
            if ($path === '') {
                throw new ApiException('Missing path', 400);
            }
            $result = $fm->removeWatermark($disk, $path);

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
            $aiTagger = $this->aiTagger();
            if ($aiTagger === null) {
                throw new ApiException('AI tagging is not configured', 400);
            }
            $fm->setAiTagger($aiTagger);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields: disk, path', 400);
            }

            $result = $fm->aiTag($disk, $path);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function presign(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $method = $request->input('method');
            // An upload URL (PUT/POST) is a write — core counts it against the write
            // bucket; a download presign stays a read.
            $this->rateLimit($claims, in_array(strtoupper((string) $method), ['PUT', 'POST'], true));
            $fm = $this->fileManager($claims);

            $disk   = $request->input('disk');
            $path   = $request->input('path');
            $ttl    = $request->input('ttl');

            if (!$disk || !$path || !$method || !$ttl) {
                throw new ApiException('Missing required fields', 400);
            }

            // A `method:"PUT"` presign mints a URL the browser PUTs straight to
            // S3/R2, so those bytes never reach this server and cannot be scanned
            // either — the same unscannable side door the chunk routes refuse
            // below, so it gets the same fail-closed treatment and the same codes.
            // POST (form upload straight to the bucket) is the same unscannable door.
            if (in_array(strtoupper((string) $method), ['PUT', 'POST'], true)) {
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

            $presignResult = $fm->presign(
                $disk,
                $path,
                strtoupper((string) $method),
                (int) $ttl,
                (int) ($request->input('size') ?? $request->input('size_bytes') ?? 0)
            );
            // A GET presign hands out a download URL, a PUT one an upload URL.
            return $this->ok($presignResult);
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
            $claims->requireDisk($disk);
            $claims->requirePerm('read');
            // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
            // internal detail), so a prefixed tenant used to get 403 path_denied on every
            // metadata read/save/delete. scopePath() prefixes it — idempotent for an
            // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
            $key = $claims->scopePath((string) $key);
            $key = $fm->validateScopedPath($key);

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
            $claims->requireDisk($disk);
            $claims->requirePerm('write');
            // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
            // internal detail), so a prefixed tenant used to get 403 path_denied on every
            // metadata read/save/delete. scopePath() prefixes it — idempotent for an
            // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
            $key = $claims->scopePath((string) $key);
            $key = $fm->assertCanModifyScopedPath($disk, $key);

            $data = [
                'title'    => $request->input('title'),
                'alt_text' => $request->input('alt_text'),
                'caption'  => $request->input('caption'),
                'tags'     => $request->input('tags'),
            ];

            $this->metaRepo->save($disk, $key, $data);
            $this->metaRepo->syncToS3Tags($disk, $key, $data, $this->diskManager);

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
            $claims->requireDisk($disk);
            $claims->requirePerm('write');
            // The UI sends the RELATIVE key list() handed it (the tenant prefix is an
            // internal detail), so a prefixed tenant used to get 403 path_denied on every
            // metadata read/save/delete. scopePath() prefixes it — idempotent for an
            // already-scoped key — and refuses a sibling tenant's path (403 path_denied).
            $key = $claims->scopePath((string) $key);
            $key = $fm->assertCanModifyScopedPath($disk, $key);

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
            $claims->requireDisk($disk);
            $claims->requirePerm('read');

            $rows = $this->metaRepo->search(
                $disk,
                $query,
                (int) $request->query('limit', 50),
                $claims->pathPrefix,
                $claims->showHidden
            );
            // Strip the tenant prefix so keys are relative to the client root, like
            // list() — the prefix is an internal detail (core does the same).
            foreach ($rows as &$row) {
                if (isset($row['file_key'])) {
                    $row['file_key'] = $claims->unscopePath((string) $row['file_key']);
                }
            }
            unset($row);

            return $this->ok($rows);
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
            $claims->requireDisk($disk);
            $claims->requirePerm('read');

            $rows = $this->metaRepo->searchFolders(
                $disk,
                $query,
                (int) $request->query('limit', 50),
                $claims->pathPrefix,
                $claims->showHidden
            );
            foreach ($rows as &$row) {
                if (isset($row['dir_key'])) {
                    $row['dir_key'] = $claims->unscopePath((string) $row['dir_key']);
                }
            }
            unset($row);

            return $this->ok($rows);
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

            $disk = (string) $request->query('disk', 'local');
            // QuotaManager has no disk ACL of its own and DiskManager builds any
            // configured disk regardless of claims, so the allowlist is enforced
            // here — same gate order as search() above.
            $claims->requireDisk($disk);
            $claims->requirePerm('read');
            $quotaManager = new QuotaManager($this->diskManager);

            return $this->ok($quotaManager->getQuotaInfo(
                $disk,
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
    /**
     * Duplicate finder (core's GET /api/fm/duplicates). A full listing per call,
     * so it also takes core's tight `usage_refresh` bucket (default 2/min).
     */
    public function duplicates(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $this->rateLimitBucket($claims, 'usage_refresh', (int) config('fluxfiles.rate_limit_usage', 2));
            $fm = $this->fileManager($claims);
            return $this->ok($fm->findDuplicates(
                (string) $request->query('disk', 'local'),
                max(1, min(200, (int) $request->query('limit', 50)))
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function usage(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            // No cache layer here: every call is what core calls a forced refresh
            // (a full listing of the prefix), so it always takes core's tight
            // `usage_refresh` bucket rather than the 60/min read budget.
            $this->rateLimitBucket($claims, 'usage_refresh', (int) config('fluxfiles.rate_limit_usage', 2));

            $disk = (string) $request->query('disk', 'local');
            // QuotaManager has no disk ACL of its own and DiskManager builds any
            // configured disk regardless of claims, so the allowlist is enforced
            // here — same gate order as search() above.
            $claims->requireDisk($disk);
            $claims->requirePerm('read');
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
     * Mint a FRESH JWT for the session-authenticated user.
     *
     * This route is deliberately NOT behind the FluxFilesAuth (JWT) middleware:
     * the whole point is that the iframe's JWT has expired, so the embedded UI
     * (via the SDK `onTokenRefresh` hook) calls this to obtain a new one WITHOUT
     * a full page reload. Auth here is the Laravel session ('web' + 'auth'); if
     * the SESSION is also gone, this 401s and the UI falls back to a reload
     * (which sends the user through login).
     *
     * What it mints — never the bare config defaults, which used to hand every
     * logged-in user an unscoped read/write/delete token here:
     *  - with an `X-FluxFiles-Refresh-Ticket` header (the <x-fluxfiles> default
     *    hook sends one): the exact overrides the page rendered with, on top of
     *    the claims resolver — so a refresh never widens the scope;
     *  - without one: the claims resolver's policy, and only if one is configured
     *    (else 403 token_refresh_unconfigured).
     * Cookie-only, so it gets the same Origin check as FluxFilesAuth's fallback.
     */
    public function token(Request $request): JsonResponse
    {
        $this->lastClaims = null; // no JWT here — never audit a 403 under a previous request's identity
        try {
            $user = $request->user();
            if (!$user) {
                throw new ApiException('Unauthenticated', 401, 'token_refresh_failed');
            }
            if (!\FluxFiles\Laravel\Http\Middleware\FluxFilesAuth::originAllowed($request)) {
                throw new ApiException('Origin not allowed', 403, 'origin_denied');
            }
            $manager = app(\FluxFiles\Laravel\FluxFilesManager::class);
            $ticket = (string) $request->header('X-FluxFiles-Refresh-Ticket', '');
            if ($ticket !== '') {
                try {
                    $overrides = $manager->verifyRefreshTicket($user, $ticket);
                } catch (\RuntimeException $e) {
                    throw new ApiException('Invalid refresh ticket', 403, 'token_refresh_failed');
                }
                $token = $manager->tokenForResolvedUser($user, $overrides);
            } elseif ($manager->hasClaimsResolver()) {
                $token = $manager->tokenForResolvedUser($user);
            } else {
                throw new ApiException(
                    'Token refresh needs a refresh ticket or a configured fluxfiles.claims_resolver',
                    403,
                    'token_refresh_unconfigured'
                );
            }
            $resp = $this->ok(['token' => $token]);
        } catch (ApiException $e) {
            $resp = $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode());
        } catch (\Throwable $e) {
            $resp = $this->error('Unable to refresh token', 401, 'token_refresh_failed');
        }
        // A bearer credential in a response body must never sit in a shared or
        // browser cache.
        return $resp->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    /**
     * Optimization (FREE/core) — mirrors index.php's /api/fm/optimize route. Opt-in
     * per token via `allow_optimize` (it replaces/deletes originals, so it's a
     * deliberate capability, not on by default); the module itself enforces write.
     * NOT a ModuleRegistry-gated paid module (that map entry was removed when
     * Optimize moved to free/core) — a direct claim check, same as the on-upload
     * auto-optimize hook above.
     */
    public function optimize(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            if (!$claims->allowOptimize) {
                throw new ApiException('This token may not use optimization', 403, 'optimize_forbidden');
            }
            $module = new \FluxFiles\OptimizeModule();
            $result = $module->run($fm, $this->diskManager, new \FluxFiles\ImageOptimizer(), $claims, $request->all());

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

            $content = $fm->getContent(
                $request->query('disk', 'local'),
                (string) $request->query('path', '')
            );
            // Opt-in read audit (`audit_reads`).
            if ($claims->auditReads) {
                $this->logAuditQuiet($claims, 'content_read', (string) $request->query('disk', 'local'), $claims->scopePath((string) $request->query('path', '')));
            }
            return $this->ok($content);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * The file text exactly as sent. A Laravel app's global TrimStrings middleware
     * rewrites input() and json() alike, which would strip the first line's indent and
     * the trailing newline from every saved file, so read the raw body.
     */
    private function rawContent(Request $request): string
    {
        $body = json_decode($request->getContent(), true);
        if (is_array($body) && is_string($body['content'] ?? null)) {
            return $body['content'];
        }
        return (string) $request->input('content', '');
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
                $this->rawContent($request)
            );

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

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Stream a multi-file/folder selection as a zip. Every gate (allow_zip,
     * allow_download, read perm, disk scope, the file-count/byte caps, owner_only)
     * lives in FileManager::zipManifest(), so this is a passthrough like the rest.
     *
     * The manifest is resolved BEFORE the stream callback runs, so a rejection is
     * still a clean JSON error — once response()->stream() starts flushing, the
     * status code is already sent and there is no way back.
     *
     * ZipStream sends its own headers from inside the callback, exactly as it does
     * in core's index.php. Symfony's Response::send() runs sendHeaders() first, but
     * that only stages them with header() — PHP does not flush until the first byte
     * of body, which is emitted by sendContent() afterwards. So ZipStream's own
     * Content-Type/Content-Disposition still replace Symfony's defaults. The
     * laravel.spec.ts zip test asserts both headers on the wire to keep it that way.
     *
     * Audited as `zip_download` before the stream starts, matching core.
     */
    public function zip(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $disk  = (string) $request->input('disk', 'local');
            $paths = $request->input('paths');
            $paths = is_array($paths) ? $paths : [];
            $name  = $request->input('name') !== null ? (string) $request->input('name') : null;

            $manifest = $fm->zipManifest($disk, $paths);
            // Bulk download = the classic exfiltration shape. Logged once every guard
            // has passed and before the stream starts (core does the same).
            $names = array_map(static fn ($e) => (string) $e['key'], $manifest['entries'] ?? []);
            $this->logAuditQuiet(
                $claims,
                'zip_download',
                $disk,
                $claims->scopePath((string) ($paths[0] ?? '')),
                mb_substr((int) ($manifest['count'] ?? 0) . ' files, ' . (int) ($manifest['total'] ?? 0) . ' bytes: '
                    . implode(', ', array_slice($names, 0, 20)) . (count($names) > 20 ? ', …' : ''), 0, 1000)
            );

            return response()->stream(function () use ($fm, $disk, $paths, $name) {
                $fm->streamZip($disk, $paths, $name);
            });
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Read the Unix mode of one file on an SFTP disk. SFTP is reachable in proxy
     * mode via a BYOB sftp disk in the token (registered in claims()), which
     * is how chmod is reachable here.
     */
    public function getChmod(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            $fm = $this->fileManager($claims);

            return $this->ok($fm->getMode(
                (string) $request->query('disk', ''),
                (string) $request->query('path', '')
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Set the Unix mode of one file on an SFTP disk. The allow_chmod claim is
     * checked here to match core's index.php (FileManager::setMode() enforces the
     * write perm, path scope and owner_only, but not this claim).
     */
    public function setChmod(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            if (!$claims->allowChmod) {
                throw new ApiException('Changing permissions is not allowed', 403, 'chmod_forbidden');
            }
            $fm = $this->fileManager($claims);

            $disk = (string) $request->input('disk', '');
            $path = (string) $request->input('path', '');
            $result = $fm->setMode($disk, $path, (string) $request->input('mode', ''));

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
            $disk = (string) $request->query('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $origin = $request->header('Origin') ?: $request->query('origin');

            $report = (new BucketDoctor($this->diskManager))->diagnose($disk, $origin ?: null);
            // Host-app checks (no network, no absolute paths): is this disk's
            // _fluxfiles/ inside the web root, and has the published config drifted.
            $report['checks'] = array_merge(
                $report['checks'] ?? [],
                \FluxFiles\Laravel\Doctor::exposureChecks(
                    (array) config('fluxfiles.disks', []),
                    function_exists('public_path') ? public_path() : null,
                    $disk
                ),
                \FluxFiles\Laravel\Doctor::driftChecks(
                    (array) config('fluxfiles'),
                    (array) require __DIR__ . '/../../../config/fluxfiles.php'
                )
            );
            $report['summary'] = \FluxFiles\Laravel\Doctor::summarize($report['checks']);
            // It writes and deletes a probe object — logged like any other write.
            $this->logAuditQuiet($claims, 'disk_doctor', $disk, '');

            return $this->ok($report);
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

            // Same gate as core's /api/fm/audit: reading the activity log needs an
            // explicit 'audit' permission (off by default), so an ordinary read
            // token cannot see who did what.
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);

            // $claims is what makes list() scope entries to the token's path
            // prefix — audit.jsonl is per-disk, not per-tenant, so without it
            // every tenant on a shared disk reads the whole log.
            return $this->ok($audit->list(
                (int) $request->query('limit', 100),
                (int) $request->query('offset', 0),
                ($request->query('actor') ?: null),
                $claims,
                [
                    'action' => $request->query('action'),
                    'from'   => $request->query('from'),
                    'to'     => $request->query('to'),
                    'path'   => $request->query('path'),
                ]
            ));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Compliance Readiness Scorecard (FREE/core, docs/design/COMPLIANCE-SCORECARD-DESIGN.md)
     * — a read-only capability checklist (virus scan / C2PA / audit export / SSO /
     * DLP / legal hold). Gated by the same `audit` perm as the activity log, not a
     * module/license check — every paid row simply reads `available: false` on an
     * unlicensed server. Mirrors index.php's `/api/fm/compliance/scorecard` route.
     */
    public function complianceScorecard(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            return $this->ok(\FluxFiles\ComplianceScorecard::build($claims, \FluxFiles\LicenseManager::fromEnv()));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Audit export (paid module) — a full, unpaginated download of the tenant's
     * audit history (live + archived), as NDJSON or CSV. Bypasses the ok()/error()
     * JSON envelope: the module streams its own headers + body directly, same
     * posture as core's own /api/fm/zip streaming route.
     */
    /**
     * Audit chain verification (paid audit-export module) — core's
     * GET /api/fm/audit/verify. Admin-only: the log is per-disk, not per-tenant.
     */
    /** Retention rules (legal-hold module) — core's POST /api/fm/retention/run. */
    public function retentionRun(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            $disk = (string) $request->input('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $fm = $this->fileManager($claims);
            \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $dryRun = filter_var($request->input('dry_run', true), FILTER_VALIDATE_BOOLEAN);
            $report = $fm->runRetention($disk, $dryRun);
            $this->auditRetention($claims, $disk, $report);
            return $this->ok($report);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * One audit line per file acted on (core does the same); the run itself
     * (`retention_run`) is recorded by ok() through AuditPolicy.
     */
    private function auditRetention(\FluxFiles\Claims $claims, string $disk, array $report): void
    {
        foreach ($report['items'] as $it) {
            if (in_array($it['result'], ['trashed', 'deleted'], true)) {
                $this->logAuditQuiet($claims, 'retention_' . $it['action'], $disk, $claims->scopePath($it['path']), 'age_days=' . $it['age_days']);
            }
        }
    }

    /** Fixity / integrity check (audit-export module) — core's POST /api/fm/fixity/check. */
    public function fixityCheck(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            $disk = (string) $request->input('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $fm = $this->fileManager($claims);
            \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
            // Only the audit rows since the oldest baseline, narrowed in SQL on the db backend.
            $report = $fm->runFixityCheck($disk, fn (?int $since): array =>
                $since !== null && method_exists($this->metaRepo, 'readAuditWindow')
                    ? $this->metaRepo->readAuditWindow($disk, $since)
                    : $this->metaRepo->readAudit($disk));
            foreach (['changed_unexplained' => 'changed', 'missing_unexplained' => 'missing'] as $list => $what) {
                foreach ($report[$list] as $key) {
                    $this->logAuditQuiet($claims, 'fixity_mismatch', $disk, $claims->scopePath((string) $key), $what);
                }
            }
            return $this->ok($report);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function auditVerify(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            if (trim($claims->pathPrefix, '/') !== '') {
                throw new ApiException('Audit verification requires an unscoped (admin) token', 403, 'forbidden');
            }
            $disk = (string) $request->query('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
            return $this->ok(\FluxFiles\AuditChain::verify(array_merge(
                $this->metaRepo->readAuditArchive($disk),
                $this->metaRepo->readAudit($disk)
            )));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function auditExport(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $module = \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);

            return response()->stream(function () use ($module, $audit, $claims, $request) {
                $module->export($audit, $claims, [
                    'action' => $request->query('action'),
                    'from'   => $request->query('from'),
                    'to'     => $request->query('to'),
                    'path'   => $request->query('path'),
                    'actor'  => $request->query('actor'),
                ], (string) $request->query('format', 'ndjson'));
            });
        } catch (ApiException $e) {
            // The standard envelope (`error_code`/`error_params`, like every other
            // route and core): the UI localises on `error_code`, and error() also
            // audits a 403 as access_denied. This used to send `code`/`params`.
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Audit purge (paid module) — destructive, so admin-only: the audit log is
     * stored per-DISK, not per-tenant, so a path-scoped token could otherwise
     * purge lines belonging to other tenants sharing the same disk.
     */
    public function auditPurge(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }
            if (trim($claims->pathPrefix, '/') !== '') {
                throw new ApiException('Audit purge requires an unscoped (admin) token', 403, 'forbidden');
            }

            $disk = (string) $request->input('disk', 'local');
            // pathPrefix and allowedDisks are independent claims — an unscoped
            // token can still be limited to specific disks, so the tenant-prefix
            // check above does NOT imply disk access.
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }

            $before = $request->filled('before')
                ? (int) $request->input('before')
                : ($claims->auditRetentionDays > 0 ? time() - ($claims->auditRetentionDays * 86400) : 0);
            if ($before <= 0) {
                throw new ApiException('An explicit `before` cutoff (or a token audit_retention_days) is required', 400, 'audit_purge_no_cutoff');
            }

            $module = \FluxFiles\ModuleRegistry::require('audit-export', \FluxFiles\LicenseManager::fromEnv(), $claims);

            $result = $module->purge($this->metaRepo, $claims, $disk, $before);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Legal hold — STATUS is free/core (visibility, no license/claim check), same
     * posture as index.php's GET /api/fm/hold/status. Ported directly since it's
     * not reusable app code — same pattern as /stream and /img.
     */
    public function holdStatus(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('read')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $disk = (string) $request->query('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $path = (string) $request->query('path', '');
            $hold = $this->metaRepo->holdCovering($disk, $claims->scopePath($path));
            if ($hold === null) {
                return $this->ok(['on_hold' => false]);
            }
            $out = ['on_hold' => true, 'hold_id' => $hold['hold_id'] ?? null];
            if ($claims->hasPerm('audit')) {
                $out['reason'] = $hold['reason'] ?? null;
                $out['placed_by'] = $hold['placed_by'] ?? null;
                $out['placed_at'] = $hold['placed_at'] ?? null;
            }
            return $this->ok($out);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Legal hold — PLACE/RELEASE/LIST are the paid-gated management half (see
     * docs/design/RETENTION-LEGAL-HOLD-DESIGN.md §2). Enforcement itself is free/core
     * and license-independent, wired unconditionally into
     * FileManager::assertNoActiveHold() (inherited from core, no proxy change needed).
     */
    public function hold(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $disk = (string) $request->input('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!$request->filled('path')) {
                throw new ApiException('Missing required field: path', 400, 'missing_param');
            }

            /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
            $module = \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);

            $path = (string) $request->input('path');
            $reason = (string) $request->input('reason', '');
            $result = $module->place($this->metaRepo, $this->diskManager, $claims, $disk, $path, $reason);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function holdRelease(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $disk = (string) $request->input('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            if (!$request->filled('hold_id')) {
                throw new ApiException('Missing required field: hold_id', 400, 'missing_param');
            }

            /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
            $module = \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);

            $holdId = (string) $request->input('hold_id');
            $reason = (string) $request->input('reason', '');
            $result = $module->release($this->metaRepo, $claims, $disk, $holdId, $reason);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function holdList(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);
            if (!$claims->hasPerm('audit')) {
                throw new ApiException('Permission denied', 403, 'forbidden');
            }

            $disk = (string) $request->query('disk', 'local');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException('Disk not allowed', 403, 'disk_not_allowed');
            }
            $includeReleased = filter_var($request->query('include_released', false), FILTER_VALIDATE_BOOLEAN);

            /** @var \FluxFiles\LegalHold\LegalHoldModule $module */
            $module = \FluxFiles\ModuleRegistry::require('legal-hold', \FluxFiles\LicenseManager::fromEnv(), $claims);

            return $this->ok($module->list($this->metaRepo, $claims, $disk, $includeReleased));
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Webhooks (paid module) — send a test ping to the configured endpoint so
     * operators can verify it. Same 3-layer gate as ModuleRegistry::require().
     */
    public function webhooksTest(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            $module = \FluxFiles\ModuleRegistry::require('webhooks', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->test($claims, (string) config('fluxfiles.secret'));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * File versioning (paid module) — list prior versions of a file / restore one.
     * Same 3-layer gate as ModuleRegistry::require(). The version-keeping/purging
     * hooks that make listVersions()/restore() meaningful are wired in
     * fileManager() above, not here.
     */
    public function versions(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, false);

            $module = \FluxFiles\ModuleRegistry::require('versioning', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $fm = $this->fileManager($claims);
            $result = $module->listVersions(
                $fm,
                $this->diskManager,
                $claims,
                (string) $request->query('disk', 'local'),
                (string) $request->query('path', ''),
                $this->metaRepo
            );

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function versionsRestore(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);

            $module = \FluxFiles\ModuleRegistry::require('versioning', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $fm = $this->fileManager($claims);
            $disk = (string) $request->input('disk', 'local');
            $path = (string) $request->input('path', '');
            $versionId = (string) $request->input('version_id', '');
            $result = $module->restore($fm, $this->diskManager, $claims, $disk, $path, $versionId, $this->metaRepo);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // ── AI Vision / OCR / Backup / C2PA (paid modules) ─────────────────────────

    /**
     * AI Vision (paid module) — bg-remove/upscale/smart-crop. Same 3-layer gate
     * as optimize() above; also needs a fresh ImageOptimizer, for the same reason.
     */
    public function aiVision(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('ai', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->run($fm, $this->diskManager, new \FluxFiles\ImageOptimizer(), $claims, $request->all());

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * OCR (paid module) — text extraction; result is returned, never persisted.
     */
    /** On-demand scan of an already-stored file (paid `virus` module) — core's POST /api/fm/virus/scan. */
    public function virusScan(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);
            $virus = \FluxFiles\ModuleRegistry::require('virus', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $disk = (string) $request->input('disk', 'local');
            $path = (string) $request->input('path', '');
            $result = $fm->scanForViruses($disk, $path, static fn (string $p): array => $virus->scanPath($p));

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function ocr(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('ocr', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->run($fm, $this->diskManager, $claims, $request->all());

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * Backup Bridge (paid module) — one-way subtree sync between disks.
     */
    public function backup(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('backup', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->run($fm, $this->diskManager, $claims, $request->all());

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * C2PA content provenance (paid module) — verify a file's manifest (read-only).
     */
    public function c2pa(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            // POST → the write bucket, as in core (verification reads + hashes the
            // whole file, so it must not ride the generous read budget).
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('c2pa', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->verify($fm, $this->diskManager, $claims, $request->all());

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * C2PA content provenance (paid module) — sign a file, producing a manifest.
     */
    public function c2paSign(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            $module = \FluxFiles\ModuleRegistry::require('c2pa', \FluxFiles\LicenseManager::fromEnv(), $claims);
            $result = $module->sign($fm, $this->diskManager, $claims, $request->all());

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // ── Gated media stream / on-demand image transform (free/core) ────────────
    //
    // Both are the shared core MediaEndpoint (the same class index.php uses), fed
    // this adapter's DiskManager, secret and rate limiter. Unauthenticated by the
    // normal JWT (see FluxFilesServiceProvider — registered with NO middleware):
    // the <img>/<video> element carries its own short-lived, single-file
    // StreamToken/ImageToken in the query string instead. The endpoint emits the
    // whole response itself, so each action exits afterwards: Laravel's kernel
    // would otherwise wrap the (void) return in a default Response and clobber the
    // Content-Type already sent.

    /** Serve one gated-local / SFTP file, Range-capable. Emits raw bytes, not JSON. */
    public function stream(Request $request): void
    {
        $this->mediaEndpoint()->stream($request->query(), \FluxFiles\MediaEndpoint::headersFromServer($request->server()));
        exit;
    }

    /** On-demand WebP/AVIF transform, with the preview watermark overlay for a watermarked token. */
    public function img(Request $request): void
    {
        $this->mediaEndpoint()->image($request->query(), \FluxFiles\MediaEndpoint::headersFromServer($request->server()));
        exit;
    }

    // ── Share + Intake (paid module) ─────────────────────────────────────────

    /**
     * Operator routes: create/list/revoke/analytics. The paid module does the
     * work; this only supplies Laravel's FileManager/DiskManager and the
     * recipient link base, and it never touches the returned token — that is
     * shown once and never stored, exactly as in standalone.
     */
    private function shareIntake(Request $request, string $module, string $op): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, in_array($op, ['create', 'revoke'], true));
            $mod = \FluxFiles\ModuleRegistry::require($module, \FluxFiles\LicenseManager::fromEnv(), $claims);
            $disk = (string) ($request->input('disk') ?? $request->query('disk') ?? 'local');

            if ($op === 'list') {
                return $this->ok($module === 'share'
                    ? $mod->listShares($this->diskManager, $claims, $disk)
                    : $mod->listPortals($this->diskManager, $claims, $disk));
            }
            if ($op === 'analytics') {
                $event = $request->query('event');
                return $this->ok($mod->analytics(
                    $this->diskManager,
                    $claims,
                    (string) $request->query('disk', 'local'),
                    (string) $request->query('jti', ''),
                    max(1, min(500, (int) $request->query('limit', 100))),
                    max(0, (int) $request->query('offset', 0)),
                    $event !== null && $event !== '' ? (string) $event : null
                ));
            }
            if ($op === 'revoke') {
                $jti = (string) ($request->input('jti') ?? '');
                $revoked = $module === 'share'
                    ? $mod->revokeShare($this->diskManager, $claims, $disk, $jti)
                    : $mod->revokePortal($this->diskManager, $claims, $disk, $jti);
                return $this->ok($revoked);
            }

            $fm = $this->fileManager($claims);
            $secret = (string) config('fluxfiles.secret');
            $body = $request->all();
            $res = $module === 'share'
                ? $mod->createShare($fm, $this->diskManager, $claims, $secret, $body)
                : $mod->createPortal($fm, $this->diskManager, $claims, $secret, $body);
            // Minting a public link is exactly what an access review must be able to see.

            // The recipient URL. The module builds it when the token carries a base
            // URL (`share_base_url`/`intake_base_url`); otherwise fall back to the
            // recipient landing page this adapter serves at its own site root.
            if (empty($res['url']) && !empty($res['token'])) {
                $res['url'] = \FluxFiles\Laravel\FluxFilesManager::publicLinkUrl(
                    $module === 'share' ? 'share.html' : 'intake.html',
                    (string) $res['token']
                );
            }

            return $this->ok($res);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    public function share(Request $r): JsonResponse { return $this->shareIntake($r, 'share', 'create'); }
    public function shareList(Request $r): JsonResponse { return $this->shareIntake($r, 'share', 'list'); }
    public function shareRevoke(Request $r): JsonResponse { return $this->shareIntake($r, 'share', 'revoke'); }
    public function shareAnalytics(Request $r): JsonResponse { return $this->shareIntake($r, 'share', 'analytics'); }
    public function intake(Request $r): JsonResponse { return $this->shareIntake($r, 'intake', 'create'); }
    public function intakeList(Request $r): JsonResponse { return $this->shareIntake($r, 'intake', 'list'); }
    public function intakeRevoke(Request $r): JsonResponse { return $this->shareIntake($r, 'intake', 'revoke'); }
    public function intakeAnalytics(Request $r): JsonResponse { return $this->shareIntake($r, 'intake', 'analytics'); }

    /**
     * The PUBLIC recipient routes, delegated to the SAME core handlers standalone
     * uses (PublicLinks.php). Nothing about tokens, expiry, passwords, download
     * caps or byte-sending is reimplemented here — a second copy of that is how a
     * hole appears on one platform only. This supplies Laravel's DiskManager and
     * then gets out of the way.
     *
     * The core handler writes its own status/headers/body and never returns a
     * value, which is why this method returns void: the response must not be
     * wrapped in the normal JSON envelope (a share/file hit can be a raw byte
     * stream or a 302 redirect). `$_GET`/`$_POST`/`$_FILES`/php://input are
     * already populated by PHP for these requests — Laravel's Request object
     * doesn't remove them.
     */
    private function publicLink(string $which, string $uri): void
    {
        $core = $this->fluxfilesBasePath() . '/api/PublicLinks.php';
        if (!is_file($core)) {
            http_response_code(501);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => null, 'error' => 'The FluxFiles core is not available', 'error_code' => 'core_missing']);
            exit;
        }
        require_once $core;

        // The core handler reads the signing secret and the storage path from $_ENV.
        $_ENV['FLUXFILES_SECRET'] = (string) config('fluxfiles.secret');
        $_ENV['FLUXFILES_STORAGE_PATH'] = (string) config('fluxfiles.storage_path');

        $method = request()->method();
        $diskConfigs = config('fluxfiles.disks');

        if ($which === 'share') {
            \handleSharePublic($method, $uri, $this->diskManager, $diskConfigs, $this->metaRepo);
        } else {
            \handleIntakePublic($method, $uri, $this->diskManager, $diskConfigs, $this->metaRepo);
        }
        exit; // the core handler has already sent status, headers and body
    }

    public function shareInfo(): void { $this->publicLink('share', '/api/fm/share/info'); }
    public function shareUnlock(): void { $this->publicLink('share', '/api/fm/share/unlock'); }
    public function shareFile(): void { $this->publicLink('share', '/api/fm/share/file'); }
    public function intakeInfo(): void { $this->publicLink('intake', '/api/fm/intake/info'); }
    public function intakeUpload(): void { $this->publicLink('intake', '/api/fm/intake/upload'); }

    /**
     * Recipient landing pages (share.html / intake.html), bundled with core and
     * served from this adapter's own site root. A static page cannot know where
     * this app's API lives (a custom `fluxfiles.route_prefix` changes it), so
     * `window.__FM_API_BASE__` is injected the same way WordPress's
     * public-link.php does it. Headers match standalone/WordPress: the URL
     * carries the recipient token, so it must never leak in a Referer or be
     * cached by a shared proxy.
     */
    private function publicPage(string $file): \Illuminate\Http\Response
    {
        $path = $this->fluxfilesBasePath() . '/public/' . $file;

        if (!file_exists($path)) {
            abort(404, "FluxFiles {$file} not found");
        }

        $html = (string) file_get_contents($path);
        $apiBase = rtrim((string) config('app.url'), '/') . '/' . trim((string) config('fluxfiles.route_prefix', 'api/fm'), '/');
        $inject = '<script>window.__FM_API_BASE__=' . json_encode($apiBase) . ';</script>';
        $html = str_contains($html, '</head>')
            ? str_replace('</head>', $inject . '</head>', $html)
            : $inject . $html;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'private, no-store');
    }

    public function sharePage(): \Illuminate\Http\Response { return $this->publicPage('share.html'); }
    public function intakePage(): \Illuminate\Http\Response { return $this->publicPage('intake.html'); }

    // Chunk upload routes

    public function chunkInit(Request $request): JsonResponse
    {
        try {
            $claims = $this->claims($request);
            $this->rateLimit($claims, true);
            $fm = $this->fileManager($claims);

            // S3 multipart sends bytes browser→S3 on presigned URLs, so they never
            // reach this server and CANNOT be scanned. A tenant that asked for virus
            // scanning must not have an unscannable side door — refuse the whole
            // chunk route family rather than let it quietly bypass the scanner.
            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            $claims->requirePerm('write');

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields', 400);
            }
            $claims->requireDisk($disk);

            $sizeBytes = (int) ($request->input('size') ?? $request->input('size_bytes') ?? 0);
            if ($sizeBytes <= 0) {
                throw new ApiException('Missing required field: size', 400);
            }
            $scopedPath = $fm->validateUserPath($path);
            $scopedPath = $fm->validateChunkUpload($disk, $scopedPath, $sizeBytes, true);

            $chunker = new ChunkUploader($this->diskManager);
            $result = $chunker->initiate($disk, $scopedPath, $fm->chunkInitMetadata($disk, $scopedPath));

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

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            $claims->requirePerm('write');

            $disk       = $request->input('disk');
            $key        = $request->input('key');
            $uploadId   = $request->input('upload_id');
            $partNumber = $request->input('part_number');

            if (!$disk || !$key || !$uploadId || !$partNumber) {
                throw new ApiException('Missing required fields', 400);
            }
            $claims->requireDisk($disk);
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }
            $key = $fm->validateScopedPath($key);

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

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            $claims->requirePerm('write');

            $disk     = $request->input('disk');
            $key      = $request->input('key');
            $uploadId = $request->input('upload_id');
            $parts    = $request->input('parts');

            if (!$disk || !$key || !$uploadId || !$parts) {
                throw new ApiException('Missing required fields', 400);
            }
            $claims->requireDisk($disk);
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }
            $key = $fm->validateScopedPath($key);

            $chunker = new ChunkUploader($this->diskManager);

            $result = $chunker->complete(
                $disk, $key, $uploadId, $parts,
                fn(int $size) => $fm->validateChunkUpload($disk, $key, $size),
                $claims->uploadCollision === 'overwrite'
            );

            $this->metaRepo->save($disk, $key, [
                'uploaded_by' => $claims->userId,
                'size' => $result['size'],
                'modified' => time(),
            ]);
            $this->metaRepo->saveHash($disk, $key, '');
            // Core's central hook (index.php) audits/webhooks EVERY successful POST
            // /api/fm/chunk/* substep (init/complete/abort all match `$isWriteAction &&
            // $data !== null`), not only completion — mirror that here rather than
            // inventing an adapter-specific "only log completion" policy. This is the
            // point where the object actually lands, so it's the most meaningful entry.

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

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }
            if ($claims->allowDlpScan) {
                throw new ApiException(
                    'Chunked upload cannot be scanned for PII — use the standard upload, or turn off allow_dlp_scan',
                    409,
                    'dlp_unscannable'
                );
            }

            $claims->requirePerm('write');

            $disk     = $request->input('disk');
            $key      = $request->input('key');
            $uploadId = $request->input('upload_id');

            if (!$disk || !$key || !$uploadId) {
                throw new ApiException('Missing required fields', 400);
            }
            $claims->requireDisk($disk);
            if (!$claims->isPathInScope($key)) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }
            $key = $fm->validateScopedPath($key);

            $chunker = new ChunkUploader($this->diskManager);

            $result = $chunker->abort($disk, $key, $uploadId);
            // Matches core's behavior — see the comment on chunkComplete() above.

            return $this->ok($result);
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
        $html = self::rewriteUiAssets($html, $base . '/assets', self::uiDebug());
        $html = self::injectLocale($html, $base . '/lang', (string) config('fluxfiles.locale', ''));

        $resp = response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            // Always revalidate the HTML so the ?v= asset URLs are never stale.
            ->header('Cache-Control', 'no-cache, must-revalidate')
            // The UI can be opened with a `?token=` (dev) — never leak the URL via
            // Referer. Origin is unaffected, so the Origin checks still work.
            ->header('Referrer-Policy', 'no-referrer');
        $frameAncestors = self::frameAncestors();
        if ($frameAncestors !== '') {
            $resp->header('Content-Security-Policy', 'frame-ancestors ' . $frameAncestors);
        }
        return $resp;
    }

    /** FLUXFILES_DEBUG (config `fluxfiles.debug`, else the raw env var): serve the readable fm.js/fm.css. */
    private static function uiDebug(): bool
    {
        $v = config('fluxfiles.debug');
        if ($v === null) {
            $v = $_ENV['FLUXFILES_DEBUG'] ?? getenv('FLUXFILES_DEBUG');
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Cache-bust the UI assets with a content hash, so a core update is never served
     * from a stale browser/proxy cache (the static URLs carry no version of their own),
     * and prefer the pre-minified fm.min.js / fm.min.css when the installed core ships
     * them (falls back to fm.js / fm.css otherwise). `$debug` forces the
     * readable sources. The vendored Alpine is versioned the same way when present.
     */
    public static function rewriteUiAssets(string $html, string $assetsDir, bool $debug = false): string
    {
        $pick = static function (string $file) use ($assetsDir, $debug): string {
            $min = (string) preg_replace('/\.(js|css)$/', '.min.$1', $file);
            return (!$debug && is_file($assetsDir . '/' . $min)) ? $min : $file;
        };
        $ver = static function (string $file) use ($assetsDir): string {
            $p = $assetsDir . '/' . $file;
            return is_file($p) ? substr(md5_file($p), 0, 10) : (string) time();
        };
        $css = $pick('fm.css');
        $js = $pick('fm.js');
        return str_replace(
            ['"../assets/fm.css"', '"../assets/fm.js"', '"../assets/vendor/alpine/alpine.min.js"'],
            [
                '"../assets/' . $css . '?v=' . $ver($css) . '"',
                '"../assets/' . $js . '?v=' . $ver($js) . '"',
                '"../assets/vendor/alpine/alpine.min.js?v=' . $ver('vendor/alpine/alpine.min.js') . '"',
            ],
            $html
        );
    }

    /**
     * Inject the UI's translations as `window.__FM_LOCALE__`, the same way core's
     * index.php serves /public/index.html. The embedded (iframe) boot path only
     * fetches messages when the host's FM_CONFIG names a locale — `<x-fluxfiles>`
     * doesn't — and otherwise keeps "the server-injected locale", so without this
     * every proxy-mode embed rendered raw i18n keys (`toolbar.upload`,
     * `share.title`, …) instead of labels. `fluxfiles.locale` (FLUXFILES_LOCALE)
     * picks the locale; empty/unsupported → English, as in core.
     */
    public static function injectLocale(string $html, string $langDir, string $locale = ''): string
    {
        if (!is_dir($langDir)) {
            return $html;
        }
        $i18n = new \FluxFiles\I18n($langDir, $locale !== '' ? $locale : null);
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $injection = 'window.__FM_LOCALE__ = { locale: ' . json_encode($i18n->locale(), $flags)
            . ', dir: ' . json_encode($i18n->direction(), $flags)
            . ', messages: ' . $i18n->toJson() . ' };';
        $html = str_replace(
            "window.__FM_LOCALE__ = window.__FM_LOCALE__ || { locale: 'en', dir: 'ltr', messages: {} };",
            $injection,
            $html
        );
        // The tag carries more attributes (`<html lang="en" translate="no">`), so
        // match the opening only — core's exact '<html lang="en">' never matches.
        return (string) preg_replace(
            '/<html lang="en"/',
            '<html lang="' . htmlspecialchars($i18n->locale()) . '" dir="' . htmlspecialchars($i18n->direction()) . '"',
            $html,
            1
        );
    }

    /**
     * CSP `frame-ancestors` for the UI page, same precedence as core's index.php:
     * `fluxfiles.frame_ancestors` (FLUXFILES_FRAME_ANCESTORS) verbatim, else
     * `'self'` + `fluxfiles.allowed_origins`, else '' (no header — the product is
     * an iframe embed, so there is no safe universal default). A value with
     * characters outside a plain source list is ignored rather than emitted.
     */
    private static function frameAncestors(): string
    {
        $value = trim((string) config('fluxfiles.frame_ancestors', ''));
        if ($value === '') {
            $origins = array_values(array_filter(array_map(
                static fn ($o) => trim((string) $o),
                (array) config('fluxfiles.allowed_origins', [])
            )));
            $value = $origins !== [] ? "'self' " . implode(' ', $origins) : '';
        }
        return ($value !== '' && preg_match('/^[A-Za-z0-9 :\/\.\*\-\']+$/', $value)) ? $value : '';
    }

    /**
     * Where the browser SDK the `<x-fluxfiles>` component loads from `/fluxfiles.js`
     * lives. The core package Composer installs (the fluxfiles-core split of
     * packages/core) does NOT contain it — it is packages/sdk's file — so looking only
     * in the core base path 404'd on every default `composer require fluxfiles/laravel`
     * install and the component never booted (`FluxFiles is not defined`). Order:
     * an operator-supplied copy in the base path (release ZIP layout), the monorepo
     * sibling (same lookup as core's router.php), then the copy this adapter ships in
     * resources/ (kept byte-identical to packages/sdk by test-laravel-smoke.php).
     */
    public static function resolveSdkPath(string $basePath): ?string
    {
        foreach ([
            rtrim($basePath, '/') . '/fluxfiles.js',
            rtrim($basePath, '/') . '/../sdk/fluxfiles.js',
            dirname(__DIR__, 3) . '/resources/fluxfiles.js',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public function sdkJs(): \Illuminate\Http\Response
    {
        $path = self::resolveSdkPath($this->fluxfilesBasePath());

        if ($path === null) {
            abort(404, 'FluxFiles SDK not found');
        }

        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Hash memo keyed by path + mtime + size (so a changed file is a new key): per
     * request always, and across requests through APCu when it is enabled - the
     * unauthenticated asset route must not re-read and re-hash files on every hit,
     * 304 revalidations included.
     */
    private static function memoAssetHash(string $key, callable $compute): string
    {
        static $mem = [];
        if (isset($mem[$key])) {
            return $mem[$key];
        }
        $apcu = function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
        if ($apcu) {
            $hit = apcu_fetch('fluxfiles_asset_' . md5($key), $ok);
            if ($ok && is_string($hit)) {
                return $mem[$key] = $hit;
            }
        }
        $val = $compute();
        if ($apcu && $val !== '') {
            apcu_store('fluxfiles_asset_' . md5($key), $val, 3600);
        }
        return $mem[$key] = $val;
    }

    /**
     * Does ?v= name the file that is actually being served? Only then is the URL
     * safe to cache as immutable: a stale node mid-rolling-deploy, or a hand-typed
     * v, would otherwise pin the wrong bytes for a year. Accepts a prefix (>= 8
     * hex) of the file's md5 (fm.js / fm.css / Alpine, as the page links them), or
     * - for the lazily loaded vendor/codemirror files - of that
     * vendor tree's content hash (fm.js's FM_VENDOR_VER; same algorithm as
     * scripts/vendor-ver.mjs).
     */
    public static function assetVersionMatches(string $filePath, string $assetsDir, string $v): bool
    {
        if (!preg_match('/^[0-9a-f]{8,32}$/', $v)) {
            return false;
        }
        $len = strlen($v);
        $st = @stat($filePath);
        $md5 = $st === false ? false : self::memoAssetHash($filePath . '|' . $st['mtime'] . '|' . $st['size'], static fn () => (string) @md5_file($filePath));
        if ($md5 !== false && $md5 !== '' && hash_equals(substr($md5, 0, $len), $v)) {
            return true;
        }
        $base = rtrim($assetsDir, '/');
        if (strncmp($filePath, $base . '/', strlen($base) + 1) === 0
            && preg_match('#^vendor/(codemirror)/#', substr($filePath, strlen($base) + 1), $m)) {
            $dir = $base . '/vendor/' . $m[1];
            $files = [];
            $sig = '';
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
                    $files[] = $rel;
                    $sig .= $rel . ':' . $f->getMTime() . ':' . $f->getSize() . ';';
                }
            }
            // Stat-only signature: the (~700 KB) tree is re-read and re-hashed only when a file changed.
            $tree = self::memoAssetHash('tree|' . $dir . '|' . md5($sig), static function () use ($files, $dir): string {
                sort($files, SORT_STRING);
                $h = hash_init('md5');
                foreach ($files as $r) {
                    hash_update($h, $r . "\0");
                    hash_update($h, (string) file_get_contents($dir . '/' . $r));
                    hash_update($h, "\0");
                }
                return hash_final($h);
            });
            return hash_equals(substr($tree, 0, $len), $v);
        }
        return false;
    }

    /**
     * Cache validators for a static UI asset. $versioned is true only when `?v=`
     * matched the served file (assetVersionMatches()) - then it is immutable for a
     * year; any other request gets a short revalidating cache, a cheap mtime+size
     * ETag and a 304 on a match.
     *
     * @return array{0:string,1:bool} [Cache-Control, notModified]
     */
    public static function assetCaching(string $filePath, bool $versioned, string $ifNoneMatch, string &$etag): array
    {
        $etag = '"' . dechex((int) filemtime($filePath)) . '-' . dechex((int) filesize($filePath)) . '"';
        if ($versioned) {
            return ['public, max-age=31536000, immutable', false];
        }
        $match = false;
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || (str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate) === $etag) {
                $match = true;
                break;
            }
        }
        return ['public, max-age=300, must-revalidate', $match];
    }

    public function asset(string $file, ?\Illuminate\Http\Request $request = null): \Illuminate\Http\Response
    {
        $request ??= (function_exists('request') ? request() : null);
        $basePath = $this->fluxfilesBasePath() . '/assets';
        $filePath = realpath($basePath . '/' . $file);

        // Prevent directory traversal. Compared WITH a trailing separator so a
        // sibling directory whose name merely starts with the base (…/assets-x)
        // cannot pass the prefix test. `$file` may contain '/' (assets/vendor/…)
        // so this check, not the route pattern, is what bounds it.
        $realBase = realpath($basePath);
        if (!$filePath || !$realBase || strncmp($filePath, $realBase . '/', strlen($realBase) + 1) !== 0) {
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

        $etag = '';
        $versioned = $request !== null
            && self::assetVersionMatches($filePath, $realBase, (string) $request->query('v', ''));
        [$cache, $notModified] = self::assetCaching($filePath, $versioned, $request !== null ? (string) $request->header('If-None-Match', '') : '', $etag);
        if ($notModified) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', $cache);
        }

        return response(file_get_contents($filePath), 200)
            ->header('Content-Type', $mime . '; charset=utf-8')
            ->header('ETag', $etag)
            ->header('Cache-Control', $cache);
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

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
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\QuotaManager;
use FluxFiles\RateLimiterFileStorage;
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
        // Turns on isGatedLocal()/gatedLocalUrl()/imgBaseUrl() inside list() —
        // unconditional, same as index.php. Without this, list() never emits
        // img_base/gatedLocal URLs even though /stream and /img are now routed.
        $fm->setStreamSecret((string) config('fluxfiles.secret'));

        // On-upload auto-optimize (FREE/core). Wire the optimizer hook when the token
        // asks for it (`auto_optimize`) — mirrors index.php's wiring. The module does
        // the work; FileManager records the savings + renames to .webp.
        if ($claims->autoOptimize ?? false) {
            $optimizeModule = new \FluxFiles\OptimizeModule();
            $fm->setUploadOptimizer(static function (string $bytes, int $quality) use ($optimizeModule) {
                return $optimizeModule->optimizeBytes($bytes, $quality);
            });
        }

        // On-upload AI auto-tag. Wired only when the token asks for it (`ai_auto_tag`)
        // AND an AI provider is configured server-side — without one this stays a
        // no-op. FileManager::upload() re-checks $this->aiTagger !== null itself
        // before calling analyze(), the same gate the manual aiTag() action uses.
        if ($claims->aiAutoTag) {
            $aiProvider = config('fluxfiles.ai_provider', env('FLUXFILES_AI_PROVIDER', ''));
            if (!empty($aiProvider)) {
                $fm->setAiTagger(new \FluxFiles\AiTagger(
                    $aiProvider,
                    config('fluxfiles.ai_api_key', env('FLUXFILES_AI_API_KEY', '')),
                    config('fluxfiles.ai_model', env('FLUXFILES_AI_MODEL')) ?: null,
                    config('fluxfiles.ai_base_url', env('FLUXFILES_AI_BASE_URL')) ?: null
                ));
            }
        }

        // File versioning (paid module). Wire the version keeper ONLY when the token
        // asks (`allow_versioning`) AND the module is installed + licensed — so the
        // free core keeps no versions. FileManager calls it before overwriting an
        // existing file. Mirrors index.php's wiring.
        if (($claims->allowVersioning ?? false)
            && \FluxFiles\ModuleRegistry::installed('versioning')
            && \FluxFiles\LicenseManager::fromEnv()->licensed('versioning')) {
            $versioning = new \FluxFiles\Versioning\VersioningModule();
            $diskManager = $this->diskManager;
            $fm->setVersionKeeper(static function (string $d, string $key, $fs) use ($versioning, $claims, $diskManager) {
                $versioning->keep($fs, $key, $claims, $diskManager, $d);
            });
            // Erase version history on permanent delete, so `/delete` can't be undone
            // via a restore of a version saved before it — see VersioningModule::purge().
            $fm->setVersionPurger(static function (string $d, string $key, $fs) use ($versioning, $diskManager) {
                $versioning->purge($fs, $key, $diskManager, $d);
            });
        }

        // Virus scan (paid module) — the proxy handles /upload itself, so without this
        // a tenant with `allow_virus_scan` would have files stored unscanned here while
        // the same token is scanned in standalone. The module gate resolves INSIDE the
        // callback (lazily, on the write that needs it) so plain reads still work and a
        // missing/unlicensed module surfaces as 501/402/403 on upload — never silence.
        if (($claims->allowVirusScan ?? false)) {
            $fm->setVirusScanner(static function (string $localPath) use ($claims): array {
                /** @var \FluxFiles\Virus\VirusScanModule $virus */
                $virus = \FluxFiles\ModuleRegistry::require('virus', \FluxFiles\LicenseManager::fromEnv(), $claims);
                return $virus->scanPath($localPath);
            });
        }
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
        string $key,
        ?string $detail = null
    ): void {
        $audit = new AuditLogStorage($this->metaRepo, $claims->allowedDisks);
        $audit->log($claims->userId, $action, $disk, $key, null, null, $detail);
    }

    /**
     * Fire a webhook for a file-changing action (paid module). Gated the same
     * 3-layer way index.php's inline wiring does it (installed + licensed +
     * `allow_webhooks` + a configured `webhook_url`) — best-effort, never throws
     * into the request path (WebhooksModule::dispatch swallows its own failures).
     *
     * @param array<string,mixed> $context
     */
    private function dispatchWebhook(\FluxFiles\Claims $claims, string $event, array $context): void
    {
        if (!$claims->allowWebhooks || $claims->webhookUrl === ''
            || !\FluxFiles\ModuleRegistry::installed('webhooks')
            || !\FluxFiles\LicenseManager::fromEnv()->licensed('webhooks')) {
            return;
        }
        $secret = (string) config('fluxfiles.secret');
        (new \FluxFiles\Webhooks\WebhooksModule())->dispatch($claims, $secret, $event, $context);
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
            $this->dispatchWebhook($claims, 'upload', [
                'disk' => $disk !== '' ? $disk : 'local',
                'path' => $path,
                'name' => (string) ($result['name'] ?? basename($path)),
            ]);

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
            $this->dispatchWebhook($claims, 'delete', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
            $this->dispatchWebhook($claims, 'rename', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
            $this->dispatchWebhook($claims, 'move', ['disk' => $disk, 'path' => $from, 'name' => basename($from)]);

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
            $this->dispatchWebhook($claims, 'copy', ['disk' => $disk, 'path' => $from, 'name' => basename($from)]);

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
            $this->dispatchWebhook($claims, 'url_import', [
                'disk' => $disk,
                'path' => (string) ($result['key'] ?? ''),
                'name' => basename((string) ($result['key'] ?? '')),
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
            $this->logAudit($claims, 'mkdir', $disk, $path);
            $this->dispatchWebhook($claims, 'mkdir', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
            $this->dispatchWebhook($claims, 'cross_copy', ['disk' => $srcDisk, 'path' => $srcPath, 'name' => basename($srcPath)]);

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
            $this->dispatchWebhook($claims, 'cross_move', ['disk' => $srcDisk, 'path' => $srcPath, 'name' => basename($srcPath)]);

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
            $this->dispatchWebhook($claims, 'crop', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
            $this->logAudit($claims, 'watermark', $disk, $path);
            $this->dispatchWebhook($claims, 'watermark', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
            $this->logAudit($claims, 'watermark_remove', $disk, $path);
            $this->dispatchWebhook($claims, 'watermark_remove', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
                config('fluxfiles.ai_model', env('FLUXFILES_AI_MODEL')) ?: null,
                // 4th arg — cores older than this adapter's floor simply ignore it
                // (PHP drops extra arguments to a userland function), so the base-URL
                // override is additive and needs no constraint bump.
                config('fluxfiles.ai_base_url', env('FLUXFILES_AI_BASE_URL')) ?: null
            );
            $fm->setAiTagger($aiTagger);

            $disk = $request->input('disk');
            $path = $request->input('path');

            if (!$disk || !$path) {
                throw new ApiException('Missing required fields: disk, path', 400);
            }

            $result = $fm->aiTag($disk, $path);
            $this->logAudit($claims, 'ai_tag', $disk, $path);
            $this->dispatchWebhook($claims, 'ai_tag', ['disk' => $disk, 'path' => $path, 'name' => basename($path)]);

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
            $this->dispatchWebhook($claims, 'metadata_update', ['disk' => $disk, 'path' => $key, 'name' => basename($key)]);

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
     * Mint a FRESH JWT for the session-authenticated user.
     *
     * This route is deliberately NOT behind the FluxFilesAuth (JWT) middleware:
     * the whole point is that the iframe's JWT has expired, so the embedded UI
     * (via the SDK `onTokenRefresh` hook) calls this to obtain a new one WITHOUT
     * a full page reload. Auth here is the Laravel session ('web' + 'auth'); if
     * the SESSION is also gone, this 401s and the UI falls back to a reload
     * (which sends the user through login). Mints with the server-side config
     * defaults — components using custom per-tag `overrides` should supply their
     * own `:on-token-refresh` to preserve them.
     */
    public function token(Request $request): JsonResponse
    {
        try {
            $manager = app(\FluxFiles\Laravel\FluxFilesManager::class);
            return $this->ok(['token' => $manager->tokenForUser()]);
        } catch (\Throwable $e) {
            return $this->error('Unable to refresh token', 401, 'token_refresh_failed');
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
            $this->dispatchWebhook($claims, 'optimize', [
                'disk' => (string) $request->input('disk', 'local'),
                'path' => (string) $request->input('path', ''),
                'name' => basename((string) $request->input('path', '')),
            ]);

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
            $this->dispatchWebhook($claims, 'content_edit', [
                'disk' => (string) $request->input('disk', 'local'),
                'path' => (string) $request->input('path', ''),
                'name' => basename((string) $request->input('path', '')),
            ]);

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
            $this->dispatchWebhook($claims, 'extract', [
                'disk' => (string) $request->input('disk', 'local'),
                'path' => (string) $request->input('path', ''),
                'name' => basename((string) $request->input('path', '')),
            ]);

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
            $this->dispatchWebhook($claims, 'trash', ['disk' => (string) $disk, 'path' => (string) $path, 'name' => basename((string) $path)]);

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
            $this->dispatchWebhook($claims, 'restore', ['disk' => (string) $disk, 'path' => (string) $trashId, 'name' => basename((string) $trashId)]);

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
            $this->dispatchWebhook($claims, 'purge', ['disk' => (string) $disk, 'path' => (string) $trashId, 'name' => basename((string) $trashId)]);

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
            $this->dispatchWebhook($claims, 'empty_trash', ['disk' => (string) $disk, 'path' => '', 'name' => '']);

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

    /**
     * Audit export (paid module) — a full, unpaginated download of the tenant's
     * audit history (live + archived), as NDJSON or CSV. Bypasses the ok()/error()
     * JSON envelope: the module streams its own headers + body directly, same
     * posture as core's own /api/fm/zip streaming route.
     */
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
            return response()->json(
                ['error' => $e->getMessage(), 'code' => $e->getErrorCode(), 'params' => $e->getErrorParams()],
                $e->getHttpCode()
            );
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

            return $this->ok($module->purge($this->metaRepo, $claims, $disk, $before));
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
                (string) $request->query('path', '')
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
            $result = $module->restore(
                $fm,
                $this->diskManager,
                $claims,
                (string) $request->input('disk', 'local'),
                (string) $request->input('path', ''),
                (string) $request->input('version_id', '')
            );

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
            $this->logAudit($claims, 'ai_vision', (string) $request->input('disk', 'local'), (string) $request->input('path', ''));
            $this->dispatchWebhook($claims, 'ai_vision', [
                'disk' => (string) $request->input('disk', 'local'),
                'path' => (string) $request->input('path', ''),
                'name' => basename((string) $request->input('path', '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * OCR (paid module) — text extraction; result is returned, never persisted.
     */
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
            $this->logAudit($claims, 'backup', (string) $request->input('from_disk', 'local'), (string) $request->input('path', ''));
            $this->dispatchWebhook($claims, 'backup', [
                'disk' => (string) $request->input('from_disk', 'local'),
                'path' => (string) $request->input('path', ''),
                'name' => basename((string) $request->input('path', '')),
            ]);

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
            $this->rateLimit($claims, false);
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
            $this->logAudit($claims, 'c2pa_sign', (string) $request->input('disk', 'local'), (string) $request->input('path', ''));
            $this->dispatchWebhook($claims, 'c2pa_sign', [
                'disk' => (string) $request->input('disk', 'local'),
                'path' => (string) $request->input('path', ''),
                'name' => basename((string) $request->input('path', '')),
            ]);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    /**
     * SSH terminal (command-runner) — SFTP disks only. Free/core, not a
     * ModuleRegistry module. Mirrors index.php's /api/fm/terminal gate order
     * exactly: server kill-switch, allow_terminal claim, write perm, disk ACL,
     * driver check, dangerous-command double-confirm, then run.
     */
    public function terminal(Request $request): JsonResponse
    {
        try {
            if (($_ENV['FLUXFILES_TERMINAL_DISABLED'] ?? '') === 'true') {
                throw new ApiException('The terminal is disabled on this server', 403, 'terminal_disabled');
            }
            $claims = $this->claims($request);
            if (!$claims->allowTerminal) {
                throw new ApiException('Terminal access is not allowed', 403, 'terminal_forbidden');
            }
            $this->rateLimit($claims, true);
            if (!$claims->hasPerm('write')) {
                throw new ApiException('Permission denied: write', 403, 'permission_denied');
            }
            $disk = (string) $request->input('disk', '');
            if (!$claims->hasDisk($disk)) {
                throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
            }
            if (($this->diskManager->config($disk)['driver'] ?? '') !== 'sftp') {
                throw new ApiException('The terminal only works on an SFTP disk', 400, 'terminal_unsupported');
            }
            $cmd = trim((string) $request->input('cmd', ''));
            if ($cmd === '') {
                throw new ApiException('Missing command', 400, 'missing_param');
            }
            $confirmOff = ($_ENV['FLUXFILES_TERMINAL_CONFIRM'] ?? '') === 'false';
            if (!$confirmOff && empty($request->input('confirm')) && \FluxFiles\SshTerminal::isDangerous($cmd)) {
                throw new ApiException('This command looks dangerous — confirm to run it', 409, 'terminal_confirm_required');
            }
            [$conn, $root] = $this->diskManager->sftpConnection($disk);
            $cwd = \FluxFiles\SshTerminal::resolveCwd((string) $request->input('cwd', ''), $root);
            $timeout = (int) ($_ENV['FLUXFILES_TERMINAL_TIMEOUT'] ?? 30);
            $result = \FluxFiles\SshTerminal::run($conn, $cmd, $cwd, $timeout);
            if (empty($result['shell_ok'])) {
                throw new ApiException('This host does not allow a shell (SFTP-only)', 400, 'terminal_no_shell');
            }
            $this->logAudit($claims, 'terminal', $disk, '', $cmd);
            $this->dispatchWebhook($claims, 'terminal', ['disk' => $disk, 'path' => '', 'name' => '']);

            return $this->ok($result);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getHttpCode(), $e->getErrorCode(), $e->getErrorParams());
        }
    }

    // ── Gated media stream / on-demand image transform (free/core) ────────────
    //
    // Both are ported from index.php's handleMediaStream()/handleImageTransform()
    // almost verbatim, using this adapter's own DiskManager/disk config instead
    // of a fresh one built from core's config/disks.php file. Unauthenticated by
    // the normal JWT (see FluxFilesServiceProvider — registered with NO
    // middleware): the <img>/<video> element carries its own short-lived,
    // single-file StreamToken/ImageToken in the query string instead.
    //
    // Watermark-overlay resolution (ff_resolve_watermark() in index.php) is
    // intentionally NOT ported: ImageToken::mint() only embeds a `wm` scope when
    // `watermark_enabled` is truthy, and this adapter's FluxFilesManager never
    // forwards that claim in proxy mode (see docs/FEATURES.md — overlay preview
    // stays a core-standalone/embed-only feature), so a proxy-minted ImageToken
    // never carries one. If that ever changes, this needs the watermark branch.

    private function qStrParam(Request $request, string $key, string $default = ''): string
    {
        $v = $request->query($key);
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Snap a requested quality to the nearest allowed step (bounds cache variants). */
    private function snapQuality($raw): int
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

    /** Emit image bytes with safe headers; $immutable adds a long-lived cache policy. */
    private function serveBytes(string $data, string $mime, bool $immutable = false): void
    {
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header('Vary: Accept');
        header($immutable
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : 'Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($data));
        echo $data;
        // Laravel's kernel would otherwise wrap this action's (void) return in its
        // own default Response and clobber Content-Type on send — see stream().
        exit;
    }

    /**
     * Serve one file on a gated (private) local disk, or any SFTP disk (no static
     * URL exists for either), authenticated by a per-file stream token. Honours
     * HTTP Range so a <video>/<audio> can seek. Emits raw bytes, not JSON.
     */
    public function stream(Request $request): void
    {
        $secret = (string) config('fluxfiles.secret');
        if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'FLUXFILES_SECRET is not configured';
            exit;
        }
        try {
            $scope = \FluxFiles\StreamToken::verify($this->qStrParam($request, 'token'), $secret);
        } catch (ApiException $e) {
            http_response_code($e->getHttpCode());
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        $disk = $scope['disk'];
        $path = $scope['path'];

        if ($path === '' || strpos($path, "\0") !== false
            || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid path';
            exit;
        }

        $config = $this->diskManager->config($disk);
        $driver = $config['driver'] ?? '';
        $isGatedLocal = $driver === 'local' && !empty($config['private']);
        $isSftp = $driver === 'sftp';
        if (!$isGatedLocal && !$isSftp) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Streaming not available for this disk';
            exit;
        }

        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
        $inlineOk = (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode(basename($path)) . '"');

        if ($isGatedLocal) {
            $root = realpath((string) ($config['root'] ?? ''));
            $abs = $root !== false ? realpath($root . '/' . $path) : false;
            if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not found';
                exit;
            }
            // Production fast path: hand the bytes to nginx (native Range, no PHP copy).
            $xaccel = (string) env('FLUXFILES_XACCEL', '');
            if ($xaccel !== '') {
                header('Content-Type: ' . $mime);
                header('X-Accel-Buffering: no');
                header('X-Accel-Redirect: ' . rtrim($xaccel, '/') . '/' . $path);
                exit;
            }
            \FluxFiles\RangeStreamer::stream($abs, $mime, $request->server('HTTP_RANGE'));
            exit;
        }

        // SFTP: read through Flysystem and stream the bytes — no byte-range support
        // (SFTP can't do it natively), the whole file is sent.
        try {
            $fs = $this->diskManager->disk($disk);
            if (!$fs->fileExists($path)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not found';
                exit;
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
        // Laravel's kernel wraps this action's (void) return in its own default
        // Response and sends it — which would clobber every header already
        // emitted above (Symfony's Response::sendHeaders() always re-asserts
        // Content-Type). exit stops that from ever running, same as index.php's
        // dispatcher doing `handleMediaStream(); exit;` in the standalone app.
        exit;
    }

    /**
     * Serve an on-demand WebP/AVIF transform of one image, cached in the file's
     * _variants/ directory. Authenticated by an image token (query string).
     */
    public function img(Request $request): void
    {
        $secret = (string) config('fluxfiles.secret');
        if ($secret === '' || $secret === 'change-me-to-random-32-char-string') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'FLUXFILES_SECRET is not configured';
            exit;
        }
        try {
            $scope = \FluxFiles\ImageToken::verify($this->qStrParam($request, 'token'), $secret);
        } catch (ApiException $e) {
            http_response_code($e->getHttpCode());
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }

        $disk = $scope['disk'];
        $path = $scope['path'];
        if ($path === '' || strpos($path, "\0") !== false
            || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid path';
            exit;
        }

        $optimizer = new \FluxFiles\ImageOptimizer();
        if (!$optimizer->isImage($path)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not an image';
            exit;
        }

        $dpr = (float) $request->query('dpr', 1);
        $dpr = $dpr >= 2.5 ? 3.0 : ($dpr >= 1.5 ? 2.0 : 1.0);

        $maxWidth = $scope['maxWidth'] > 0 ? $scope['maxWidth'] : 2000;
        $reqWidth = (int) round(((int) $request->query('width', 0)) * $dpr);
        $width = $reqWidth > 0 ? min($maxWidth, max(100, (int) round($reqWidth / 100) * 100)) : 0;
        $reqHeight = (int) round(((int) $request->query('height', 0)) * $dpr);
        $height = $reqHeight > 0 ? min($maxWidth, max(100, (int) round($reqHeight / 100) * 100)) : 0;
        $fit = ($request->query('fit', 'contain') === 'cover') ? 'cover' : 'contain';
        $defaultQuality = $scope['defaultQuality'] > 0 ? $scope['defaultQuality'] : 80;
        $quality = $this->snapQuality($request->query('quality', $defaultQuality));

        $reqFormat = strtolower($this->qStrParam($request, 'format', 'auto'));
        $avifOk = $optimizer->avifSupported();
        $accept = (string) $request->header('Accept', '');
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

        try {
            $fs = $this->diskManager->disk($disk);
        } catch (\Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Disk not available';
            exit;
        }

        if (!$fs->fileExists($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }

        $origMime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';

        // No watermark scope ever reaches this adapter — see the class-note above.
        if ($format === '') {
            $this->serveBytes((string) $fs->read($path), $origMime);
        }

        $ver = (string) (@$fs->lastModified($path) ?: '0');
        $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, '', $format, $height, $fit);
        $outMime = 'image/' . $format;

        if ($fs->fileExists($cacheKey)) {
            // S3/R2: redirect to a presigned URL of the cached image (no app egress).
            if (($this->diskManager->config($disk)['driver'] ?? '') === 's3') {
                $redirect = $this->diskManager->presignGetUrl($disk, $cacheKey, 3600);
                if ($redirect !== null) {
                    header('Cache-Control: private, max-age=600');
                    header('Vary: Accept');
                    header('Location: ' . $redirect, true, 302);
                    exit;
                }
            }
            $this->serveBytes((string) $fs->read($cacheKey), $outMime, true);
        }

        $out = $optimizer->transform((string) $fs->read($path), $width, $quality, null, $format, $height, $fit);
        if ($out === null) {
            // Animated GIF / SVG / non-raster / bomb — serve the original untouched.
            $this->serveBytes((string) $fs->read($path), $origMime);
        }

        // Honor the format transform() actually produced (falls back to WebP if an
        // AVIF encode isn't available at runtime) so cache key + Content-Type agree.
        $outFormat = $out['format'] ?? $format;
        if ($outFormat !== $format) {
            $cacheKey = \FluxFiles\ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, '', $outFormat, $height, $fit);
            $outMime = 'image/' . $outFormat;
        }
        try { $fs->write($cacheKey, $out['data']); } catch (\Throwable $e) { /* best-effort cache */ }
        $this->serveBytes($out['data'], $outMime, true);
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
                return $this->ok($module === 'share'
                    ? $mod->revokeShare($this->diskManager, $claims, $disk, $jti)
                    : $mod->revokePortal($this->diskManager, $claims, $disk, $jti));
            }

            $fm = $this->fileManager($claims);
            $secret = (string) config('fluxfiles.secret');
            $body = $request->all();
            $res = $module === 'share'
                ? $mod->createShare($fm, $this->diskManager, $claims, $secret, $body)
                : $mod->createPortal($fm, $this->diskManager, $claims, $secret, $body);

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
            \handleSharePublic($method, $uri, $this->diskManager, $diskConfigs);
        } else {
            \handleIntakePublic($method, $uri, $this->diskManager, $diskConfigs);
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
            $this->dispatchWebhook($claims, 'chunk_upload', ['disk' => $disk, 'path' => $scopedPath, 'name' => basename($scopedPath)]);

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

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }

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
            // Unlike the direct upload() path, S3 multipart has no collision policy at
            // all — completing against an existing key overwrites it unconditionally.
            // Honour owner_only the same way upload()/rename()/move() do before letting
            // the multipart complete replace bytes that already exist at this key.
            if ($this->diskManager->disk($disk)->fileExists($key)) {
                $fm->assertCanModifyScopedPath($disk, $key);
            }

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

            if ($claims->allowVirusScan) {
                throw new ApiException(
                    'Chunked upload cannot be virus-scanned — use the standard upload, or turn off allow_virus_scan',
                    409,
                    'virus_unscannable'
                );
            }

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

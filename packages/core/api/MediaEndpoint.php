<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The two token-authenticated media routes — `GET /api/fm/stream` (gated local /
 * SFTP bytes, Range-capable) and `GET /api/fm/img` (on-demand WebP/AVIF with the
 * preview watermark overlay) — as one framework-neutral class.
 *
 * They used to be nine loose functions in index.php, and the Laravel and
 * WordPress proxies each carried a hand-ported copy (which drifted: unencoded
 * X-Accel paths, no watermark overlay, no `FLUXFILES_*_RATE_LIMIT` env). The
 * class takes the query params and the two request headers it needs as plain
 * arrays, so core (PHP globals), Laravel (Illuminate Request) and WordPress
 * (WP_REST_Request) can all feed it, and it EMITS the response itself — status,
 * headers and body — exactly like the old functions did. It never calls `exit`;
 * the caller exits afterwards (Laravel/WordPress must, or their kernel wraps the
 * void return in a default Response and clobbers Content-Type).
 *
 * Output goes through the protected status()/header()/write()/streamLocal()
 * seams so a host can adapt them (WordPress's `status_header()`) and the unit
 * test can capture a response without a web server.
 *
 * Security checks all live here and are not optional: token type (`t=stream` /
 * `t=img`, verified by StreamToken / ImageToken), a placeholder-secret refusal,
 * the traversal guard on the signed path, the gated-local-or-SFTP disk check,
 * the realpath-inside-root symlink guard, the preview-only "never hand back the
 * clean original" rules for watermarked / no-download tokens, and per-segment
 * X-Accel percent-encoding.
 *
 * Watermark overlay in the proxies: nothing here is core-specific, so once an
 * adapter routes through this class the overlay works there too. What blocks it
 * today is not this code but that the proxies' token minters do not forward the
 * `watermark_enabled` claim (the `wm` scope only exists when it is forwarded) and
 * that they 415 a watermarked token. Forward the claim in step 2 and delete the
 * 415 branch.
 */
class MediaEndpoint
{
    private const PLACEHOLDER_SECRET = 'change-me-to-random-32-char-string';

    private DiskManager $diskManager;
    private string $secret;
    /** @var callable(string,string,int):bool */
    private $rateLimit;
    private string $xaccelBase;
    /** @var MetadataRepositoryInterface|callable|null */
    private $metaRepo;

    /**
     * @param callable(string $sub, string $bucket, int $default):bool $rateLimit
     *        true = proceed; false = the caller is over its limit (this class
     *        then emits the 429). See defaultRateLimit() for core's implementation.
     * @param string $xaccelBase nginx internal location for X-Accel-Redirect
     *        (`FLUXFILES_XACCEL`); '' = stream through PHP.
     * @param MetadataRepositoryInterface|callable|null $metaRepo repo (or a lazy
     *        factory for one) used only for the opt-in `audit_reads` log line.
     */
    public function __construct(
        DiskManager $diskManager,
        string $secret,
        callable $rateLimit,
        string $xaccelBase = '',
        $metaRepo = null
    ) {
        $this->diskManager = $diskManager;
        $this->secret = $secret;
        $this->rateLimit = $rateLimit;
        $this->xaccelBase = $xaccelBase;
        $this->metaRepo = $metaRepo;
    }

    /**
     * Per-subject rate limit for the two token-authenticated media endpoints.
     *
     * `/img` and `/stream` dispatch before the main auth block, so they never reach
     * the general read/write limiter — an unbounded loop over `/img`'s
     * width/height/quality/format/dpr axes can otherwise fill a tenant's
     * `_variants/` with cache entries it can neither see nor purge.
     *
     * Keyed on the token's `sub` (there is no Claims here), in its own bucket so it
     * never eats the tenant's API read budget. Defaults are generous: a gallery
     * page legitimately fires dozens of `/img` requests, and a seeking `<video>`
     * fires one `/stream` Range request per seek.
     *
     * @param callable(string $bucket, int $default):int|null $limitFor per-minute
     *        limit for a bucket (<= 0 disables). Default: env
     *        `FLUXFILES_<BUCKET>_RATE_LIMIT`, else $default.
     * @param callable(int $limit):RateLimiterStorageInterface|null $makeLimiter
     *        limiter factory. Default: RateLimiterFactory::make() (file, or the
     *        env-selected DB backend).
     * @return callable(string,string,int):bool
     */
    public static function defaultRateLimit(?callable $limitFor = null, ?callable $makeLimiter = null): callable
    {
        return static function (string $sub, string $bucket, int $default) use ($limitFor, $makeLimiter): bool {
            $limit = $limitFor !== null
                ? (int) $limitFor($bucket, $default)
                : (int) ($_ENV['FLUXFILES_' . strtoupper($bucket) . '_RATE_LIMIT'] ?? $default);
            if ($limit <= 0) {
                return true; // explicitly disabled by the operator
            }
            try {
                $limiter = $makeLimiter !== null ? $makeLimiter($limit) : RateLimiterFactory::make($limit, $limit, 60);
                $limiter->check($sub !== '' ? $sub : 'anonymous', $bucket);
                return true;
            } catch (ApiException $e) {
                // A limiter that cannot write its own state must not take the endpoint
                // down with it — only a genuine 429 blocks the request.
                return $e->getHttpCode() !== 429;
            }
        };
    }

    /**
     * The `Range` / `Accept` request headers the endpoints read, from a $_SERVER-style array.
     *
     * @param array<string,mixed> $server
     * @return array{range:?string,accept:string}
     */
    public static function headersFromServer(array $server): array
    {
        return [
            'range'  => isset($server['HTTP_RANGE']) ? (string) $server['HTTP_RANGE'] : null,
            'accept' => (string) ($server['HTTP_ACCEPT'] ?? ''),
        ];
    }

    /**
     * The `X-Accel-Redirect` target for a storage key. nginx URL-DECODES this header
     * before matching the internal location, so a raw key is wrong for any name with
     * a `%` (`50%25 off.pdf` resolved to `50% off.pdf` → 404) or a `?`/`#` (cut off
     * as a query/fragment). Each segment is percent-encoded; the `/` separators stay.
     */
    public static function xaccelUri(string $base, string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));
        return rtrim($base, '/') . '/' . implode('/', $segments);
    }

    /**
     * Snap a requested quality to the nearest allowed step (default 80). A fixed set
     * of steps bounds the number of cache variants a single file can spawn.
     *
     * @param mixed $raw
     */
    public static function snapQuality($raw): int
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

    // ── Output seams ────────────────────────────────────────────────────

    protected function status(int $code): void
    {
        http_response_code($code);
    }

    protected function header(string $line, bool $replace = true, int $code = 0): void
    {
        header($line, $replace, $code);
    }

    protected function write(string $bytes): void
    {
        echo $bytes;
    }

    protected function flushOutput(): void
    {
        @flush();
    }

    /** Hand a local file to the Range-capable streamer (emits its own headers/body). */
    protected function streamLocal(string $abs, string $mime, ?string $range): void
    {
        RangeStreamer::stream($abs, $mime, $range);
    }

    /** A plain-text error response: status + text/plain body. */
    private function fail(int $code, string $message): void
    {
        $this->status($code);
        $this->header('Content-Type: text/plain; charset=utf-8');
        $this->write($message);
    }

    private function strParam(array $src, string $key, string $default = ''): string
    {
        $v = $src[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Mirror the main app's guard: a placeholder secret must not verify tokens either. */
    private function secretConfigured(): bool
    {
        if ($this->secret === '' || $this->secret === self::PLACEHOLDER_SECRET) {
            $this->fail(500, 'FLUXFILES_SECRET is not configured');
            return false;
        }
        return true;
    }

    private function rateLimited(string $sub, string $bucket, int $default): bool
    {
        if (($this->rateLimit)($sub, $bucket, $default)) {
            return false;
        }
        $this->fail(429, 'Too many requests');
        return true;
    }

    private static function unsafePath(string $path): bool
    {
        return $path === '' || strpos($path, "\0") !== false
            || (bool) preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path));
    }

    // ── /api/fm/stream ──────────────────────────────────────────────────

    /**
     * Serve one file on a gated (private) local disk, or any SFTP disk (no static
     * URL exists for either), authenticated by a per-file stream token in the query
     * string. Honours HTTP Range so a <video>/<audio> can seek. Emits raw bytes.
     *
     * @param array<string,mixed> $query   the query params (`token`)
     * @param array{range?:?string,accept?:string} $headers
     */
    public function stream(array $query, array $headers = []): void
    {
        if (!$this->secretConfigured()) {
            return;
        }
        try {
            $scope = StreamToken::verify($this->strParam($query, 'token'), $this->secret);
        } catch (ApiException $e) {
            $this->fail($e->getHttpCode(), $e->getMessage());
            return;
        }

        if ($this->rateLimited((string) ($scope['sub'] ?? ''), 'stream', 300)) {
            return;
        }

        $disk = $scope['disk'];
        $path = $scope['path'];

        // Reject any traversal in the (signed) path defensively.
        if (self::unsafePath($path)) {
            $this->fail(403, 'Invalid path');
            return;
        }

        $config = $this->diskManager->config($disk);
        $driver = $config['driver'] ?? '';

        // Two servable cases: a gated (private) local disk, or any SFTP disk (which
        // has no static/presigned URL, so it must be streamed through the app). S3/R2
        // use presigned URLs the browser fetches directly, so they never reach here.
        $isGatedLocal = $driver === 'local' && !empty($config['private']);
        $isSftp = $driver === 'sftp';
        if (!$isGatedLocal && !$isSftp) {
            $this->fail(403, 'Streaming not available for this disk');
            return;
        }

        // MIME by extension (don't trust on-disk sniffing for active types).
        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
        // Inline only for media/image/pdf; everything else is forced to download so a
        // stray .html/.svg can't execute in the FluxFiles origin.
        $inlineOk = (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);
        $this->header('X-Content-Type-Options: nosniff');
        $this->header('Cache-Control: private, no-store');
        $this->header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode(basename($path)) . '"');

        $range = $headers['range'] ?? null;

        if ($isGatedLocal) {
            $root = realpath($config['root'] ?? (__DIR__ . '/../storage/uploads'));
            $abs  = realpath(($root ?: '') . '/' . $path);
            // The resolved file must stay inside the disk root (symlink / traversal guard).
            if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
                $this->fail(404, 'Not found');
                return;
            }
            $this->auditStreamRead($scope, $range);
            // Production fast path: hand the bytes to nginx (native Range, no PHP copy).
            if ($this->xaccelBase !== '') {
                $this->header('Content-Type: ' . $mime);
                $this->header('X-Accel-Buffering: no');
                $this->header('X-Accel-Redirect: ' . self::xaccelUri($this->xaccelBase, $path));
                return;
            }
            $this->streamLocal($abs, $mime, $range);
            return;
        }

        // SFTP: read through Flysystem and stream the bytes (no presign, no static URL).
        // SFTP can't do byte-range natively, so Range is not advertised — the whole
        // file is sent. SFTP is for browsing/editing VPS files, not media seeking.
        try {
            $fs = $this->diskManager->disk($disk);
            if (!$fs->fileExists($path)) {
                $this->fail(404, 'Not found');
                return;
            }
            $this->auditStreamRead($scope, $range);
            $this->header('Content-Type: ' . $mime);
            $stream = $fs->readStream($path);
            while (!feof($stream)) {
                $this->write((string) fread($stream, 8192));
                $this->flushOutput();
            }
            if (is_resource($stream)) { fclose($stream); }
        } catch (\Throwable $e) {
            $this->fail(502, 'Stream failed');
        }
    }

    /**
     * `audit_reads` for /stream: record a `stream_read` when the token says the
     * tenant opted in. Only the FIRST request of a playback (no Range, or a Range
     * starting at byte 0) — every seek of a <video> is its own Range request and
     * would otherwise write one line per scrub. Best-effort, never blocks the bytes.
     *
     * @param array{disk:string,path:string,sub:string,auditRead?:bool} $scope
     */
    private function auditStreamRead(array $scope, ?string $range): void
    {
        if (empty($scope['auditRead'])) {
            return;
        }
        $range = trim((string) $range);
        if ($range !== '' && !preg_match('/^bytes=0-/', $range)) {
            return;
        }
        try {
            $repo = is_callable($this->metaRepo) ? ($this->metaRepo)() : $this->metaRepo;
            if (!$repo instanceof MetadataRepositoryInterface) {
                return;
            }
            (new AuditLogStorage($repo, [$scope['disk']]))
                ->log($scope['sub'], 'stream_read', $scope['disk'], $scope['path']);
        } catch (\Throwable $ignored) {
        }
    }

    // ── /api/fm/img ─────────────────────────────────────────────────────

    /**
     * Serve an on-demand WebP/AVIF transform of one image, cached in the file's
     * _variants/ directory. Authenticated by an image token (query string). The width
     * is rounded to 100px and clamped to the tenant's max so the number of cacheable
     * variants per file is mathematically bounded (no per-request counting needed).
     *
     * @param array<string,mixed> $query   `token`, `width`, `height`, `fit`, `dpr`, `quality`, `format`
     * @param array{range?:?string,accept?:string} $headers `accept` drives format=auto negotiation
     */
    public function image(array $query, array $headers = []): void
    {
        if (!$this->secretConfigured()) {
            return;
        }
        try {
            $scope = ImageToken::verify($this->strParam($query, 'token'), $this->secret);
        } catch (ApiException $e) {
            $this->fail($e->getHttpCode(), $e->getMessage());
            return;
        }

        if ($this->rateLimited((string) ($scope['sub'] ?? ''), 'img', 120)) {
            return;
        }

        $disk = $scope['disk'];
        $path = $scope['path'];
        if (self::unsafePath($path)) {
            $this->fail(403, 'Invalid path');
            return;
        }

        $optimizer = new ImageOptimizer();
        if (!$optimizer->isImage($path)) {
            $this->fail(403, 'Not an image');
            return;
        }

        // DPR: device-pixel-ratio multiplier (1–3, snapped) so a CSS width can be
        // requested while the physical pixels honor a retina screen. Applied to the
        // requested width/height BEFORE clamping to the tenant max.
        $dpr = (float) ($query['dpr'] ?? 1);
        $dpr = $dpr >= 2.5 ? 3.0 : ($dpr >= 1.5 ? 2.0 : 1.0);

        // Width: round to 100px, clamp to the tenant max (mw, default 2000). 0 = keep size.
        $maxWidth = $scope['maxWidth'] > 0 ? $scope['maxWidth'] : 2000;
        $reqWidth = (int) round(((int) ($query['width'] ?? 0)) * $dpr);
        $width = $reqWidth > 0 ? min($maxWidth, max(100, (int) round($reqWidth / 100) * 100)) : 0;
        // Height + fit (box sizing): height rounds to 100px and clamps to the same
        // dimension ceiling; fit 'cover' crops to fill, else 'contain' fits within.
        $reqHeight = (int) round(((int) ($query['height'] ?? 0)) * $dpr);
        $height = $reqHeight > 0 ? min($maxWidth, max(100, (int) round($reqHeight / 100) * 100)) : 0;
        $fit = (($query['fit'] ?? 'contain') === 'cover') ? 'cover' : 'contain';
        $defaultQuality = $scope['defaultQuality'] > 0 ? $scope['defaultQuality'] : 80;
        $quality = self::snapQuality($query['quality'] ?? $defaultQuality);

        // Output format: 'avif'/'webp' force it; 'auto' (default) content-negotiates
        // from Accept — AVIF first (smallest, when the build supports it), then WebP,
        // else '' = serve the original for ancient clients (resolved below).
        $reqFormat = strtolower($this->strParam($query, 'format', 'auto'));
        $avifOk = $optimizer->avifSupported();
        $accept = (string) ($headers['accept'] ?? '');
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
            $this->fail(404, 'Disk not available');
            return;
        }

        if (!$fs->fileExists($path)) {
            $this->fail(404, 'Not found');
            return;
        }

        $origMime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';

        // Resolve the effective watermark from the token (logo presence → text
        // fallback) WITHOUT reading the logo bytes yet — enough for the cache key.
        [$wmEnabled, $wmSigCfg, $logoVer, $wmLogoPath] = $this->resolveWatermark($fs, $scope['watermark'] ?? null, $scope['sub']);
        $wmSig = ImageOptimizer::watermarkSignature($wmSigCfg, $logoVer);

        // A preview-only token (allow_download=false, with or without a watermark)
        // must never reach a path that returns the untransformed source. Watermark
        // implies this, but not the reverse — Claims only forces the implication one
        // way — so check the token's own `dl` flag, not just $wmEnabled.
        $noOriginal = $wmEnabled || !$scope['allowDownload'];

        // Content negotiation: a client that accepts neither AVIF nor WebP → serve
        // the original unchanged (old browsers). NEVER when $noOriginal — that would
        // hand back the clean image; force WebP there. A bare wildcard Accept (curl's
        // default) lands here, so this is a one-request bypass if left open.
        if ($format === '') {
            if (!$noOriginal) {
                $this->serveBytes((string) $fs->read($path), $origMime);
                return;
            }
            $format = 'webp';
        }

        // Cache key is stamped with the source mtime (+ watermark signature, format,
        // height/fit) so a re-upload, config/logo change, or a different output
        // format/size never re-matches a stale image.
        $ver = (string) (@$fs->lastModified($path) ?: '0');
        $cacheKey = ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, $wmSig, $format, $height, $fit);
        $outMime = 'image/' . $format;

        if ($fs->fileExists($cacheKey)) {
            // S3/R2: redirect to a presigned URL of the cached image so the bucket
            // serves the bytes directly (no app-server egress). Local disks read
            // cheaply, so we just serve them.
            if (($this->diskManager->config($disk)['driver'] ?? '') === 's3') {
                $redirect = $this->diskManager->presignGetUrl($disk, $cacheKey, 3600);
                if ($redirect !== null) {
                    $this->header('Cache-Control: private, max-age=600');
                    $this->header('Vary: Accept');
                    $this->header('Location: ' . $redirect, true, 302);
                    return;
                }
            }
            $this->serveBytes((string) $fs->read($cacheKey), $outMime, true);
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

        $out = $optimizer->transform((string) $fs->read($path), $width, $quality, $wmCfg, $format, $height, $fit);
        if ($out === null) {
            // Animated GIF / SVG / non-raster / bomb. A preview-only token must not
            // leak the clean original, so refuse rather than serve it untouched.
            if ($noOriginal) {
                $this->fail(415, $wmEnabled ? 'Cannot watermark this image type' : 'Cannot transform this image type');
                return;
            }
            $this->serveBytes((string) $fs->read($path), $origMime);
            return;
        }

        // Honor the format transform() actually produced (it falls back to WebP if an
        // AVIF encode isn't available at runtime) so the cache key + Content-Type can
        // never disagree with the bytes.
        $outFormat = $out['format'] ?? $format;
        if ($outFormat !== $format) {
            $cacheKey = ImageOptimizer::transformCacheKey($path, $width, $quality, $ver, $wmSig, $outFormat, $height, $fit);
            $outMime = 'image/' . $outFormat;
        }
        try { $fs->write($cacheKey, $out['data']); } catch (\Throwable $e) { /* best-effort cache */ }
        $this->serveBytes($out['data'], $outMime, true);
    }

    /**
     * Resolve a token's watermark into a config usable for the cache key, choosing
     * the logo-vs-text fallback up front (reads only the logo's mtime, not its bytes).
     * Returns [enabled, sigConfig|null, logoVer|null, logoPath]. A missing logo file
     * falls back to a text watermark (warning header) — never to a clean image.
     *
     * @param object $fs a Flysystem filesystem
     * @return array{0:bool,1:array|null,2:int|null,3:string}
     */
    public function resolveWatermark($fs, ?array $wm, string $sub): array
    {
        if (!is_array($wm) || empty($wm['enabled'])) {
            return [false, null, null, ''];
        }
        $position = in_array($wm['position'] ?? '', ImageOptimizer::WM_POSITIONS, true)
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
            $this->header('X-FluxFiles-Warning: watermark-logo-missing');
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
    private function serveBytes(string $data, string $mime, bool $immutable = false): void
    {
        $this->header('Content-Type: ' . $mime);
        $this->header('X-Content-Type-Options: nosniff');
        $this->header('Content-Disposition: inline');
        // Output format is content-negotiated from Accept (format=auto → avif/webp),
        // so caches must key on it to avoid serving the wrong format to a client.
        $this->header('Vary: Accept');
        $this->header($immutable
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : 'Cache-Control: private, no-store');
        $this->header('Content-Length: ' . strlen($data));
        $this->write($data);
    }
}

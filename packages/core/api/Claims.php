<?php

declare(strict_types=1);

namespace FluxFiles;

class Claims
{
    /** @var string */
    public $userId;

    /** @var array */
    public $permissions;

    /** @var array */
    public $allowedDisks;

    /** @var string */
    public $pathPrefix;

    /** @var int */
    public $maxUploadMb;

    /** @var array|null */
    public $allowedExt;

    /** @var int */
    public $maxStorageMb;

    /** @var bool When true, write/delete operations are restricted to files uploaded by this user */
    public $ownerOnly;

    /** @var array<string, array> BYOB disk configs (decrypted) — diskName => config */
    public $byobDisks = [];

    /** @var int Max number of files allowed under the prefix (0 = unlimited) */
    public $maxFiles;

    /** @var bool|null Per-tenant AI auto-tag toggle. null = inherit server default (FLUXFILES_AI_AUTO_TAG). */
    public $aiAutoTag;

    /** @var int Per-tenant read rate limit / minute. 0 = inherit server default. */
    public $rateRead;

    /** @var int Per-tenant write rate limit / minute. 0 = inherit server default. */
    public $rateWrite;

    /** @var array<string,int>|null Per-tenant image variant widths (thumb/medium/large). null = inherit defaults. */
    public $variants;

    // ── URL import (Upload from URL) — opt-in per tenant ──────────────────
    /** @var bool Enable URL import for this tenant. Default false (avoid open-proxy abuse). */
    public bool $allowUrlImport = false;
    /** @var int Max size per import, in MB (consistent with max_upload/max_storage). 0 = inherit server default (50). */
    public int $maxImportMb = 0;
    /** @var string[]|null Host glob allowlist for imports (e.g. ["*.unsplash.com"]). null = any public host. */
    public ?array $importUrlAllowlist = null;
    /** @var string|null Force imports into this path, ignoring the request path. null = use request path. */
    public ?string $importPath = null;
    /** @var int Import requests / minute. 0 = inherit default (10). */
    public int $importRateLimit = 0;
    /** @var int Max concurrent imports. 0 = inherit default (3). */
    public int $importConcurrency = 0;

    // ── Media preview (inline video/audio) ────────────────────────────────
    /** @var bool Enable inline video/audio preview for this tenant. Default true. */
    public bool $mediaPreview = true;
    /** @var int Presigned media-URL TTL (seconds) — longer than the default so a
     *           long video doesn't 403 mid-play. 0 = inherit default (7200). */
    public int $previewUrlTtl = 0;
    /** @var int Max file size (MB) eligible for inline preview; larger media shows
     *           a "too large" placeholder + download. 0 = inherit default (500). */
    public int $maxPreviewMb = 0;
    /** @var int TTL (seconds) for the per-file stream token used by gated local
     *           media. 0 = inherit default (3600). */
    public int $streamTokenTtl = 0;

    // ── On-demand WebP transform ──────────────────────────────────────────
    /** @var bool Expose the on-demand WebP endpoint (`/api/fm/img`) for images. Default true. */
    public bool $webpEnabled = true;
    /** @var int Max resize width (px) a transform request may ask for. 0 = inherit default (2000). */
    public int $webpMaxWidth = 0;
    /** @var int Default WebP quality when a request omits it. 0 = inherit default (80). */
    public int $webpDefaultQuality = 0;

    /** @var int[] Responsive `srcset` width ladder (px). Snapped to 100px and
     *            clamped to webp_max_width; list() emits these as `img_srcset`
     *            on image entries that already get an `img_base`. */
    public array $srcsetWidths = [320, 640, 768, 1024, 1366, 1920];
    /** @var string Optional `sizes` attribute, surfaced as `img_sizes` when set
     *            (empty = omit; the host can supply its own `sizes`). */
    public string $srcsetSizes = '';

    // ── Zip / extract ─────────────────────────────────────────────────────
    /** @var bool May this token download a multi-file/-folder selection as a zip
     *            (`POST /api/fm/zip`)? Default true (also requires allow_download). */
    public bool $allowZip = true;
    /** @var bool May this token extract a zip in place (`POST /api/fm/extract`)?
     *            Default true (write perm + ext/dangerous-ext policy still apply). */
    public bool $allowExtract = true;
    /** @var int Max total uncompressed size (MB) for a zip/extract. 0 = inherit (1024). */
    public int $zipMaxMb = 0;
    /** @var int Max file count for a zip/extract. 0 = inherit (10000). */
    public int $zipMaxFiles = 0;

    /** @var bool May this token get clean original download URLs? Default true. When
     *            false (preview-only / watermark), list() withholds `url`/
     *            `permanent_url`/`variants` and GET presign is denied — only the
     *            (watermarked) `img_base` remains for images. */
    public bool $allowDownload = true;

    /** @var bool May this token change Unix file permissions (chmod) on an SFTP
     *            disk? Default true (part of the SFTP file-manager toolkit). */
    public bool $allowChmod = true;

    /** @var bool May this token edit a file's text content via /api/fm/content?
     *            Default FALSE — editing config/executable files (wp-config.php,
     *            .env, nginx.conf, deploy.sh) is powerful, so it's opt-in. */
    public bool $allowCodeEdit = false;

    /** @var bool May this token use the (paid) Optimization module (/api/fm/optimize)?
     *            Default FALSE — opt-in. Even when true, the module's code must be
     *            installed and the license must cover it (the 3-layer gate). */
    public bool $allowOptimize = false;

    /** @var bool Auto-optimize images on upload (recompress to WebP in the pipeline)?
     *            Default FALSE. Like allow_optimize, only effective when the module
     *            is installed + licensed (the core wires the upload hook then). */
    public bool $autoOptimize = false;
    /** @var int WebP quality for optimization (40–95). 0 = inherit default (82). */
    public int $optimizeQuality = 0;
    /** @var string Target format for optimization: 'webp' (default) or 'avif'.
     *            AVIF falls back to WebP when the server build lacks AVIF support. */
    public string $optimizeFormat = 'webp';
    /** @var bool Keep the original file when optimizing via /api/fm/optimize (the
     *            request body can still override). Default FALSE (replace in place). */
    public bool $optimizeKeepOriginal = false;
    /** @var int Skip optimizing a file larger than this many MB (0 = no limit). */
    public int $optimizeMaxMb = 0;
    /** @var string Ghostscript preset for PDF optimization:
     *            screen|ebook|printer|prepress|default. Default 'ebook'. */
    public string $pdfLevel = 'ebook';

    /** @var string How an upload whose NAME collides with an existing different file
     *            (content dedup is separate, by hash) is handled:
     *              'rename'    — keep both; write `<name>-1.<ext>`, `-2`… (default, like
     *                            Google Drive / Dropbox / WordPress).
     *              'overwrite' — replace the existing file in place.
     *              'reject'    — refuse with 409 so the host can prompt the user.
     *            Invalid values fall back to 'rename'. */
    public string $uploadCollision = 'rename';

    /** @var bool Show dotfiles (names starting with '.', e.g. .env / .gitignore) in
     *            listings AND search? Default FALSE — dotfiles are hidden, matching
     *            Finder / cPanel / Nextcloud (a `.env` must not surface in search). */
    public bool $showHidden = false;

    /** @var array<string,mixed>|null Assembled, sanitized watermark config (null = off).
     *            Keys: enabled, type, text, logo_path, position, opacity, font_size.
     *            Applied on the fly by the /api/fm/img endpoint; the source is untouched. */
    public ?array $watermark = null;

    // ── Usage dashboard (/api/fm/usage) ───────────────────────────────────
    /** @var int Usage-summary cache TTL (seconds). 0 = inherit default (900). */
    public int $usageCacheTtl = 0;
    /** @var int Percent at which quota status becomes "warning". 0 = inherit (70). */
    public int $usageWarningThreshold = 0;
    /** @var int Percent at which quota status becomes "critical". 0 = inherit (90). */
    public int $usageCriticalThreshold = 0;
    /** @var int Number of largest folders to return. 0 = inherit (10). */
    public int $usageTopFoldersCount = 0;
    /** @var int Folder grouping depth for the breakdown. 0 = inherit (1). */
    public int $usageFolderDepth = 0;

    public function __construct(
        string $userId,
        array $permissions,
        array $allowedDisks,
        string $pathPrefix,
        int $maxUploadMb,
        ?array $allowedExt,
        int $maxStorageMb,
        bool $ownerOnly = false,
        array $byobDisks = [],
        int $maxFiles = 0,
        ?bool $aiAutoTag = null,
        int $rateRead = 0,
        int $rateWrite = 0,
        ?array $variants = null
    ) {
        $this->userId = $userId;
        $this->permissions = $permissions;
        $this->allowedDisks = $allowedDisks;
        $this->pathPrefix = $pathPrefix;
        $this->maxUploadMb = $maxUploadMb;
        $this->allowedExt = $allowedExt;
        $this->maxStorageMb = $maxStorageMb;
        $this->ownerOnly = $ownerOnly;
        $this->byobDisks = $byobDisks;
        $this->maxFiles = $maxFiles;
        $this->aiAutoTag = $aiAutoTag;
        $this->rateRead = max(0, $rateRead);
        $this->rateWrite = max(0, $rateWrite);
        $this->variants = $variants;
    }

    /**
     * Sanitize a per-tenant `variants` claim: keep only the known size names with
     * sane positive widths. Returns null when nothing usable remains (= inherit).
     *
     * @return array<string,int>|null
     */
    public static function sanitizeVariants($raw): ?array
    {
        if (!is_array($raw) && !is_object($raw)) {
            return null;
        }
        $out = [];
        foreach (['thumb', 'medium', 'large'] as $name) {
            $v = is_object($raw) ? ($raw->$name ?? null) : ($raw[$name] ?? null);
            if ($v === null) {
                continue;
            }
            $w = (int) $v;
            if ($w >= 16 && $w <= 8000) {
                $out[$name] = $w;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * Assemble + clamp the per-tenant watermark config from `watermark_*` claims.
     * Returns null when disabled. Done at decode so the serve endpoint can trust it.
     *
     * @return array<string,mixed>|null
     */
    public static function sanitizeWatermark(object $payload): ?array
    {
        if (empty($payload->watermark_enabled)) {
            return null;
        }
        $type = ($payload->watermark_type ?? 'text') === 'logo' ? 'logo' : 'text';
        $position = in_array($payload->watermark_position ?? '', ImageOptimizer::WM_POSITIONS, true)
            ? (string) $payload->watermark_position : 'bottom-right';
        return [
            'enabled'   => true,
            'type'      => $type,
            'text'      => (string) ($payload->watermark_text ?? ''),
            'logo_path' => (string) ($payload->watermark_logo_path ?? ''),
            'position'  => $position,
            'opacity'   => max(0.0, min(1.0, (float) ($payload->watermark_opacity ?? 0.6))),
            'font_size' => max(8, min(200, (int) ($payload->watermark_font_size ?? 24))),
        ];
    }

    /**
     * Normalize a responsive `srcset` width ladder: positive ints only, snapped to
     * 100px (the /api/fm/img cache grain) and clamped to webp_max_width (default
     * 2000), deduped, sorted ascending, capped at 12. A missing/empty/all-invalid
     * ladder falls back to the default (itself clamped to the max).
     *
     * @param mixed $raw
     * @return int[]
     */
    public static function sanitizeSrcsetWidths($raw, int $webpMaxWidth): array
    {
        $default = [320, 640, 768, 1024, 1366, 1920];
        $max = $webpMaxWidth > 0 ? $webpMaxWidth : 2000;
        $snap = static fn (int $w): int => min($max, max(100, (int) round($w / 100) * 100));

        $clean = [];
        foreach ((is_array($raw) ? $raw : []) as $w) {
            if (is_int($w) || (is_string($w) && ctype_digit($w))) {
                $w = (int) $w;
                if ($w > 0) {
                    $clean[$snap($w)] = true;
                }
            }
        }
        if ($clean === []) {
            foreach ($default as $w) {
                $clean[$snap($w)] = true;
            }
        }
        $clean = array_keys($clean);
        sort($clean);
        return array_slice($clean, 0, 12);
    }

    /**
     * @param object $payload JWT payload
     * @param string $secret  FLUXFILES_SECRET (needed to decrypt BYOB credentials)
     */
    public static function fromJwtPayload(object $payload, string $secret = ''): self
    {
        // Decrypt BYOB disk credentials if present
        $byobDisks = [];
        if (isset($payload->byob_disks) && is_object($payload->byob_disks) && $secret !== '') {
            foreach ($payload->byob_disks as $diskName => $encryptedBlob) {
                $config = CredentialEncryptor::decrypt((string) $encryptedBlob, $secret);
                CredentialEncryptor::validate((string) $diskName, $config);
                $byobDisks[(string) $diskName] = $config;
            }
        }

        $c = new self(
            (string) ($payload->sub ?? '0'),
            (array) ($payload->perms ?? ['read']),
            (array) ($payload->disks ?? ['local']),
            (string) ($payload->prefix ?? ''),
            (int) ($payload->max_upload ?? 10),
            isset($payload->allowed_ext) ? (array) $payload->allowed_ext : null,
            (int) ($payload->max_storage ?? 0),
            (bool) ($payload->owner_only ?? false),
            $byobDisks,
            (int) ($payload->max_files ?? 0),
            isset($payload->ai_auto_tag) ? (bool) $payload->ai_auto_tag : null,
            (int) ($payload->rate_read ?? 0),
            (int) ($payload->rate_write ?? 0),
            isset($payload->variants) ? self::sanitizeVariants($payload->variants) : null
        );

        // URL-import claims (set on the instance so the constructor signature stays
        // stable for positional callers / adapters).
        $c->allowUrlImport = (bool) ($payload->allow_url_import ?? false);
        $c->maxImportMb = max(0, (int) ($payload->max_import_mb ?? 0));
        if (isset($payload->import_url_allowlist)) {
            $list = array_values(array_filter(array_map(
                static fn ($h) => strtolower(trim((string) $h)),
                (array) $payload->import_url_allowlist
            )));
            $c->importUrlAllowlist = $list === [] ? null : $list;
        }
        $c->importPath = isset($payload->import_path) ? (string) $payload->import_path : null;
        $c->importRateLimit = max(0, (int) ($payload->import_rate_limit ?? 0));
        $c->importConcurrency = max(0, (int) ($payload->import_concurrency ?? 0));

        // Media-preview claims.
        $c->mediaPreview = isset($payload->media_preview) ? (bool) $payload->media_preview : true;
        $c->previewUrlTtl = max(0, (int) ($payload->preview_url_ttl ?? 0));
        $c->maxPreviewMb = max(0, (int) ($payload->max_preview_mb ?? 0));
        $c->streamTokenTtl = max(0, (int) ($payload->stream_token_ttl ?? 0));

        // On-demand WebP claims.
        $c->webpEnabled = isset($payload->webp_enabled) ? (bool) $payload->webp_enabled : true;
        $c->webpMaxWidth = max(0, (int) ($payload->webp_max_width ?? 0));
        $c->webpDefaultQuality = max(0, (int) ($payload->webp_default_quality ?? 0));
        $c->srcsetWidths = self::sanitizeSrcsetWidths($payload->srcset_widths ?? null, $c->webpMaxWidth);
        $c->srcsetSizes = is_string($payload->srcset_sizes ?? null) ? trim((string) $payload->srcset_sizes) : '';
        $c->allowZip = isset($payload->allow_zip) ? (bool) $payload->allow_zip : true;
        $c->allowExtract = isset($payload->allow_extract) ? (bool) $payload->allow_extract : true;
        $c->zipMaxMb = max(0, (int) ($payload->zip_max_mb ?? 0));
        $c->zipMaxFiles = max(0, (int) ($payload->zip_max_files ?? 0));
        $c->allowDownload = isset($payload->allow_download) ? (bool) $payload->allow_download : true;
        $c->allowChmod = isset($payload->allow_chmod) ? (bool) $payload->allow_chmod : true;
        $c->allowCodeEdit = (bool) ($payload->allow_code_edit ?? false);
        $c->allowOptimize = (bool) ($payload->allow_optimize ?? false);
        $c->autoOptimize = (bool) ($payload->auto_optimize ?? false);
        $c->optimizeQuality = max(0, min(95, (int) ($payload->optimize_quality ?? 0)));
        $c->optimizeFormat = (($payload->optimize_format ?? 'webp') === 'avif') ? 'avif' : 'webp';
        $c->optimizeKeepOriginal = (bool) ($payload->optimize_keep_original ?? false);
        $c->optimizeMaxMb = max(0, (int) ($payload->optimize_max_mb ?? 0));
        $pdfLevel = strtolower((string) ($payload->pdf_level ?? 'ebook'));
        $c->pdfLevel = in_array($pdfLevel, ['screen', 'ebook', 'printer', 'prepress', 'default'], true) ? $pdfLevel : 'ebook';
        $collision = strtolower((string) ($payload->upload_collision ?? 'rename'));
        $c->uploadCollision = in_array($collision, ['rename', 'overwrite', 'reject'], true) ? $collision : 'rename';
        $c->showHidden = (bool) ($payload->show_hidden ?? false);
        $c->watermark = self::sanitizeWatermark($payload);

        // Usage-dashboard claims.
        $c->usageCacheTtl = max(0, (int) ($payload->usage_cache_ttl ?? 0));
        $c->usageWarningThreshold = max(0, min(100, (int) ($payload->usage_warning_threshold ?? 0)));
        $c->usageCriticalThreshold = max(0, min(100, (int) ($payload->usage_critical_threshold ?? 0)));
        $c->usageTopFoldersCount = max(0, (int) ($payload->usage_top_folders_count ?? 0));
        $c->usageFolderDepth = max(0, (int) ($payload->usage_folder_depth ?? 0));

        return $c;
    }

    /**
     * Is this a previewable media file (video/audio)? Mirrors the frontend
     * `isPreviewable()` extension list. Used to grant media files a longer
     * presigned-URL TTL so playback doesn't expire mid-stream.
     */
    public static function isMediaPath(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'webm', 'mov', 'mp3', 'wav', 'ogg', 'flac', 'aac'], true);
    }

    /**
     * Check if a disk is a BYOB (user-provided) disk.
     */
    public function isByobDisk(string $disk): bool
    {
        return isset($this->byobDisks[$disk]);
    }

    /**
     * Get the decrypted config for a BYOB disk.
     */
    public function getByobConfig(string $disk): ?array
    {
        return $this->byobDisks[$disk] ?? null;
    }

    public function hasPerm(string $perm): bool
    {
        return in_array($perm, $this->permissions, true);
    }

    public function hasDisk(string $disk): bool
    {
        return in_array($disk, $this->allowedDisks, true);
    }

    /**
     * Generic check for a named `allow_*` feature claim — used by ModuleRegistry to
     * gate a paid module by its declared claim without hard-coding the property.
     * Unknown claim names are denied (fail-closed). Add a case when a module needs one.
     */
    public function isAllowed(string $claim): bool
    {
        switch ($claim) {
            case 'allow_optimize':   return $this->allowOptimize;
            case 'allow_code_edit':  return $this->allowCodeEdit;
            case 'allow_zip':        return $this->allowZip;
            case 'allow_extract':    return $this->allowExtract;
            case 'allow_chmod':      return $this->allowChmod;
            case 'allow_download':   return $this->allowDownload;
            default:                 return false;
        }
    }

    /**
     * Check if a path is within the user's allowed scope (pathPrefix).
     */
    public function isPathInScope(string $path): bool
    {
        $prefix = trim($this->pathPrefix, '/');
        if ($prefix === '') {
            return true;
        }
        $path = trim(str_replace(["\0", "\x00"], '', $path), '/');
        return $path === $prefix || strpos($path, $prefix . '/') === 0;
    }

    /**
     * Apply path prefix and normalize (remove .. and .).
     */
    public function scopePath(string $path): string
    {
        $path = str_replace(["\0", "\x00"], '', $path);
        $parts = explode('/', $path);
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                continue;
            }
            if ($part !== '') {
                $safe[] = $part;
            }
        }
        $relative = implode('/', $safe);
        $prefix = trim($this->pathPrefix, '/');
        if ($prefix !== '') {
            // Idempotent prefixing — see FileManager::scopedPath(). A path already
            // inside the prefix is returned unchanged (`..`/`.` stripped above), and
            // the "/" boundary keeps prefix confusion (user_1 vs user_10) out.
            if ($relative === $prefix || strpos($relative, $prefix . '/') === 0) {
                return $relative;
            }
            // Fail closed on a cross-tenant path that targets a sibling under the
            // prefix's parent (e.g. prefix "users/42" + path "users/99/…").
            $parent = (strpos($prefix, '/') !== false) ? substr($prefix, 0, strrpos($prefix, '/')) : '';
            if ($parent !== '' && strpos($relative, $parent . '/') === 0) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }
            return $relative === '' ? $prefix : $prefix . '/' . $relative;
        }
        return $relative;
    }

    /**
     * Inverse of scopePath: strip the tenant prefix from an absolute storage key
     * so the client sees paths relative to ITS root (the prefix is an internal
     * sandbox detail and must stay invisible — the prefix IS the tenant's root).
     *
     * Idempotent: a key that is already relative (not under the prefix) is
     * returned unchanged. The prefix root itself maps to '' (the relative root).
     * URLs are NOT run through this — they must keep the real storage path.
     */
    public function unscopePath(string $key): string
    {
        $prefix = trim($this->pathPrefix, '/');
        if ($prefix === '') {
            return ltrim($key, '/');
        }
        $key = ltrim(str_replace(["\0", "\x00"], '', $key), '/');
        if ($key === $prefix) {
            return '';
        }
        if (strpos($key, $prefix . '/') === 0) {
            return substr($key, strlen($prefix) + 1);
        }
        // Already relative (or outside the prefix — shouldn't happen for scoped
        // storage ops): leave as-is rather than corrupt it.
        return $key;
    }
}

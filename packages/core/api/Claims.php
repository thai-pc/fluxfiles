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

    /** @var bool May this token open a shell terminal on an SFTP/SSH disk?
     *            Default FALSE — this grants shell access as the SSH user, so it
     *            must be opted in deliberately (and only makes sense on an SFTP
     *            disk whose host actually allows a shell). */
    public bool $allowTerminal = false;

    /** @var string Optional URL of a self-hosted PTY terminal server (ttyd / gotty /
     *            wetty, or any PTY-over-WebSocket service the operator runs on their
     *            own box). When set (and `allow_terminal` is on), the UI embeds this
     *            for a true interactive terminal (vim/top/htop) instead of the
     *            built-in command-runner. Empty (default) → the stateless
     *            command-runner. Free feature — opt-in config, never gated by a
     *            license. Must be http(s) (rejected otherwise). */
    public string $terminalPtyUrl = '';

    /** @var string Optional URL of a self-hosted PDF-tools server (Stirling-PDF, or any
     *            web PDF toolkit the operator runs on their own box). When set, the UI
     *            shows a "PDF tools" action that embeds it (merge/split/OCR/convert/…)
     *            instead of FluxFiles building a competitor. Empty (default) → no button.
     *            Free feature — opt-in config, never gated by a license. Must be http(s). */
    public string $pdfToolsUrl = '';

    /** @var string Optional URL of a self-hosted office suite (Collabora / OnlyOffice)
     *            for opening .docx/.xlsx/.pptx… An office suite opens a SPECIFIC
     *            document, so this may carry a `{url}` placeholder that the UI
     *            substitutes with the selected file's URL (the operator's page does the
     *            WOPI/editor wiring). Empty (default) → no "Open in Office" action.
     *            Free feature — opt-in config, never gated by a license. Must be http(s). */
    public string $officeUrl = '';

    /** @var string Optional URL of a self-hosted e-signature service (DocuSeal, or any
     *            web e-sign tool) for signing PDFs/documents. May carry a `{url}`
     *            placeholder that the UI substitutes with the selected file's URL — the
     *            operator's page builds the signing request. Empty (default) → no "Sign"
     *            action. Free feature — opt-in config, never gated by a license. http(s). */
    public string $esignUrl = '';

    /** @var bool May this token edit a file's text content via /api/fm/content?
     *            Default FALSE — editing config/executable files (wp-config.php,
     *            .env, nginx.conf, deploy.sh) is powerful, so it's opt-in. */
    public bool $allowCodeEdit = false;

    // ── One-click Git deploy (SSH exec) ───────────────────────────────────
    // See docs/GIT-DEPLOY-SECURITY-REVIEW.md. Deliberately narrower than
    // allow_terminal: the repo path/branch are OPERATOR claims baked in at mint
    // time, never accepted from the request body — a client can trigger a deploy,
    // never redirect it. Never bundle this with allow_sftp/allow_terminal.
    /** @var bool May this token trigger a Git deploy on an SFTP disk
     *            (`POST /api/fm/git-deploy`)? Default FALSE — separate claim,
     *            never implied by allow_sftp or allow_terminal. */
    public bool $allowGitDeploy = false;
    /** @var string Repo path on the SFTP disk (relative to the disk root, or
     *            absolute) that a deploy syncs. Required when allow_git_deploy is
     *            true — an empty path 400s the route rather than guessing. */
    public string $gitDeployPath = '';
    /** @var string Branch to force-sync to via `fetch --prune` + `reset --hard
     *            origin/<branch>` (destructive but deterministic). Empty (default)
     *            → `git pull --ff-only` on whatever branch is checked out instead
     *            (safe: refuses on divergence, never rewrites history). */
    public string $gitDeployBranch = '';
    /** @var bool Let the deployed repo's Git hooks (post-merge, etc.) run.
     *            Default FALSE: hooks are neutered (`core.hooksPath=/dev/null`)
     *            because a hostile hook in the repo is otherwise arbitrary code
     *            execution on the VPS, independent of any FluxFiles bug. */
    public bool $gitDeployHooks = false;

    /** @var bool May this token use the (paid) Optimization module (/api/fm/optimize)?
     *            Default FALSE — opt-in. Even when true, the module's code must be
     *            installed and the license must cover it (the 3-layer gate). */
    public bool $allowOptimize = false;

    /** @var bool Paid module claims (3-layer gate — code installed + licensed + this
     *            claim). All default FALSE (opt-in). See ModuleRegistry. */
    public bool $allowShare = false;       // Branded Share links (fluxfiles/share)
    public bool $allowIntake = false;      // Intake / upload portals (fluxfiles/intake)
    public bool $allowVersioning = false;  // File version history (fluxfiles/versioning)
    public bool $allowWebhooks = false;    // Signed HTTP events on file changes (fluxfiles/webhooks)
    public bool $allowAiVision = false;    // AI Vision: bg-removal/upscale/smart-crop (fluxfiles/ai)
    public bool $allowOcr = false;         // OCR / document text extraction (fluxfiles/ocr)
    public bool $allowVirusScan = false;   // Virus/malware scan on upload (fluxfiles/virus)
    public bool $allowBackup = false;      // Backup Bridge: SFTP↔S3 sync (fluxfiles/backup)
    public bool $allowC2pa = false;        // C2PA content provenance (fluxfiles/c2pa)
    public bool $allowAuditExport = false; // Audit log export/purge (fluxfiles/audit-export)
    public bool $allowDlpScan = false;     // DLP/PII detection on write (fluxfiles/dlp)
    public bool $allowLegalHold = false;   // Legal hold / retention — place/release only (fluxfiles/legal-hold)

    // ── DLP / PII detection-on-write (Enterprise bundle) ──────────────────
    // See docs/DLP-PII-REDACTION-DESIGN.md. Mirrors allow_virus_scan's shape: a
    // 3-layer-gated module + a fail-closed FileManager hook. The eligibility
    // pre-filter (extension + size cap) below is checked in CORE, before the
    // module is ever invoked, so an engine outage never blocks a non-text upload.
    /** @var string[]|null Allowlist of Presidio entity-type names that trigger a
     *            block (e.g. ["US_SSN","CREDIT_CARD"]). null = the engine's full
     *            default detection set (unfiltered) — matches the existing
     *            `allowed_ext: null = broadest` precedent rather than a hidden
     *            curated default. Sanitized: uppercased/trimmed, must match
     *            `^[A-Z_]+$`, else dropped. */
    public ?array $dlpEntityTypes = null;
    /** @var string[] v1 scan-eligibility allowlist — extensions outside this list
     *            are skipped, not blocked, even with allow_dlp_scan on. Lowercase,
     *            no dot, alnum only. Defaults to a built-in text-bearing list when
     *            the claim is absent/empty. */
    public array $dlpScanExtensions = [];
    /** @var int Per-file cap (KB) on bytes read and sent to the engine. A file over
     *            this cap is skipped, not blocked. Clamped [16, 51200]. 0/absent
     *            → default 2048 (2 MB). */
    public int $dlpMaxScanKb = 2048;
    /** @var float Minimum Presidio confidence score (0–1) an entity match must
     *            reach to count as "detected". Clamped [0, 1]. 0/absent → default
     *            0.6 (same "0 means unset, use default" convention as versioningMax). */
    public float $dlpMinScore = 0.6;

    /** Built-in text-bearing extension allowlist used when `dlp_scan_extensions`
     *  is absent/empty (§3 of the design doc). */
    public const DLP_DEFAULT_SCAN_EXTENSIONS = [
        'txt', 'csv', 'tsv', 'json', 'ndjson', 'log', 'md', 'yaml', 'yml', 'xml', 'html', 'htm', 'sql',
    ];

    /** @var int Days of audit history a purge call should keep, resolved server-side
     *           by the /api/fm/audit/purge route when the request body omits `before`.
     *           0 = no default cutoff (the route requires an explicit `before` then). */
    public int $auditRetentionDays = 0;

    // ── Share landing page (read at create time, baked into the share record) ──
    /** @var int TTL (seconds) of the presigned S3/R2 URL a share download redirects
     *           to. Clamped to [10, 300]; bounds the "a handed-out presigned URL
     *           stays fetchable" window (on S3/R2 the cap counts grants, not GETs). */
    public int $shareUrlTtl = 60;
    /** @var string Public base the create response builds the recipient link from
     *            (e.g. https://files.acme.com/public/share.html). http(s) only —
     *            anything else is dropped so it can never become a javascript: link.
     *            Empty (default) → the request origin + /public/share.html. */
    public string $shareBaseUrl = '';
    /** @var bool May the landing page render an inline preview (images via
     *            /api/fm/img, PDFs on uncapped shares)? false = download-only. */
    public bool $sharePreview = true;
    /** @var bool Log a per-event view/download/unlock_fail record (timestamp + IP +
     *            user-agent) for a share, alongside the existing aggregate counters.
     *            Off by default — persists visitor IPs (privacy/compliance footprint).
     *            Baked into the share record at create time, like sharePreview. */
    public bool $shareAnalytics = false;
    /** @var array{name:string,logo_url:string,color:string,link_url:string}|null
     *            Sanitized Share brand config (null = no branding). Baked into
     *            the share record at create time (ShareModule::createShare) —
     *            a public request has no claims to read live. */
    public ?array $shareBrand = null;

    /** @var string Public base the intake create response builds the portal link from
     *            (e.g. https://files.acme.com/public/intake.html). http(s) only —
     *            anything else is dropped so it can never become a javascript: link.
     *            Empty (default) → the request origin + /public/intake.html.
     *            Mirrors shareBaseUrl. */
    public string $intakeBaseUrl = '';

    /** @var array{name:string,logo_url:string,color:string,link_url:string}|null
     *            Sanitized Intake brand config (null = no branding). Baked into
     *            the portal record at create time (IntakeModule::createPortal) —
     *            a public request has no claims to read live. Mirrors shareBrand. */
    public ?array $intakeBrand = null;

    /** @var bool Log a per-event received/rejected record (timestamp + IP +
     *            user-agent + filename + rejection reason) for a portal,
     *            alongside the existing aggregate `received`/`rejected`
     *            counters. Off by default — persists visitor IPs (privacy/
     *            compliance footprint). Baked into the portal record at create
     *            time, like intakeBrand. */
    public bool $intakeAnalytics = false;

    /** @var int Max prior versions to keep per file (Versioning module). 0 = inherit
     *            the module default (10). Oldest beyond this are pruned on each snapshot. */
    public int $versioningMax = 0;
    /** @var int Skip versioning a file larger than this many MB (0 = module default, 25).
     *            Avoids snapshotting huge videos on every overwrite. */
    public int $versioningMaxMb = 0;

    /** @var string Webhook endpoint URL (Webhooks module) — signed HTTP POST fired on
     *            file events. '' = no webhook. http(s) only (validated on decode). */
    public string $webhookUrl = '';
    /** @var string[] Event filter — only these events fire the webhook. [] = all events. */
    public array $webhookEvents = [];
    /** @var string Secret used to HMAC-sign the webhook payload (X-FluxFiles-Signature).
     *            '' = fall back to FLUXFILES_SECRET server-side. */
    public string $webhookSecret = '';

    /** @var bool Auto-optimize images on upload (recompress to WebP in the pipeline)?
     *            Default FALSE. Like allow_optimize, only effective when the module
     *            is installed + licensed (the core wires the upload hook then). */
    public bool $autoOptimize = false;
    /** @var int WebP quality for optimization (40–95). 0 = inherit default (82). */
    public int $optimizeQuality = 0;
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

    /** @var bool Show the locked "Pro" affordance for a paid module this token may not
     *            use? **UI-only — no endpoint behaviour depends on it.** The affordance
     *            is already bounded to an *unlicensed* server viewed *unframed* (the
     *            standalone/Docker evaluation), so the default TRUE cannot put an upsell
     *            inside a paying operator's product. FALSE is the hard off switch, for
     *            operators shipping the free core in production. */
    public bool $proHints = true;

    /** @var bool Block re-uploading identical CONTENT (SHA-256 dedup)? Default FALSE
     *            — like Finder / Google Drive / Dropbox, an identical upload is kept
     *            as a copy (via the upload_collision policy), not refused. Set TRUE to
     *            save storage by refusing byte-for-byte duplicates. */
    public bool $dedupeUploads = false;

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
     * Assemble a per-tenant "brand" config, shared by Share (`share_brand_*`) and
     * Intake (`intake_brand_*`) — the two are byte-for-byte identical apart from
     * the claim prefix. Returns null when every field is empty — no explicit
     * enabled flag needed (unlike watermark) since this is cosmetic display data
     * with no serve-time cost. Done at decode so {Share,Intake}Module::create*()
     * can bake a sanitized copy into the record.
     *
     * The per-prefix reads below are literal property accesses on $payload
     * (not a dynamic lookup built from $prefix) so the config-doc coverage
     * guard (tests/unit/test-config-doc.php, which greps this file for a
     * literal `$payload->` property access) keeps catching every claim this
     * reads.
     *
     * @param string $prefix 'share_brand' or 'intake_brand'
     * @return array{name:string,logo_url:string,color:string,link_url:string}|null
     */
    private static function sanitizeBrandFields(object $payload, string $prefix): ?array
    {
        if ($prefix === 'share_brand') {
            $rawName = $payload->share_brand_name ?? '';
            $rawLogo = $payload->share_brand_logo_url ?? '';
            $rawColor = $payload->share_brand_color ?? '';
            $rawLink = $payload->share_brand_link_url ?? '';
        } else {
            $rawName = $payload->intake_brand_name ?? '';
            $rawLogo = $payload->intake_brand_logo_url ?? '';
            $rawColor = $payload->intake_brand_color ?? '';
            $rawLink = $payload->intake_brand_link_url ?? '';
        }
        $name = mb_substr(trim((string) $rawName), 0, 80);
        $logo = trim((string) $rawLogo);
        $logo = preg_match('#^https?://#i', $logo) ? $logo : '';
        $color = trim((string) $rawColor);
        $color = preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? $color : '';
        $link = trim((string) $rawLink);
        $link = preg_match('#^https?://#i', $link) ? $link : '';
        if ($name === '' && $logo === '' && $color === '' && $link === '') {
            return null;
        }
        return ['name' => $name, 'logo_url' => $logo, 'color' => $color, 'link_url' => $link];
    }

    /**
     * Assemble the per-tenant Share "brand" config from `share_brand_*` claims.
     * See sanitizeBrandFields() — zero behavior change from before the refactor.
     *
     * @return array{name:string,logo_url:string,color:string,link_url:string}|null
     */
    public static function sanitizeShareBrand(object $payload): ?array
    {
        return self::sanitizeBrandFields($payload, 'share_brand');
    }

    /**
     * Assemble the per-tenant Intake "brand" config from `intake_brand_*` claims.
     * See sanitizeBrandFields() — identical shape to sanitizeShareBrand().
     *
     * @return array{name:string,logo_url:string,color:string,link_url:string}|null
     */
    public static function sanitizeIntakeBrand(object $payload): ?array
    {
        return self::sanitizeBrandFields($payload, 'intake_brand');
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
     * Sanitize `dlp_entity_types`: uppercase/trim each entry, keep only ones matching
     * Presidio's entity-type naming convention (`^[A-Z_]+$`), drop the rest. Returns
     * null when nothing usable remains — null means "engine's full default set" (§3
     * of docs/DLP-PII-REDACTION-DESIGN.md), so an all-garbage input is NOT the same
     * as an empty allowlist (which would block nothing).
     *
     * @return string[]|null
     */
    public static function sanitizeDlpEntityTypes($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $v) {
            $t = strtoupper(trim((string) $v));
            if ($t !== '' && preg_match('/^[A-Z_]+$/', $t)) {
                $out[$t] = true;
            }
        }
        return $out === [] ? null : array_keys($out);
    }

    /**
     * Sanitize `dlp_scan_extensions`: lowercase, strip a leading dot, alnum only —
     * same normalization as `allowed_ext`. Falls back to the built-in text-bearing
     * default list when the claim is absent or nothing usable remains.
     *
     * @return string[]
     */
    public static function sanitizeDlpScanExtensions($raw): array
    {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $e = strtolower(ltrim(trim((string) $v), '.'));
                if ($e !== '' && ctype_alnum($e)) {
                    $out[$e] = true;
                }
            }
        }
        return $out === [] ? self::DLP_DEFAULT_SCAN_EXTENSIONS : array_keys($out);
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
                $pinnedIp = CredentialEncryptor::validate((string) $diskName, $config);
                if ($pinnedIp !== null) {
                    // See CredentialEncryptor::validate() — closes the DNS-rebinding
                    // TOCTOU between this check and DiskManager's actual S3 connect.
                    $config['_pinned_ip'] = $pinnedIp;
                }
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
        $c->allowTerminal = isset($payload->allow_terminal) ? (bool) $payload->allow_terminal : false;
        // Only accept an http(s) PTY URL — anything else (javascript:, data:, …) is
        // dropped so it can never reach an iframe src.
        $ptyUrl = trim((string) ($payload->terminal_pty_url ?? ''));
        $c->terminalPtyUrl = preg_match('#^https?://#i', $ptyUrl) ? $ptyUrl : '';
        // PDF-tools embed URL — http(s) only (so it can never reach a non-HTTP scheme).
        $pdfUrl = trim((string) ($payload->pdf_tools_url ?? ''));
        $c->pdfToolsUrl = preg_match('#^https?://#i', $pdfUrl) ? $pdfUrl : '';
        // Office suite embed URL (Collabora/OnlyOffice) — http(s) only.
        $officeUrl = trim((string) ($payload->office_url ?? ''));
        $c->officeUrl = preg_match('#^https?://#i', $officeUrl) ? $officeUrl : '';
        // E-signature embed URL (DocuSeal) — http(s) only.
        $esignUrl = trim((string) ($payload->esign_url ?? ''));
        $c->esignUrl = preg_match('#^https?://#i', $esignUrl) ? $esignUrl : '';
        $c->allowCodeEdit = (bool) ($payload->allow_code_edit ?? false);
        $c->allowGitDeploy = (bool) ($payload->allow_git_deploy ?? false);
        $c->gitDeployPath = str_replace(["\0", "\x00"], '', trim((string) ($payload->git_deploy_path ?? '')));
        // Only safe git-ref characters. escapeshellarg() already makes this shell-safe
        // regardless, but rejecting a malformed "branch" before it ever reaches git
        // is cheap defense in depth against a mis-minted claim.
        $gdBranch = trim((string) ($payload->git_deploy_branch ?? ''));
        $c->gitDeployBranch = preg_match('/^[A-Za-z0-9._\/-]+$/', $gdBranch) === 1 ? $gdBranch : '';
        $c->gitDeployHooks = (bool) ($payload->git_deploy_hooks ?? false);
        $c->allowOptimize = (bool) ($payload->allow_optimize ?? false);
        $c->allowShare = (bool) ($payload->allow_share ?? false);
        // Share landing claims. The TTL clamps to [10, 300] (0/absent → 60); the base
        // URL is http(s)-only like terminal_pty_url so it can never become a
        // javascript: link in a host UI; preview defaults on.
        $shareTtl = (int) ($payload->share_url_ttl ?? 0);
        $c->shareUrlTtl = $shareTtl > 0 ? max(10, min(300, $shareTtl)) : 60;
        $shareBase = trim((string) ($payload->share_base_url ?? ''));
        $c->shareBaseUrl = preg_match('#^https?://#i', $shareBase) ? $shareBase : '';
        $c->sharePreview = isset($payload->share_preview) ? (bool) $payload->share_preview : true;
        $c->shareAnalytics = isset($payload->share_analytics) ? (bool) $payload->share_analytics : false;
        $c->shareBrand = self::sanitizeShareBrand($payload);
        $c->allowIntake = (bool) ($payload->allow_intake ?? false);
        // Intake portal link base — same http(s)-only rule as share_base_url.
        $intakeBase = trim((string) ($payload->intake_base_url ?? ''));
        $c->intakeBaseUrl = preg_match('#^https?://#i', $intakeBase) ? $intakeBase : '';
        $c->intakeBrand = self::sanitizeIntakeBrand($payload);
        $c->intakeAnalytics = isset($payload->intake_analytics) ? (bool) $payload->intake_analytics : false;
        $c->allowVersioning = (bool) ($payload->allow_versioning ?? false);
        $c->versioningMax = max(0, (int) ($payload->versioning_max ?? 0));
        $c->versioningMaxMb = max(0, (int) ($payload->versioning_max_mb ?? 0));
        $c->allowWebhooks = (bool) ($payload->allow_webhooks ?? false);
        // Only accept an http(s) webhook URL (anything else is dropped so it can never
        // be used to reach a non-HTTP scheme).
        $whUrl = trim((string) ($payload->webhook_url ?? ''));
        $c->webhookUrl = preg_match('#^https?://#i', $whUrl) ? $whUrl : '';
        $whEv = $payload->webhook_events ?? null;
        if (is_array($whEv)) {
            $c->webhookEvents = array_values(array_filter(array_map(
                static fn ($e) => strtolower(trim((string) $e)),
                $whEv
            ), 'strlen'));
        } elseif (is_string($whEv) && trim($whEv) !== '') {
            $c->webhookEvents = array_values(array_filter(array_map('trim', explode(',', strtolower($whEv))), 'strlen'));
        }
        $c->webhookSecret = (string) ($payload->webhook_secret ?? '');
        $c->allowAiVision = (bool) ($payload->allow_ai_vision ?? false);
        $c->allowOcr = (bool) ($payload->allow_ocr ?? false);
        $c->allowVirusScan = (bool) ($payload->allow_virus_scan ?? false);
        $c->allowBackup = (bool) ($payload->allow_backup ?? false);
        $c->allowC2pa = (bool) ($payload->allow_c2pa ?? false);
        $c->allowAuditExport = (bool) ($payload->allow_audit_export ?? false);
        $c->auditRetentionDays = max(0, (int) ($payload->audit_retention_days ?? 0));
        $c->allowDlpScan = (bool) ($payload->allow_dlp_scan ?? false);
        $c->dlpEntityTypes = isset($payload->dlp_entity_types) ? self::sanitizeDlpEntityTypes($payload->dlp_entity_types) : null;
        $c->dlpScanExtensions = self::sanitizeDlpScanExtensions($payload->dlp_scan_extensions ?? null);
        $dlpMaxScanKb = (int) ($payload->dlp_max_scan_kb ?? 0);
        $c->dlpMaxScanKb = $dlpMaxScanKb > 0 ? max(16, min(51200, $dlpMaxScanKb)) : 2048;
        $dlpMinScore = (float) ($payload->dlp_min_score ?? 0);
        $c->dlpMinScore = $dlpMinScore > 0 ? max(0.0, min(1.0, $dlpMinScore)) : 0.6;
        // Legal hold: gates PLACING/RELEASING a hold only (module management routes).
        // Enforcement of an already-placed hold (blocking delete/trash/rename/move/
        // purge) is free/core and NEVER checks this claim — see docs/RETENTION-LEGAL-HOLD-DESIGN.md §2.
        $c->allowLegalHold = (bool) ($payload->allow_legal_hold ?? false);
        $c->autoOptimize = (bool) ($payload->auto_optimize ?? false);
        $c->optimizeQuality = max(0, min(95, (int) ($payload->optimize_quality ?? 0)));
        $c->optimizeKeepOriginal = (bool) ($payload->optimize_keep_original ?? false);
        $c->optimizeMaxMb = max(0, (int) ($payload->optimize_max_mb ?? 0));
        $pdfLevel = strtolower((string) ($payload->pdf_level ?? 'ebook'));
        $c->pdfLevel = in_array($pdfLevel, ['screen', 'ebook', 'printer', 'prepress', 'default'], true) ? $pdfLevel : 'ebook';
        $collision = strtolower((string) ($payload->upload_collision ?? 'rename'));
        $c->uploadCollision = in_array($collision, ['rename', 'overwrite', 'reject'], true) ? $collision : 'rename';
        $c->showHidden = (bool) ($payload->show_hidden ?? false);
        // Default TRUE (opt-out): the UI only ever renders the affordance on an
        // unlicensed, unframed server, so an absent claim can't upsell a paying tenant.
        $c->proHints = isset($payload->pro_hints) ? (bool) $payload->pro_hints : true;
        $c->dedupeUploads = (bool) ($payload->dedupe_uploads ?? false);
        $c->watermark = self::sanitizeWatermark($payload);
        // A watermark OVERLAY exists solely to withhold the clean original — serving
        // it alongside (allow_download=true) would defeat the watermark entirely,
        // a contradictory/no-op state. So an overlay watermark IMPLIES preview-only:
        // force allow_download off, and list() then serves only the watermarked
        // img_base (presign of the original → 403, no clean variants/zip). The
        // burn-in editor doesn't use this claim, so it's unaffected. To sell the
        // clean file later, issue a separate token without the watermark.
        if ($c->watermark !== null) {
            $c->allowDownload = false;
        }

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
            case 'allow_terminal':   return $this->allowTerminal;
            case 'allow_git_deploy': return $this->allowGitDeploy;
            case 'allow_download':   return $this->allowDownload;
            // Paid-module claims — without these the ModuleRegistry 3-layer gate
            // (layer 3) would 403 every paid module regardless of the token.
            case 'allow_share':      return $this->allowShare;
            case 'allow_intake':     return $this->allowIntake;
            case 'allow_versioning': return $this->allowVersioning;
            case 'allow_webhooks':   return $this->allowWebhooks;
            case 'allow_ai_vision':  return $this->allowAiVision;
            case 'allow_ocr':        return $this->allowOcr;
            case 'allow_virus_scan': return $this->allowVirusScan;
            case 'allow_backup':     return $this->allowBackup;
            case 'allow_c2pa':       return $this->allowC2pa;
            case 'allow_audit_export': return $this->allowAuditExport;
            case 'allow_dlp_scan':    return $this->allowDlpScan;
            case 'allow_legal_hold': return $this->allowLegalHold;
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

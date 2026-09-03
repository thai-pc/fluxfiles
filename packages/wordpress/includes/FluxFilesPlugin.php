<?php

defined('ABSPATH') || exit;

/**
 * Main plugin class — singleton that wires all hooks.
 */
class FluxFilesPlugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(FLUXFILES_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(FLUXFILES_PLUGIN_FILE, [$this, 'deactivate']);
        add_action('admin_init', [FluxFilesDbSchema::class, 'maybeUpgrade']);

        // Admin
        if (is_admin()) {
            new FluxFilesAdmin();
            new FluxFilesMediaButton();
        }
        // Native media-modal integration (experimental, opt-in) — runs in admin +
        // the Customizer, so it's booted outside the is_admin() block.
        new FluxFilesMediaIntegration();

        // REST API
        add_action('rest_api_init', [FluxFilesApi::class, 'registerRoutes']);

        // Shortcode
        new FluxFilesShortcode();

        // Attachment bridge (3-in-1 media manager): register FluxFiles picks as WP
        // attachments served from your bucket + a Gutenberg block. URL-rewrite filters
        // load early; the block registers on init.
        add_action('plugins_loaded', ['FluxFilesAttachments', 'register']);
        new FluxFilesBlock();
    }

    /**
     * Plugin activation — create storage directory and set default options.
     */
    public function activate(): void
    {
        $storagePath = self::storagePath();

        if (!is_dir($storagePath)) {
            wp_mkdir_p($storagePath);
        }
        if (!is_dir($storagePath . '/uploads')) {
            wp_mkdir_p($storagePath . '/uploads');
        }

        // Set default options if not already set
        if (get_option('fluxfiles_secret') === false) {
            update_option('fluxfiles_secret', wp_generate_password(32, false));
        }
        if (get_option('fluxfiles_disks') === false) {
            update_option('fluxfiles_disks', ['local']);
        }
        if (get_option('fluxfiles_default_perms') === false) {
            update_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        }
        if (get_option('fluxfiles_max_upload') === false) {
            update_option('fluxfiles_max_upload', 10);
        }
        if (get_option('fluxfiles_max_storage') === false) {
            update_option('fluxfiles_max_storage', 0);
        }
        if (get_option('fluxfiles_ttl') === false) {
            update_option('fluxfiles_ttl', 3600);
        }

        FluxFilesDbSchema::install();

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the FluxFiles storage directory path.
     */
    public static function storagePath(): string
    {
        return WP_CONTENT_DIR . '/fluxfiles';
    }

    /**
     * The licence key for this install.
     *
     * The core reads `FLUXFILES_LICENSE_KEY` from the environment, which is fine for a
     * Docker or Laravel deploy and useless on the shared hosting most WordPress sites
     * run on — there is no shell to export it from. So the plugin stores it as an
     * option and prefers that, falling back to the environment for the minority who
     * can set one (and so an existing env-based install keeps working untouched).
     */
    public static function licenseKey(): string
    {
        $opt = trim((string) get_option('fluxfiles_license_key', ''));
        if ($opt !== '') {
            return $opt;
        }
        return (string) (getenv('FLUXFILES_LICENSE_KEY') ?: ($_ENV['FLUXFILES_LICENSE_KEY'] ?? ''));
    }

    /**
     * The licence verifier, built from wherever the key actually lives.
     *
     * Every paid-module gate in the plugin must go through this rather than
     * LicenseManager::fromEnv(), or a customer who pasted a perfectly good key into the
     * settings screen is told they have no licence.
     */
    public static function license(): \FluxFiles\LicenseManager
    {
        return new \FluxFiles\LicenseManager(self::licenseKey() ?: null);
    }

    /**
     * Get the FluxFiles base installation path (project root).
     */
    public static function basePath(): string
    {
        return dirname(FLUXFILES_PLUGIN_DIR, 2);
    }

    /**
     * Locate a core asset path. Release ZIPs bundle core under <plugin>/{public,assets,lang};
     * monorepo dev checkouts have them at packages/core/{public,assets,lang}.
     */
    public static function corePath(string $subdir): ?string
    {
        $candidates = [
            FLUXFILES_PLUGIN_DIR . $subdir,
            dirname(FLUXFILES_PLUGIN_DIR) . '/core/' . $subdir,
        ];
        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return rtrim($path, '/');
            }
        }
        return null;
    }

    /**
     * The public URL of a bundled recipient page (share.html / intake.html), with the
     * one-shot token attached.
     *
     * Standalone serves these from /public/ at the site root; a WordPress site has no
     * such path. They go through public-link.php, which injects the REST base the
     * static page cannot know — see that file.
     */
    public static function publicLinkUrl(string $page, string $token = ''): string
    {
        $which = $page === 'intake.html' ? 'intake' : 'share';
        $args = ['page' => $which];
        // Token omitted = the BASE url, which is what the *_base_url claims carry: the
        // module appends `&token=…` itself, so a base that already had one would
        // produce two and resolve the wrong (first) value.
        if ($token !== '') {
            $args['token'] = $token;
        }
        return add_query_arg($args, plugins_url('public-link.php', FLUXFILES_PLUGIN_FILE));
    }

    /**
     * Get disk configurations.
     */
    public static function diskConfigs(): array
    {
        $storagePath = self::storagePath();

        $disks = [
            'local' => [
                'driver' => 'local',
                'root'   => $storagePath . '/uploads',
                'url'    => content_url('fluxfiles/uploads'),
                // true = files are NOT served from the public 'url' above; the UI's
                // list() marks them gated and the embedded client fetches bytes via
                // the signed `/api/fm/stream` route instead. Off by default (the WP
                // content dir is normally web-served).
                'private' => (bool) get_option('fluxfiles_local_private', false),
            ],
        ];

        // S3
        $s3Bucket = get_option('fluxfiles_s3_bucket', '');
        if (!empty($s3Bucket)) {
            $disks['s3'] = [
                'driver'     => 's3',
                'region'     => get_option('fluxfiles_s3_region', 'ap-southeast-1'),
                'bucket'     => $s3Bucket,
                'key'        => get_option('fluxfiles_s3_key', ''),
                'secret'     => get_option('fluxfiles_s3_secret', ''),
                // Custom S3-compatible endpoint (MinIO / DO Spaces). Empty = native AWS.
                'endpoint'   => get_option('fluxfiles_s3_endpoint', ''),
                // 'private' = presigned URLs (default); 'public' = direct object URLs.
                'visibility' => get_option('fluxfiles_s3_visibility', 'private'),
                'public_url' => get_option('fluxfiles_s3_public_url', ''),
                // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h.
                'url_ttl'    => (int) get_option('fluxfiles_s3_url_ttl', 3600),
            ];
        }

        // R2
        $r2Bucket = get_option('fluxfiles_r2_bucket', '');
        if (!empty($r2Bucket)) {
            $accountId = get_option('fluxfiles_r2_account_id', '');
            $disks['r2'] = [
                'driver'     => 's3',
                'endpoint'   => "https://{$accountId}.r2.cloudflarestorage.com",
                'region'     => 'auto',
                'bucket'     => $r2Bucket,
                'key'        => get_option('fluxfiles_r2_key', ''),
                'secret'     => get_option('fluxfiles_r2_secret', ''),
                // 'public' needs a public bucket + public_url (r2.dev / custom domain).
                'visibility' => get_option('fluxfiles_r2_visibility', 'private'),
                'public_url' => get_option('fluxfiles_r2_public_url', ''),
                // Presigned GET-URL lifetime (seconds) on a private disk. Default 1h.
                'url_ttl'    => (int) get_option('fluxfiles_r2_url_ttl', 3600),
            ];
        }

        return $disks;
    }

    /**
     * Generate a JWT token for a WordPress user.
     */
    public static function generateToken(
        int $userId,
        array $overrides = []
    ): string {
        $secret = get_option('fluxfiles_secret', '');

        if (empty($secret)) {
            throw new \RuntimeException('FluxFiles secret is not configured.');
        }

        $defaultPerms  = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $defaultDisks  = get_option('fluxfiles_disks', ['local']);
        $maxUpload     = (int) get_option('fluxfiles_max_upload', 10);
        $maxStorage    = (int) get_option('fluxfiles_max_storage', 0);
        $maxFiles      = (int) get_option('fluxfiles_max_files', 0);
        $ttl           = (int) get_option('fluxfiles_ttl', 3600);

        $now = time();

        // Role preset (DX sugar, docs/ACL-ROLE-PRESETS-DESIGN.md): resolved BEFORE the
        // base payload array, because `perms` already has an unconditional default baked
        // into that array below — a plain "set if absent" guard would never fire for it.
        $roleDefaults = self::rolePreset(isset($overrides['role']) ? (string) $overrides['role'] : null);

        $payload = [
            'sub'         => (string) $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? $ttl),
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaultPerms),
            'disks'       => $overrides['disks'] ?? $defaultDisks,
            'prefix'      => $overrides['prefix'] ?? '',
            'max_upload'  => $overrides['max_upload'] ?? $maxUpload,
            'allowed_ext' => $overrides['allowed_ext'] ?? null,
            'max_storage' => $overrides['max_storage'] ?? $maxStorage,
            'max_files'   => $overrides['max_files'] ?? $maxFiles,
        ];

        if (array_key_exists('owner_only', $overrides) ? (bool) $overrides['owner_only'] : ($roleDefaults['owner_only'] ?? false)) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides, $roleDefaults);

        return \FluxFiles\JwtCompat::encode($payload, $secret);
    }

    /**
     * Forward the optional per-tenant override claims into a token payload. The
     * core sanitizes/clamps them on decode, so this only copies them — no hard
     * dependency on a specific core method.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $overrides
     */
    /** @param array<string,mixed> $payload */
    private static function applyEditionPreset(array &$payload, ?string $edition): void
    {
        $presets = [
            'pro'        => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true],
            'agency'     => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true],
            'enterprise' => ['allow_optimize' => true, 'allow_share' => true, 'allow_intake' => true, 'allow_virus_scan' => true, 'allow_c2pa' => true],
        ];
        foreach ($presets[strtolower((string) $edition)] ?? [] as $k => $v) {
            if (!array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }

    /**
     * Look up a role preset's raw claim map (DX sugar, docs/ACL-ROLE-PRESETS-DESIGN.md).
     * `role` never itself becomes a JWT claim — it only ever expands, at mint time, into
     * ordinary claims the core already decodes. Mirrors packages/core/embed.php's
     * fluxfiles_role_preset() / packages/laravel/src/FluxFilesManager.php's rolePreset().
     *
     * @return array<string,mixed>
     */
    private static function rolePreset(?string $role): array
    {
        $presets = [
            'viewer'     => ['perms' => ['read'], 'owner_only' => true],
            'editor'     => ['perms' => ['read', 'write'], 'owner_only' => true],
            'admin'      => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                              'allow_extract' => true, 'allow_chmod' => true, 'allow_code_edit' => true, 'show_hidden' => true],
            'superadmin' => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                              'allow_extract' => true, 'allow_chmod' => true, 'allow_code_edit' => true, 'show_hidden' => true],
        ];

        return $presets[strtolower((string) $role)] ?? [];
    }

    /**
     * Apply a role preset's default claims onto $payload. Only sets a claim when it's not
     * already present, so explicit overrides win. Deliberately excludes `perms` and
     * `owner_only` — both are already resolved earlier (in generateToken()/generateByobToken(),
     * before this function ever runs), because unlike these claims they already have an
     * unconditional default baked into the base payload array and this guard would never
     * fire for them.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $roleDefaults
     */
    private static function applyRolePreset(array &$payload, array $roleDefaults): void
    {
        foreach ($roleDefaults as $k => $v) {
            if ($k !== 'perms' && $k !== 'owner_only' && !array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }

    private static function applyTenantOverrides(array &$payload, array $overrides, array $roleDefaults = []): void
    {
        // Edition preset (DX sugar): default a tier's claims before explicit overrides.
        self::applyEditionPreset($payload, isset($overrides['edition']) ? (string) $overrides['edition'] : null);
        // Role preset: the rest of the bundle (perms/owner_only already resolved by the
        // caller before applyTenantOverrides() ever runs). Reaches BYOB tokens too, since
        // this method is already shared by generateToken()/generateByobToken() here and
        // edition already reaches both — a deliberate consistency choice, not an oversight.
        self::applyRolePreset($payload, $roleDefaults);
        if (isset($overrides['ai_auto_tag'])) {
            $payload['ai_auto_tag'] = (bool) $overrides['ai_auto_tag'];
        }
        if (!empty($overrides['rate_read'])) {
            $payload['rate_read'] = (int) $overrides['rate_read'];
        }
        if (!empty($overrides['rate_write'])) {
            $payload['rate_write'] = (int) $overrides['rate_write'];
        }
        if (is_array($overrides['variants'] ?? null) && $overrides['variants'] !== []) {
            $payload['variants'] = $overrides['variants'];
        }
        // URL-import claims.
        if (!empty($overrides['allow_url_import'])) {
            $payload['allow_url_import'] = true;
        }
        foreach (['max_import_mb', 'import_rate_limit', 'import_concurrency'] as $intClaim) {
            if (!empty($overrides[$intClaim])) {
                $payload[$intClaim] = (int) $overrides[$intClaim];
            }
        }
        if (!empty($overrides['import_path'])) {
            $payload['import_path'] = (string) $overrides['import_path'];
        }
        if (is_array($overrides['import_url_allowlist'] ?? null) && $overrides['import_url_allowlist'] !== []) {
            $payload['import_url_allowlist'] = array_values($overrides['import_url_allowlist']);
        }
        // Media-preview claims.
        if (array_key_exists('media_preview', $overrides)) {
            $payload['media_preview'] = (bool) $overrides['media_preview'];
        }
        foreach (['preview_url_ttl', 'max_preview_mb', 'stream_token_ttl'] as $mediaClaim) {
            if (!empty($overrides[$mediaClaim])) {
                $payload[$mediaClaim] = (int) $overrides[$mediaClaim];
            }
        }
        // On-demand WebP claims.
        if (array_key_exists('webp_enabled', $overrides)) {
            $payload['webp_enabled'] = (bool) $overrides['webp_enabled'];
        }
        foreach (['webp_max_width', 'webp_default_quality'] as $webpClaim) {
            if (!empty($overrides[$webpClaim])) {
                $payload[$webpClaim] = (int) $overrides[$webpClaim];
            }
        }
        // Responsive srcset (Claims sanitizes the ladder on decode).
        if (isset($overrides['srcset_widths']) && is_array($overrides['srcset_widths'])) {
            $payload['srcset_widths'] = array_values(array_map('intval', $overrides['srcset_widths']));
        }
        if (!empty($overrides['srcset_sizes'])) {
            $payload['srcset_sizes'] = (string) $overrides['srcset_sizes'];
        }
        // Download gate + watermark.
        if (array_key_exists('allow_download', $overrides)) {
            $payload['allow_download'] = (bool) $overrides['allow_download'];
        }
        if (array_key_exists('allow_chmod', $overrides)) {
            $payload['allow_chmod'] = (bool) $overrides['allow_chmod'];
        }
        if (array_key_exists('allow_code_edit', $overrides)) {
            $payload['allow_code_edit'] = (bool) $overrides['allow_code_edit'];
        }
        // SSH terminal now proxies too (see FluxFilesApi's handleTerminal()), so the
        // gate forwards like any other, same as allow_versioning/allow_audit_export above.
        if (array_key_exists('allow_terminal', $overrides)) {
            $payload['allow_terminal'] = (bool) $overrides['allow_terminal'];
        }
        // Optional self-hosted PTY terminal URL (ttyd/gotty/wetty) — pure UI config,
        // works in any mode like pdf_tools_url/office_url/esign_url below.
        if (!empty($overrides['terminal_pty_url'])) {
            $payload['terminal_pty_url'] = (string) $overrides['terminal_pty_url'];
        }
        // PDF-tools + office embeds are pure UI (no core endpoint) → work in any mode.
        if (!empty($overrides['pdf_tools_url'])) {
            $payload['pdf_tools_url'] = (string) $overrides['pdf_tools_url'];
        }
        if (!empty($overrides['office_url'])) {
            $payload['office_url'] = (string) $overrides['office_url'];
        }
        if (!empty($overrides['esign_url'])) {
            $payload['esign_url'] = (string) $overrides['esign_url'];
        }
        // Share and Intake. These used to be stripped here, because the REST API
        // exposed none of their endpoints and a forwarded claim would have rendered a
        // button that 404s. It now proxies all eleven — the six operator routes and the
        // five public recipient ones — so the claims are forwarded like any other.
        foreach (['allow_share', 'allow_intake'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
        }
        // Share landing config, read by the module at create time and baked into the
        // record, so it travels with the gate claim (core clamps the TTL and drops a
        // non-http(s) base URL on decode).
        if (!empty($overrides['share_url_ttl'])) {
            $payload['share_url_ttl'] = (int) $overrides['share_url_ttl'];
        }
        if (array_key_exists('share_preview', $overrides)) {
            $payload['share_preview'] = (bool) $overrides['share_preview'];
        }
        if (array_key_exists('share_analytics', $overrides)) {
            $payload['share_analytics'] = (bool) $overrides['share_analytics'];
        }
        // The recipient link base. Defaults to this site's own page rather than being
        // left empty: empty makes the module fall back to the request origin plus
        // `/public/share.html`, a path a WordPress site does not have.
        $payload['share_base_url'] = !empty($overrides['share_base_url'])
            ? (string) $overrides['share_base_url']
            : self::publicLinkUrl('share.html');
        $payload['intake_base_url'] = !empty($overrides['intake_base_url'])
            ? (string) $overrides['intake_base_url']
            : self::publicLinkUrl('intake.html');
        if (array_key_exists('intake_analytics', $overrides)) {
            $payload['intake_analytics'] = (bool) $overrides['intake_analytics'];
        }
        // File versioning. This used to be stripped here, because the REST API exposed
        // no /api/fm/versions* endpoint and a forwarded claim would have rendered a
        // History panel that 404s. It now proxies list + restore (see FluxFilesApi's
        // handleVersions()/handleVersionsRestore()), so the gate + its tuning claims
        // forward like any other, same as allow_share/allow_intake above.
        if (array_key_exists('allow_versioning', $overrides)) {
            $payload['allow_versioning'] = (bool) $overrides['allow_versioning'];
        }
        foreach (['versioning_max', 'versioning_max_mb'] as $verClaim) {
            if (!empty($overrides[$verClaim])) {
                $payload[$verClaim] = (int) $overrides[$verClaim];
            }
        }
        // Audit export/purge now proxies too (see FluxFilesApi's
        // handleAuditExport()/handleAuditPurge()), so the gate + its retention-days
        // tuning claim forward like any other, same as allow_versioning above.
        if (array_key_exists('allow_audit_export', $overrides)) {
            $payload['allow_audit_export'] = (bool) $overrides['allow_audit_export'];
        }
        if (!empty($overrides['audit_retention_days'])) {
            $payload['audit_retention_days'] = (int) $overrides['audit_retention_days'];
        }
        // AI Vision now proxies too (see FluxFilesApi's handleAiVision()), so the gate
        // forwards like any other, same as allow_versioning/allow_audit_export above.
        if (array_key_exists('allow_ai_vision', $overrides)) {
            $payload['allow_ai_vision'] = (bool) $overrides['allow_ai_vision'];
        }
        // NOTE: SSO (FLUXFILES_SSO_*) is NOT a claim-forwarding concern at all — those
        // are pure server env vars configuring the standalone /public UI's own login
        // endpoint. They never travel inside a JWT, so there is nothing to forward
        // here either. SSO only applies to deployments with no host app (like this
        // plugin) minting tokens in the first place.
        foreach (['allow_webhooks', 'allow_ocr', 'allow_virus_scan', 'allow_backup', 'allow_c2pa'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
        }
        // Webhook config. Without a URL `allow_webhooks` is inert (the module has
        // nowhere to POST), so these travel with the gate claim. The core drops a
        // non-http(s) URL on decode; `webhook_secret` falls back to FLUXFILES_SECRET.
        if (!empty($overrides['webhook_url'])) {
            $payload['webhook_url'] = (string) $overrides['webhook_url'];
        }
        if (!empty($overrides['webhook_events'])) {
            // Array or a comma-separated string (the core normalizes both), so a plain
            // settings text field works as well as a list.
            $payload['webhook_events'] = is_array($overrides['webhook_events'])
                ? array_values($overrides['webhook_events'])
                : (string) $overrides['webhook_events'];
        }
        if (!empty($overrides['webhook_secret'])) {
            $payload['webhook_secret'] = (string) $overrides['webhook_secret'];
        }
        if (array_key_exists('allow_optimize', $overrides)) {
            $payload['allow_optimize'] = (bool) $overrides['allow_optimize'];
        }
        if (array_key_exists('auto_optimize', $overrides)) {
            $payload['auto_optimize'] = (bool) $overrides['auto_optimize'];
        }
        if (!empty($overrides['optimize_quality'])) {
            $payload['optimize_quality'] = (int) $overrides['optimize_quality'];
        }
        if (array_key_exists('optimize_keep_original', $overrides)) {
            $payload['optimize_keep_original'] = (bool) $overrides['optimize_keep_original'];
        }
        if (!empty($overrides['optimize_max_mb'])) {
            $payload['optimize_max_mb'] = (int) $overrides['optimize_max_mb'];
        }
        if (isset($overrides['pdf_level'])
            && in_array($overrides['pdf_level'], ['screen', 'ebook', 'printer', 'prepress', 'default'], true)) {
            $payload['pdf_level'] = (string) $overrides['pdf_level'];
        }
        if (isset($overrides['upload_collision'])
            && in_array($overrides['upload_collision'], ['rename', 'overwrite', 'reject'], true)) {
            $payload['upload_collision'] = (string) $overrides['upload_collision'];
        }
        if (array_key_exists('show_hidden', $overrides)) {
            $payload['show_hidden'] = (bool) $overrides['show_hidden'];
        }
        if (array_key_exists('dedupe_uploads', $overrides)) {
            $payload['dedupe_uploads'] = (bool) $overrides['dedupe_uploads'];
        }
        if (array_key_exists('allow_zip', $overrides)) {
            $payload['allow_zip'] = (bool) $overrides['allow_zip'];
        }
        if (array_key_exists('allow_extract', $overrides)) {
            $payload['allow_extract'] = (bool) $overrides['allow_extract'];
        }
        foreach (['zip_max_mb', 'zip_max_files'] as $zipClaim) {
            if (!empty($overrides[$zipClaim])) {
                $payload[$zipClaim] = (int) $overrides[$zipClaim];
            }
        }
        // NOTE: the OVERLAY watermark (preview-time, served via /api/fm/img) is
        // intentionally NOT forwarded. The WordPress plugin is proxy-only (the REST
        // API never exposes /api/fm/img and sets no stream secret), so list() can't
        // emit the watermarked img_base; and an overlay watermark forces the token
        // preview-only (allow_download off in core), which would leave images with
        // neither a clean URL nor a preview — i.e. broken. Use the burn-in watermark
        // instead (POST .../api/fm/watermark, handled by handleWatermark), which
        // writes the mark into the file and is fully proxied.
        // Usage-dashboard claims.
        foreach ([
            'usage_cache_ttl', 'usage_warning_threshold', 'usage_critical_threshold',
            'usage_top_folders_count', 'usage_folder_depth',
        ] as $usageClaim) {
            if (isset($overrides[$usageClaim]) && $overrides[$usageClaim] !== '') {
                $payload[$usageClaim] = (int) $overrides[$usageClaim];
            }
        }

        // Generic escape hatch: any JWT claim by its raw snake_case name, e.g.
        // ['claims' => ['allow_optimize' => true, 'upload_collision' => 'overwrite']].
        // Merged last so explicit claims win; the core sanitizes on decode. See docs/CONFIG.md.
        if (!empty($overrides['claims']) && is_array($overrides['claims'])) {
            foreach ($overrides['claims'] as $k => $v) {
                if ($v !== null) {
                    $payload[(string) $k] = $v;
                }
            }
        }
    }

    /**
     * Generate a BYOB (Bring Your Own Bucket) JWT token.
     *
     * @param int   $userId    WordPress user ID
     * @param array $byobDisks Map of disk name => S3 config array
     * @param array $overrides Optional overrides
     */
    public static function generateByobToken(
        int $userId,
        array $byobDisks,
        array $overrides = []
    ): string {
        $secret = get_option('fluxfiles_secret', '');

        if (empty($secret)) {
            throw new \RuntimeException('FluxFiles secret is not configured.');
        }

        $defaultPerms = get_option('fluxfiles_default_perms', ['read', 'write', 'delete']);
        $defaultDisks = get_option('fluxfiles_disks', ['local']);
        $maxUpload    = (int) get_option('fluxfiles_max_upload', 10);
        $maxStorage   = (int) get_option('fluxfiles_max_storage', 0);
        $ttl          = (int) get_option('fluxfiles_ttl', 3600);

        $now = time();

        // Encrypt BYOB disk configs
        $encryptedDisks = [];
        foreach ($byobDisks as $name => $config) {
            \FluxFiles\CredentialEncryptor::validate($name, $config);
            $encryptedDisks[$name] = \FluxFiles\CredentialEncryptor::encrypt($config, $secret);
        }

        $serverDisks = $overrides['disks'] ?? $defaultDisks;
        $allDisks = array_merge($serverDisks, array_keys($byobDisks));

        // Role preset — see the identical early-resolution note in generateToken() above.
        $roleDefaults = self::rolePreset(isset($overrides['role']) ? (string) $overrides['role'] : null);

        $payload = [
            'sub'         => (string) $userId,
            'iat'         => $now,
            'exp'         => $now + min($overrides['ttl'] ?? $ttl, 1800), // cap at 1800s for BYOB
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaultPerms),
            'disks'       => $allDisks,
            'prefix'      => $overrides['prefix'] ?? '',
            'max_upload'  => $overrides['max_upload'] ?? $maxUpload,
            'allowed_ext' => $overrides['allowed_ext'] ?? null,
            'max_storage' => $overrides['max_storage'] ?? $maxStorage,
            'byob_disks'  => $encryptedDisks,
        ];

        if (array_key_exists('owner_only', $overrides) ? (bool) $overrides['owner_only'] : ($roleDefaults['owner_only'] ?? false)) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides, $roleDefaults);

        return \FluxFiles\JwtCompat::encode($payload, $secret);
    }

    /**
     * Generate a token for the current logged-in user.
     *
     * The built-in shortcode / media button / REST proxy mint tokens through
     * here, so the `fluxfiles_token_overrides` filter is the supported way to
     * set per-tenant claims (e.g. enable Import from URL) without a custom
     * token caller:
     *
     *   add_filter('fluxfiles_token_overrides', function ($overrides, $userId) {
     *       $overrides['allow_url_import'] = true;
     *       $overrides['max_import_mb']    = 20;
     *       return $overrides;
     *   }, 10, 2);
     */
    public static function tokenForCurrentUser(array $overrides = []): string
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            throw new \RuntimeException('No authenticated WordPress user.');
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fluxfiles_token_overrides', $overrides, $userId);
            if (is_array($filtered)) {
                $overrides = $filtered;
            }
        }

        return self::generateToken($userId, $overrides);
    }

    /**
     * Get the FluxFiles REST API endpoint base URL.
     */
    public static function apiEndpoint(): string
    {
        return rest_url('fluxfiles/v1');
    }
}

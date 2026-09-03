<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use FluxFiles\JwtCompat;
use Illuminate\Contracts\Auth\Authenticatable;

class FluxFilesManager
{
    /**
     * Generate a JWT token for FluxFiles.
     *
     * @param string|int|Authenticatable $user
     */
    public function token(
        $user,
        array $overrides = []
    ): string {
        $secret = config('fluxfiles.secret');

        if (empty($secret)) {
            throw new \RuntimeException('FLUXFILES_SECRET is not configured.');
        }

        $userId = $user instanceof Authenticatable
            ? (string) $user->getAuthIdentifier()
            : (string) $user;

        $defaults = config('fluxfiles.defaults');
        $now = time();

        // Role preset (DX sugar, docs/ACL-ROLE-PRESETS-DESIGN.md): resolved BEFORE
        // the base payload array, because `perms` already has an unconditional
        // default baked into that array below — a plain "set if absent" guard
        // running afterward would never fire for it.
        $roleDefaults = self::rolePreset(isset($overrides['role']) ? (string) $overrides['role'] : null);

        $payload = [
            'sub'         => $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? $defaults['ttl']),
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaults['perms']),
            'disks'       => $overrides['disks'] ?? $defaults['disks'],
            'prefix'      => $overrides['prefix'] ?? $defaults['prefix'],
            'max_upload'  => $overrides['max_upload'] ?? $defaults['max_upload'],
            'allowed_ext' => $overrides['allowed_ext'] ?? $defaults['allowed_ext'],
            'max_storage' => $overrides['max_storage'] ?? $defaults['max_storage'],
            'max_files'   => $overrides['max_files'] ?? $defaults['max_files'] ?? 0,
        ];

        if (array_key_exists('owner_only', $overrides) ? (bool) $overrides['owner_only'] : ($roleDefaults['owner_only'] ?? false)) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides, $roleDefaults);

        return JwtCompat::encode($payload, $secret);
    }

    /**
     * Copy the optional per-tenant override claims into a payload when present.
     * Keeps tokens lean (omitted keys inherit the server defaults).
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
     * `role` never itself becomes a JWT claim — it only ever expands, at mint time,
     * into ordinary claims already decoded server-side. Mirrors core's
     * `fluxfiles_role_preset()` in packages/core/embed.php.
     *
     * @return array<string,mixed>
     */
    private static function rolePreset(?string $role): array
    {
        $presets = [
            'viewer'     => ['perms' => ['read'], 'owner_only' => true,
                              'allow_extract' => false, 'allow_chmod' => false],
            'editor'     => ['perms' => ['read', 'write'], 'owner_only' => true,
                              'allow_extract' => true, 'allow_chmod' => false],
            'admin'      => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                              'allow_extract' => true, 'allow_chmod' => true, 'allow_code_edit' => true, 'show_hidden' => true],
            'superadmin' => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                              'allow_extract' => true, 'allow_chmod' => true, 'allow_code_edit' => true, 'show_hidden' => true],
        ];

        return $presets[strtolower((string) $role)] ?? [];
    }

    /**
     * Apply a role preset's default claims onto $payload. Only sets a claim when
     * it's not already present, so explicit overrides win. Deliberately excludes
     * `perms` and `owner_only` — both are already resolved earlier in token()/
     * tokenWithByob(), because unlike these claims they already have an
     * unconditional default baked into the base payload array and this guard
     * would never fire for them.
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
        // Edition preset (DX sugar): default a tier's claims before explicit
        // overrides below (which still win). The license gates the actual code.
        self::applyEditionPreset($payload, isset($overrides['edition']) ? (string) $overrides['edition'] : null);
        // Role preset: the rest of the bundle (perms/owner_only already resolved by
        // the caller before applyTenantOverrides() ever runs — see token()/
        // tokenWithByob()). Reaches BYOB tokens too, matching this file's existing
        // edition-preset behavior (applyTenantOverrides() is shared by both, unlike
        // core's embed.php where the BYOB helper never calls the edition-preset
        // function at all) — a deliberate difference from core, not an oversight.
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
        // Pass `variants` through as-is; the core re-sanitizes it on decode
        // (Claims::sanitizeVariants). Done inline so the adapter never hard-depends
        // on a specific core method — a version mismatch must never be fatal.
        if (is_array($overrides['variants'] ?? null) && $overrides['variants'] !== []) {
            $payload['variants'] = $overrides['variants'];
        }

        // URL-import claims (the core sanitizes/clamps these on decode). Forwarded
        // inline so token($user, ['allow_url_import' => true, …]) actually enables it.
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

        // Media-preview claims (the core sanitizes/clamps these on decode).
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
        // SSH terminal (SFTP disks) now has a proxy route too
        // (FluxFilesController::terminal(), see routes/fluxfiles.php), so the gate
        // claim forwards unconditionally, matching allow_versioning/allow_audit_export/
        // allow_ai_vision above.
        if (array_key_exists('allow_terminal', $overrides)) {
            $payload['allow_terminal'] = (bool) $overrides['allow_terminal'];
        }
        // Optional self-hosted PTY terminal URL (ttyd/gotty/wetty) — pure UI config,
        // works in any mode like pdf_tools_url/office_url/esign_url above.
        if (!empty($overrides['terminal_pty_url'])) {
            $payload['terminal_pty_url'] = (string) $overrides['terminal_pty_url'];
        }
        // Versioning now has routes in both modes (proxy: FluxFilesController's
        // versions()/versionsRestore() + the version-keeper hook wired in
        // fileManager(); standalone: index.php), so the gate claim — and its tuning
        // claims — forward unconditionally, matching allow_share/allow_intake above.
        if (array_key_exists('allow_versioning', $overrides)) {
            $payload['allow_versioning'] = (bool) $overrides['allow_versioning'];
        }
        // Versioning tuning claims (the core clamps these on decode; 0 = its default).
        foreach (['versioning_max', 'versioning_max_mb'] as $verClaim) {
            if (!empty($overrides[$verClaim])) {
                $payload[$verClaim] = (int) $overrides[$verClaim];
            }
        }
        // Audit export/purge now has routes in both modes (proxy: FluxFilesController's
        // auditExport()/auditPurge(); standalone: index.php), so the gate claim — and
        // its retention-days tuning claim — forward unconditionally, matching
        // allow_versioning above.
        if (array_key_exists('allow_audit_export', $overrides)) {
            $payload['allow_audit_export'] = (bool) $overrides['allow_audit_export'];
        }
        if (!empty($overrides['audit_retention_days'])) {
            $payload['audit_retention_days'] = (int) $overrides['audit_retention_days'];
        }
        // AI Vision now has a proxy route too (FluxFilesController::aiVision(), see
        // routes/fluxfiles.php), so the gate claim forwards unconditionally, matching
        // allow_versioning/allow_audit_export above (allow_terminal is still the only
        // standalone-only holdout).
        if (array_key_exists('allow_ai_vision', $overrides)) {
            $payload['allow_ai_vision'] = (bool) $overrides['allow_ai_vision'];
        }
        // NOTE: SSO (FLUXFILES_SSO_*) is NOT a claim-forwarding concern at all — those
        // are pure server env vars that configure the standalone /public UI's own
        // login endpoint (/api/fm/sso/login|callback|exchange). They never travel
        // inside a JWT, so there is nothing for token()/tokenForUser() to forward here,
        // in either mode. SSO only applies to deployments with no host app minting
        // tokens in the first place.
        foreach (['allow_webhooks', 'allow_ocr', 'allow_virus_scan', 'allow_backup', 'allow_c2pa'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
        }
        // Share + Intake now have routes in both modes (proxy: FluxFilesController's
        // shareIntake()/publicLink() dispatchers; standalone: index.php), so the
        // gate claims — and the config that travels with them — forward
        // unconditionally, matching WordPress's FluxFilesPlugin.
        foreach (['allow_share', 'allow_intake'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
        }
        // Share landing config. Read by the module at create time and baked into the
        // share record, so these travel with `allow_share` (the core clamps the TTL
        // and drops a non-http(s) base URL on decode).
        if (!empty($overrides['share_url_ttl'])) {
            $payload['share_url_ttl'] = (int) $overrides['share_url_ttl'];
        }
        if (!empty($overrides['share_base_url'])) {
            $payload['share_base_url'] = (string) $overrides['share_base_url'];
        }
        if (array_key_exists('share_preview', $overrides)) {
            $payload['share_preview'] = (bool) $overrides['share_preview'];
        }
        if (array_key_exists('share_analytics', $overrides)) {
            $payload['share_analytics'] = (bool) $overrides['share_analytics'];
        }
        // Intake portal link base — the same role for `allow_intake`.
        if (!empty($overrides['intake_base_url'])) {
            $payload['intake_base_url'] = (string) $overrides['intake_base_url'];
        }
        if (array_key_exists('intake_analytics', $overrides)) {
            $payload['intake_analytics'] = (bool) $overrides['intake_analytics'];
        }
        // Webhook config. Without a URL `allow_webhooks` is inert (the module has
        // nowhere to POST), so these travel with the gate claim. The core drops a
        // non-http(s) URL on decode; `webhook_secret` falls back to FLUXFILES_SECRET.
        if (!empty($overrides['webhook_url'])) {
            $payload['webhook_url'] = (string) $overrides['webhook_url'];
        }
        if (!empty($overrides['webhook_events'])) {
            // Array or a comma-separated string (the core normalizes both), so a plain
            // config/text field works as well as a list.
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
        // OVERLAY watermark (preview-time, served via /api/fm/img) is forwarded only
        // in 'standalone' mode. /api/fm/img is proxied in BOTH modes now (see
        // FluxFilesController::img()), but that port intentionally does NOT
        // implement the watermark-compositing branch — it only ever serves the
        // plain resized transform. Forwarding watermark_enabled in proxy mode
        // would mint a token whose overlay the proxy's /img silently ignores,
        // handing out an unwatermarked "preview" — worse than not forwarding it,
        // since an overlay watermark also forces the token preview-only
        // (allow_download off in core), so there'd be no clean URL either. For a
        // watermark through the proxy, use the burn-in route (POST
        // /api/fm/watermark) instead, which writes the mark into the file.
        if (!empty($overrides['watermark_enabled']) && config('fluxfiles.mode') === 'standalone') {
            $payload['watermark_enabled'] = true;
            foreach (['watermark_type', 'watermark_text', 'watermark_logo_path', 'watermark_position'] as $s) {
                if (!empty($overrides[$s])) {
                    $payload[$s] = (string) $overrides[$s];
                }
            }
            if (isset($overrides['watermark_opacity'])) {
                $payload['watermark_opacity'] = (float) $overrides['watermark_opacity'];
            }
            if (!empty($overrides['watermark_font_size'])) {
                $payload['watermark_font_size'] = (int) $overrides['watermark_font_size'];
            }
        }

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
        // Merged last so explicit claims win; the core sanitizes on decode. The single
        // place to set claims without a dedicated override. See docs/CONFIG.md.
        if (!empty($overrides['claims']) && is_array($overrides['claims'])) {
            foreach ($overrides['claims'] as $k => $v) {
                if ($v !== null) {
                    $payload[(string) $k] = $v;
                }
            }
        }
    }

    /**
     * Generate a token for the currently authenticated user.
     */
    public function tokenForUser(array $overrides = []): string
    {
        $user = auth()->user();

        if (!$user) {
            throw new \RuntimeException('No authenticated user.');
        }

        return $this->token($user, $overrides);
    }

    /**
     * Generate a BYOB (Bring Your Own Bucket) token.
     *
     * @param string|int|Authenticatable $user
     * @param array $byobDisks Map of disk name => S3 config array
     * @param array $overrides Optional overrides (perms, prefix, ttl, etc.)
     */
    public function tokenWithByob(
        $user,
        array $byobDisks,
        array $overrides = []
    ): string {
        $secret = config('fluxfiles.secret');

        if (empty($secret)) {
            throw new \RuntimeException('FLUXFILES_SECRET is not configured.');
        }

        $userId = $user instanceof Authenticatable
            ? (string) $user->getAuthIdentifier()
            : (string) $user;

        $defaults = config('fluxfiles.defaults');
        $now = time();

        // Encrypt BYOB disk configs
        $encryptedDisks = [];
        foreach ($byobDisks as $name => $config) {
            \FluxFiles\CredentialEncryptor::validate($name, $config);
            $encryptedDisks[$name] = \FluxFiles\CredentialEncryptor::encrypt($config, $secret);
        }

        // Merge server disks + BYOB disk names
        $serverDisks = $overrides['disks'] ?? $defaults['disks'];
        $allDisks = array_merge($serverDisks, array_keys($byobDisks));

        // Role preset — see the identical early-resolution note in token() above.
        $roleDefaults = self::rolePreset(isset($overrides['role']) ? (string) $overrides['role'] : null);

        $payload = [
            'sub'         => $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? 1800), // shorter TTL for BYOB
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaults['perms']),
            'disks'       => $allDisks,
            'prefix'      => $overrides['prefix'] ?? $defaults['prefix'],
            'max_upload'  => $overrides['max_upload'] ?? $defaults['max_upload'],
            'allowed_ext' => $overrides['allowed_ext'] ?? $defaults['allowed_ext'],
            'max_storage' => $overrides['max_storage'] ?? $defaults['max_storage'],
            'max_files'   => $overrides['max_files'] ?? $defaults['max_files'] ?? 0,
            'byob_disks'  => $encryptedDisks,
        ];

        if (array_key_exists('owner_only', $overrides) ? (bool) $overrides['owner_only'] : ($roleDefaults['owner_only'] ?? false)) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides, $roleDefaults);

        return JwtCompat::encode($payload, $secret);
    }

    /**
     * Get the FluxFiles endpoint URL.
     */
    public function endpoint(): string
    {
        $mode = config('fluxfiles.mode');

        if ($mode === 'standalone') {
            return rtrim(config('fluxfiles.endpoint'), '/');
        }

        // Proxy mode: derive from app URL + route prefix
        return rtrim(config('app.url'), '/');
    }

    /**
     * Get the iframe source URL.
     */
    public function iframeSrc(): string
    {
        return $this->endpoint() . '/public/index.html';
    }

    /**
     * Get the SDK script URL.
     */
    public function sdkUrl(): string
    {
        return $this->endpoint() . '/fluxfiles.js';
    }

    /**
     * The public URL of a bundled recipient page (share.html / intake.html), with
     * the one-shot token attached. Served from this app's own site root by
     * FluxFilesController::publicPage() (registered outside the FluxFilesAuth
     * group), mirroring WordPress's FluxFilesPlugin::publicLinkUrl().
     */
    public static function publicLinkUrl(string $page, string $token = ''): string
    {
        $base = rtrim(config('app.url'), '/') . '/public/' . $page;

        // Token omitted = the BASE url, which is what the *_base_url claims carry:
        // the module appends `&token=…` itself, so a base that already had one
        // would produce two and resolve the wrong (first) value.
        return $token !== '' ? $base . '?token=' . urlencode($token) : $base;
    }
}

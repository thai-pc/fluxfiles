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

        $payload = [
            'sub'         => $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? $defaults['ttl']),
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? $defaults['perms'],
            'disks'       => $overrides['disks'] ?? $defaults['disks'],
            'prefix'      => $overrides['prefix'] ?? $defaults['prefix'],
            'max_upload'  => $overrides['max_upload'] ?? $defaults['max_upload'],
            'allowed_ext' => $overrides['allowed_ext'] ?? $defaults['allowed_ext'],
            'max_storage' => $overrides['max_storage'] ?? $defaults['max_storage'],
            'max_files'   => $overrides['max_files'] ?? $defaults['max_files'] ?? 0,
        ];

        if (!empty($overrides['owner_only'])) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides);

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
            'pro'        => ['allow_optimize' => true, 'allow_share' => true],
            'agency'     => ['allow_optimize' => true, 'allow_share' => true],
            'enterprise' => ['allow_optimize' => true, 'allow_share' => true, 'allow_virus_scan' => true],
        ];
        foreach ($presets[strtolower((string) $edition)] ?? [] as $k => $v) {
            if (!array_key_exists($k, $payload)) {
                $payload[$k] = $v;
            }
        }
    }

    private static function applyTenantOverrides(array &$payload, array $overrides): void
    {
        // Edition preset (DX sugar): default a tier's claims before explicit
        // overrides below (which still win). The license gates the actual code.
        self::applyEditionPreset($payload, isset($overrides['edition']) ? (string) $overrides['edition'] : null);
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
        // SSH terminal (SFTP disks) is core-standalone — /api/fm/terminal isn't
        // proxied. Forward the claim only in 'standalone' mode (token → a real core
        // that serves it); in proxy mode it's dropped so the button can't appear
        // for an endpoint that would 404. Same rule as the overlay watermark below.
        if (!empty($overrides['allow_terminal']) && config('fluxfiles.mode') === 'standalone') {
            $payload['allow_terminal'] = true;
            // Optional self-hosted PTY terminal URL (ttyd/gotty/wetty) — standalone only,
            // like allow_terminal (the proxy doesn't expose the terminal endpoint).
            if (!empty($overrides['terminal_pty_url'])) {
                $payload['terminal_pty_url'] = (string) $overrides['terminal_pty_url'];
            }
        }
        foreach (['allow_share', 'allow_ai_vision', 'allow_ocr', 'allow_virus_scan', 'allow_backup', 'allow_c2pa'] as $mc) {
            if (array_key_exists($mc, $overrides)) {
                $payload[$mc] = (bool) $overrides[$mc];
            }
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
        // in 'standalone' mode — the token then targets a real core that serves /img.
        // In proxy mode the adapter does NOT expose /api/fm/img and sets no stream
        // secret, so list() can't emit the watermarked img_base; since an overlay
        // watermark also forces the token preview-only (allow_download off in core),
        // forwarding it here would yield images with neither a clean URL nor a
        // preview — i.e. broken. For a watermark through the proxy, use the burn-in
        // route (POST /api/fm/watermark) instead, which writes the mark into the file.
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

        $payload = [
            'sub'         => $userId,
            'iat'         => $now,
            'exp'         => $now + ($overrides['ttl'] ?? 1800), // shorter TTL for BYOB
            'jti'         => bin2hex(random_bytes(12)),
            'perms'       => $overrides['perms'] ?? $defaults['perms'],
            'disks'       => $allDisks,
            'prefix'      => $overrides['prefix'] ?? $defaults['prefix'],
            'max_upload'  => $overrides['max_upload'] ?? $defaults['max_upload'],
            'allowed_ext' => $overrides['allowed_ext'] ?? $defaults['allowed_ext'],
            'max_storage' => $overrides['max_storage'] ?? $defaults['max_storage'],
            'max_files'   => $overrides['max_files'] ?? $defaults['max_files'] ?? 0,
            'byob_disks'  => $encryptedDisks,
        ];

        if (!empty($overrides['owner_only'])) {
            $payload['owner_only'] = true;
        }
        self::applyTenantOverrides($payload, $overrides);

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
}

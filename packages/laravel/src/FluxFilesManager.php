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
    private static function applyTenantOverrides(array &$payload, array $overrides): void
    {
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

<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use Firebase\JWT\JWT;
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
        ];

        return JWT::encode($payload, $secret, 'HS256');
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

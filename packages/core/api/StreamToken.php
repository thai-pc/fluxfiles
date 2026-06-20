<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Short-lived, single-file token for gated local media streaming.
 *
 * A `<video>`/`<audio>` element can't send an Authorization header, so a private
 * local disk serves its files through `/api/fm/stream?token=<this>`. The token is
 * a tiny HS256 JWT signed with FLUXFILES_SECRET, scoped to exactly one disk+path
 * with a short TTL — so leaking the URL (browser history/logs) exposes only that
 * one file, briefly. The disk/path are read from the *signed* payload, never from
 * the query string, so they can't be tampered into a traversal.
 */
final class StreamToken
{
    /** Token type marker, to keep these distinct from the main access JWT. */
    private const TYPE = 'stream';

    /** Hard ceiling on TTL regardless of the requested value. */
    public const MAX_TTL = 86400; // 24h

    /**
     * Mint a stream token for one file.
     *
     * @param string $disk   Disk name (must be a local, private disk at serve time)
     * @param string $path   Full storage key (already prefix-scoped)
     * @param string $sub    Subject (the access token's user id) — for audit parity
     * @param int    $ttl    Seconds until expiry (clamped to [1, MAX_TTL])
     * @param string $secret FLUXFILES_SECRET
     */
    public static function mint(string $disk, string $path, string $sub, int $ttl, string $secret): string
    {
        $ttl = max(1, min($ttl, self::MAX_TTL));
        $now = time();
        return JwtCompat::encode([
            't'    => self::TYPE,
            'disk' => $disk,
            'path' => $path,
            'sub'  => $sub,
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ], $secret);
    }

    /**
     * Verify a stream token and return its scoped {disk, path, sub}.
     * Throws ApiException(403) on any failure (bad signature, expired, wrong type).
     *
     * @return array{disk:string,path:string,sub:string}
     */
    public static function verify(string $token, string $secret): array
    {
        if ($token === '' || $secret === '') {
            throw new ApiException('Stream token required', 403, 'stream_token_invalid');
        }
        try {
            $p = JwtCompat::decode($token, $secret);
        } catch (\Throwable $e) {
            // Expired / bad signature / malformed — all collapse to one opaque error.
            throw new ApiException('Stream token invalid or expired', 403, 'stream_token_invalid');
        }

        if (($p->t ?? null) !== self::TYPE) {
            throw new ApiException('Not a stream token', 403, 'stream_token_invalid');
        }
        $disk = (string) ($p->disk ?? '');
        $path = (string) ($p->path ?? '');
        if ($disk === '' || $path === '') {
            throw new ApiException('Stream token missing scope', 403, 'stream_token_invalid');
        }
        return ['disk' => $disk, 'path' => $path, 'sub' => (string) ($p->sub ?? '')];
    }
}

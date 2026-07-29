<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Short-lived proof that a share's password was entered, bound to one share id.
 *
 * A password must never ride a query string (token + password would land in the
 * same access-log line, which defeats the password). So `POST /share/unlock`
 * exchanges the password for this grant, and the bytes GET carries the grant
 * instead — exactly the StreamToken/ImageToken pattern: a tiny HS256 JWT signed
 * with FLUXFILES_SECRET, short TTL, scoped to a single object.
 *
 * A distinct `t=share_grant` type keeps it unusable on /stream or /img (and
 * theirs unusable here), and the `jti` binding keeps a grant for share A from
 * unlocking share B.
 */
final class ShareGrant
{
    /** Token type marker, distinct from `stream` / `img` and the main access JWT. */
    private const TYPE = 'share_grant';

    /** Default lifetime of a grant (the recipient's unlock → download window). */
    public const DEFAULT_TTL = 600; // 10 min

    /** Hard ceiling on TTL regardless of the requested value. */
    public const MAX_TTL = 3600; // 1h

    /**
     * Mint a grant for one share.
     *
     * @param string $jti    The share record id (from the share token's payload)
     * @param int    $ttl    Seconds until expiry (clamped to [1, MAX_TTL]; 0 = default)
     * @param string $secret FLUXFILES_SECRET
     */
    public static function mint(string $jti, int $ttl, string $secret): string
    {
        $ttl = $ttl > 0 ? min($ttl, self::MAX_TTL) : self::DEFAULT_TTL;
        $now = time();
        return JwtCompat::encode([
            't'   => self::TYPE,
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $ttl,
        ], $secret);
    }

    /**
     * Verify a grant against the share it must belong to.
     * Throws ApiException(403, share_grant_invalid) on any failure (bad signature,
     * expired, wrong type, or minted for a different share).
     *
     * @return array{jti:string,exp:int}
     */
    public static function verify(string $grant, string $jti, string $secret): array
    {
        if ($grant === '' || $secret === '' || $jti === '') {
            throw new ApiException('Unlock required', 403, 'share_grant_invalid');
        }
        try {
            $p = JwtCompat::decode($grant, $secret);
        } catch (\Throwable $e) {
            // Expired / bad signature / malformed — all collapse to one opaque error.
            throw new ApiException('Unlock expired — please enter the password again', 403, 'share_grant_invalid');
        }
        if (($p->t ?? null) !== self::TYPE) {
            throw new ApiException('Not an unlock token', 403, 'share_grant_invalid');
        }
        if (!hash_equals($jti, (string) ($p->jti ?? ''))) {
            // A grant is bound to ONE share; it can't be moved to another link.
            throw new ApiException('Unlock token is for a different link', 403, 'share_grant_invalid');
        }
        return ['jti' => $jti, 'exp' => (int) ($p->exp ?? 0)];
    }
}

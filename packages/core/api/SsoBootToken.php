<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * One-time bootstrap token handed to the browser after a successful SSO login,
 * via the URL *fragment* (`#boot=<this>`) — never the query string, so it never
 * reaches server access logs or the `Referer` header. It nests the fully-signed
 * main access JWT (already minted via fluxfiles_token()) inside a `t=sso_boot`
 * wrapper.
 *
 * This is safe to put in the URL at all only because
 * JwtMiddleware::assertAccessToken() unconditionally rejects any token carrying
 * `t` as a main `Authorization: Bearer` token — so a leaked boot token can never
 * be replayed as the real access token, identical enforcement to StreamToken/
 * ImageToken/share tokens. The client exchanges it for the real JWT via
 * POST /api/fm/sso/exchange, which is the only way the real JWT is ever
 * transmitted.
 *
 * No server-side session store exists, so true single-use isn't enforced — v1
 * relies on the short TTL alone, the same posture as StreamToken/ImageToken
 * (neither of which enforce single-use either).
 */
final class SsoBootToken
{
    private const TYPE = 'sso_boot';

    /** Hard ceiling on TTL — the client is expected to exchange this immediately. */
    public const MAX_TTL = 60;

    public static function mint(string $realJwt, string $secret, int $ttl = self::MAX_TTL): string
    {
        $ttl = max(1, min($ttl, self::MAX_TTL));
        $now = time();
        return JwtCompat::encode([
            't'        => self::TYPE,
            'jti'      => bin2hex(random_bytes(8)),
            'real_jwt' => $realJwt,
            'iat'      => $now,
            'exp'      => $now + $ttl,
        ], $secret);
    }

    /**
     * Verify a boot token and return the real access JWT it carries.
     * Throws ApiException(403) on any failure (bad signature, expired, wrong type).
     */
    public static function verify(string $token, string $secret): string
    {
        if ($token === '' || $secret === '') {
            throw new ApiException('SSO boot token required', 403, 'sso_boot_token_invalid');
        }
        try {
            $p = JwtCompat::decode($token, $secret);
        } catch (\Throwable $e) {
            throw new ApiException('SSO boot token invalid or expired', 403, 'sso_boot_token_invalid');
        }

        if (($p->t ?? null) !== self::TYPE) {
            throw new ApiException('Not an SSO boot token', 403, 'sso_boot_token_invalid');
        }
        $realJwt = (string) ($p->real_jwt ?? '');
        if ($realJwt === '') {
            throw new ApiException('SSO boot token missing payload', 403, 'sso_boot_token_invalid');
        }
        return $realJwt;
    }
}

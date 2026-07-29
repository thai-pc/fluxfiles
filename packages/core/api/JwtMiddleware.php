<?php

declare(strict_types=1);

namespace FluxFiles;

class JwtMiddleware
{
    public static function handle(string $token, string $secret): Claims
    {
        try {
            $payload = JwtCompat::decode($token, $secret);
        } catch (\Exception $e) {
            error_log('FluxFiles JWT error: ' . $e->getMessage());
            throw new ApiException('Invalid or expired token', 401, 'token_invalid');
        }

        self::assertAccessToken($payload);

        return Claims::fromJwtPayload($payload, $secret);
    }

    /**
     * Refuse anything that is NOT a main API access token.
     *
     * Every narrow, single-purpose token FluxFiles hands to an untrusted party is
     * signed with the same FLUXFILES_SECRET (StreamToken `t=stream`, ImageToken
     * `t=img`, ShareGrant `t=share_grant`, the share link `t=share`, the intake
     * portal `t=intake`). They are only safe because their OWN endpoint enforces
     * them — a share link's password, download cap, expiry and revocation all live
     * in `/api/fm/share/*`. Without this check a recipient could replay the share
     * token as `Authorization: Bearer` on `/api/fm/content`, `/zip` or `/presign`
     * and read the file with none of that enforcement (and revocation, which only
     * deletes the storage record, would not stop them until the JWT expired).
     *
     * The rule is positive: a main access token never carries a type marker. The
     * legacy `share` / `intake` booleans are checked too, so tokens minted before
     * the `t` marker existed are refused as well.
     */
    private static function assertAccessToken(object $payload): void
    {
        if (isset($payload->t) || !empty($payload->share) || !empty($payload->intake)) {
            throw new ApiException(
                'This token is not an API access token',
                403,
                'token_not_access'
            );
        }
    }

    public static function extractToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new ApiException('Missing or malformed Authorization header', 401, 'token_missing');
        }

        return $matches[1];
    }
}

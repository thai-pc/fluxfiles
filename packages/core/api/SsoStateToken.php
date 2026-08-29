<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Short-lived token carried as the OIDC `state` param through the IdP redirect.
 *
 * The IdP echoes `state` back verbatim on the callback, so this doubles as CSRF
 * protection (an attacker can't forge a state signed with FLUXFILES_SECRET) and
 * carries the post-login `redirect` target. The same `nonce` value is also sent
 * to the IdP as the OIDC `nonce` param, so the callback can bind the returned
 * `id_token` back to this specific login attempt (replay protection).
 */
final class SsoStateToken
{
    private const TYPE = 'sso_state';

    /** Hard ceiling on TTL — a login round-trip is expected to take seconds. */
    public const MAX_TTL = 600; // 10 minutes

    /**
     * @param string $redirect Same-origin relative path to return to after login
     */
    public static function mint(string $nonce, string $redirect, string $secret, int $ttl = self::MAX_TTL): string
    {
        $ttl = max(1, min($ttl, self::MAX_TTL));
        $now = time();
        return JwtCompat::encode([
            't'        => self::TYPE,
            'nonce'    => $nonce,
            'redirect' => $redirect,
            'iat'      => $now,
            'exp'      => $now + $ttl,
        ], $secret);
    }

    /**
     * Verify a state token and return its scoped {nonce, redirect}.
     * Throws ApiException(403) on any failure (bad signature, expired, wrong type).
     *
     * @return array{nonce:string,redirect:string}
     */
    public static function verify(string $token, string $secret): array
    {
        if ($token === '' || $secret === '') {
            throw new ApiException('SSO state token required', 403, 'sso_state_token_invalid');
        }
        try {
            $p = JwtCompat::decode($token, $secret);
        } catch (\Throwable $e) {
            throw new ApiException('SSO state token invalid or expired', 403, 'sso_state_token_invalid');
        }

        if (($p->t ?? null) !== self::TYPE) {
            throw new ApiException('Not an SSO state token', 403, 'sso_state_token_invalid');
        }
        $nonce = (string) ($p->nonce ?? '');
        if ($nonce === '') {
            throw new ApiException('SSO state token missing nonce', 403, 'sso_state_token_invalid');
        }
        return ['nonce' => $nonce, 'redirect' => (string) ($p->redirect ?? '')];
    }
}

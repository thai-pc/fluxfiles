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
 * The nested JWT is ENCRYPTED (AES-256-GCM, key HKDF-derived from the secret),
 * not merely signed: a JWT payload is only base64, so a plain nested `real_jwt`
 * let anyone who saw the fragment (a proxy/APM logging the callback's 302
 * `Location`, a browser extension, a screen share) decode the real, full-TTL
 * access token directly — no exchange call, and no 60s window at all.
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

    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;
    /** Distinct from CredentialEncryptor's info string → an independent key. */
    private const HKDF_INFO = 'fluxfiles-sso-boot-enc';

    public static function mint(string $realJwt, string $secret, int $ttl = self::MAX_TTL): string
    {
        $ttl = max(1, min($ttl, self::MAX_TTL));
        $now = time();
        return JwtCompat::encode([
            't'   => self::TYPE,
            'jti' => bin2hex(random_bytes(8)),
            'enc' => self::seal($realJwt, $secret),
            'iat' => $now,
            'exp' => $now + $ttl,
        ], $secret);
    }

    /**
     * Verify a boot token and return the real access JWT it carries.
     * Throws ApiException(403) on any failure (bad signature, expired, wrong
     * type, missing/undecryptable payload).
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
        $realJwt = self::open((string) ($p->enc ?? ''), $secret);
        if ($realJwt === null || $realJwt === '') {
            throw new ApiException('SSO boot token missing payload', 403, 'sso_boot_token_invalid');
        }
        return $realJwt;
    }

    private static function key(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::HKDF_INFO);
    }

    private static function seal(string $plaintext, string $secret): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, self::key($secret), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new \RuntimeException('SSO boot token encryption failed');
        }
        return rtrim(strtr(base64_encode($nonce . $ct . $tag), '+/', '-_'), '=');
    }

    private static function open(string $blob, string $secret): ?string
    {
        if ($blob === '') {
            return null;
        }
        $raw = base64_decode(strtr($blob, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN + 1) {
            return null;
        }
        $pt = openssl_decrypt(
            substr($raw, self::NONCE_LEN, -self::TAG_LEN),
            self::CIPHER,
            self::key($secret),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_LEN),
            substr($raw, -self::TAG_LEN)
        );
        return $pt === false ? null : $pt;
    }
}

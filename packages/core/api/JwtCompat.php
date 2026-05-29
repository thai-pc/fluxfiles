<?php

declare(strict_types=1);

namespace FluxFiles;

use Firebase\JWT\JWT;

/**
 * Compatibility shim for firebase/php-jwt versions 5.x → 7.x.
 *
 * The library introduced a `Key` wrapper class in v6 and changed the
 * JWT::decode() signature: it no longer takes (string $key, array $algs)
 * and instead takes a Key or array<string,Key>. We hide that difference
 * here so the rest of FluxFiles can call one stable interface and the
 * composer constraint can keep accepting both v5 (for PHP 7.4 users)
 * and v6/v7 (for PHP 8+ users).
 *
 * encode() did NOT change between v5 and v6+, so we forward it as-is.
 */
final class JwtCompat
{
    private function __construct()
    {
    }

    /**
     * Encode an HS256-signed JWT.
     *
     * @param array<string,mixed> $payload
     */
    public static function encode(array $payload, string $secret): string
    {
        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Decode an HS256-signed JWT.
     *
     * v6+ requires a Key object; v5 accepts the secret string + allowed algs array.
     * We detect which library is installed via class_exists() so the call site
     * does not need to know.
     *
     * @return \stdClass
     */
    public static function decode(string $token, string $secret)
    {
        if (\class_exists('\\Firebase\\JWT\\Key')) {
            // v6 / v7 path — wrap secret in Key(<secret>, <alg>).
            $keyClass = '\\Firebase\\JWT\\Key';
            return JWT::decode($token, new $keyClass($secret, 'HS256'));
        }
        // v5 path — pass secret string + allowed-algs array.
        return JWT::decode($token, $secret, ['HS256']);
    }
}

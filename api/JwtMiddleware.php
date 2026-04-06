<?php

declare(strict_types=1);

namespace FluxFiles;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtMiddleware
{
    public static function handle(string $token, string $secret): Claims
    {
        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Exception $e) {
            error_log('FluxFiles JWT error: ' . $e->getMessage());
            throw new ApiException('Invalid or expired token', 401, 'token_invalid');
        }

        return Claims::fromJwtPayload($payload, $secret);
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

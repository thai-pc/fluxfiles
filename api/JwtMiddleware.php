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
            throw new ApiException('Invalid or expired token: ' . $e->getMessage(), 401);
        }

        return Claims::fromJwtPayload($payload);
    }

    public static function extractToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new ApiException('Missing or malformed Authorization header', 401);
        }

        return $matches[1];
    }
}

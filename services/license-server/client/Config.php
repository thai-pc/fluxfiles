<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Client;

final class Config
{
    public static function get(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function secret(string $name): string
    {
        $file = self::get($name . '_FILE');
        return $file !== '' ? trim((string) @file_get_contents($file)) : self::get($name);
    }

    public static function sandbox(): bool
    {
        return self::get('FLUXFILES_LICENSE_INSTANCE', 'production') !== 'production';
    }

    public static function api(): string
    {
        return self::loopback(
            self::get(
                'FLUXFILES_ADMIN_API',
                'http://127.0.0.1:' . (self::sandbox() ? '9473' : '9471'),
            ),
        );
    }

    /** Literal loopback only: DNS, redirects, userinfo and URL query tricks cannot receive credentials. */
    public static function loopback(string $url): string
    {
        $p = parse_url($url);
        if (
            !is_array($p) ||
            ($p['scheme'] ?? '') !== 'http' ||
            !in_array($p['host'] ?? '', ['127.0.0.1', '[::1]'], true) ||
            isset($p['user'], $p['pass']) ||
            isset($p['user']) ||
            isset($p['pass']) ||
            isset($p['query']) ||
            isset($p['fragment']) ||
            strpbrk($url, "\\\r\n@") !== false
        ) {
            throw new \InvalidArgumentException('invalid loopback URL');
        }
        return rtrim($url, '/');
    }
}

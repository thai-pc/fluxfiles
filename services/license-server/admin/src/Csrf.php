<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

final class Csrf
{
    public static function host(string $host, string $allow): bool
    {
        if (!preg_match('/^(localhost|127\.0\.0\.1)(?::([0-9]{1,5}))?$/D', $host, $m)) {
            return false;
        }
        return (!isset($m[2]) || ((int) $m[2] > 0 && (int) $m[2] <= 65535)) && in_array($m[1], array_map('trim', explode(',', $allow)), true);
    }

    public static function check(array $server, string $expected, string $actual): bool
    {
        $origin = (isset($server['HTTPS']) && $server['HTTPS'] !== 'off'?'https': 'http') . '://' . ($server['HTTP_HOST'] ?? '');
        return $expected !== '' && hash_equals($expected, $actual) && hash_equals($origin, (string) ($server['HTTP_ORIGIN'] ?? '')) && (!isset($server['HTTP_SEC_FETCH_SITE']) || $server['HTTP_SEC_FETCH_SITE'] === 'same-origin');
    }
}

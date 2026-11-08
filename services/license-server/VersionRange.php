<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/** A deliberately small advisory grammar; malformed constraints never silently match. */
final class VersionRange
{
    public static function validVersion(string $version): bool
    {
        return preg_match(
            '/^v?\d{1,4}(?:\.\d{1,4}){1,3}(?:[-+][0-9A-Za-z.-]{1,40})?$/D',
            $version,
        ) === 1;
    }

    public static function valid(string $constraint): bool
    {
        if ($constraint === '' || strlen($constraint) > 500) {
            return false;
        }
        foreach (explode('||', $constraint) as $group) {
            $tokens = preg_split('/\s+/', trim($group)) ?: [];
            if ($tokens === []) {
                return false;
            }
            foreach ($tokens as $token) {
                if (
                    !preg_match('/^(?:<=|>=|!=|=|<|>)?(.+)$/D', $token, $match) ||
                    !self::validVersion($match[1])
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function normalize(string $version): string
    {
        $version = explode('+', ltrim($version, 'v'), 2)[0];
        $parts = explode('-', $version, 2);
        $numbers = array_map('intval', explode('.', $parts[0]));
        return implode('.', array_pad($numbers, 4, 0)) . (isset($parts[1]) ? '-' . $parts[1] : '');
    }

    public static function matches(string $version, string $constraint): bool
    {
        if (!self::validVersion($version) || !self::valid($constraint)) {
            return false;
        }
        foreach (explode('||', $constraint) as $group) {
            $all = true;
            foreach (preg_split('/\s+/', trim($group)) ?: [] as $token) {
                preg_match('/^(<=|>=|!=|=|<|>)?(.+)$/D', $token, $m);
                if (
                    !version_compare(
                        self::normalize($version),
                        self::normalize($m[2]),
                        $m[1] ?? '' ?: '=',
                    )
                ) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }
}

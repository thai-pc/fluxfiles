<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/** A deliberately small advisory grammar; malformed constraints never silently match. */
final class VersionRange
{
    public static function validVersion(string $version): bool
    {
        return preg_match('/^v?\d{1,4}(?:\.\d{1,4}){1,3}(?:[-+][0-9A-Za-z.-]{1,40})?$/D', $version) === 1;
    }

    public static function valid(string $constraint): bool
    {
        if ($constraint === '' || strlen($constraint) > 500) { return false; }
        foreach (explode('||', $constraint) as $group) {
            $tokens = preg_split('/\s+/', trim($group)) ?: [];
            if ($tokens === []) { return false; }
            foreach ($tokens as $token) {
                if (!preg_match('/^(?:<=|>=|!=|=|<|>)?(.+)$/D', $token, $match) || !self::validVersion($match[1])) { return false; }
            }
        }
        return true;
    }

    public static function matches(string $version, string $constraint): bool
    {
        if (!self::validVersion($version) || !self::valid($constraint)) { return false; }
        foreach (explode('||', $constraint) as $group) {
            $all = true;
            foreach (preg_split('/\s+/', trim($group)) ?: [] as $token) {
                preg_match('/^(<=|>=|!=|=|<|>)?(.+)$/D', $token, $m);
                if (!version_compare(ltrim($version, 'v'), ltrim($m[2], 'v'), ($m[1] ?? '') ?: '=')) { $all = false; break; }
            }
            if ($all) { return true; }
        }
        return false;
    }
}

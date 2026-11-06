<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The running core's own version, plus a minimal Composer-style constraint check.
 *
 * Paid modules declare the core they need (`require["fluxfiles/fluxfiles"]`), but
 * Composer only enforces that at `composer require` time. The self-hosted update
 * channel (`fluxfiles update <module>`) bypasses Composer, so UpdateClient and the
 * update server compare against this instead. composer/semver is not a dependency
 * of core, so the subset the module constraints use is implemented here:
 * `^`, `~`, `>=`/`>`/`<=`/`<`/`=`, `1.2.*`, space/comma AND, `||`/`|` OR.
 */
final class CoreVersion
{
    /**
     * The installed core version as a plain `x.y.z`, or null when it cannot be
     * known (a git checkout, `dev-*`, or no Composer metadata).
     */
    public static function current(): ?string
    {
        if (defined('FLUXFILES_CORE_VERSION')) {
            $v = self::normalize((string) constant('FLUXFILES_CORE_VERSION'));
            if ($v !== null) {
                return $v;
            }
        }
        if (class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('fluxfiles/fluxfiles')) {
            return self::normalize((string) \Composer\InstalledVersions::getPrettyVersion('fluxfiles/fluxfiles'));
        }
        return null;
    }

    /** `v1.0.4`, `core-v1.0.4`, `1.0.4-rc1` -> a comparable string; `dev-master` -> null. */
    public static function normalize(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(?:core-)?v?(\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.]+)?)$/', $v, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * True when $version satisfies $constraint. An unparseable constraint is
     * treated as unsatisfied: a release we cannot reason about is not installed.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        if ($version === null) {
            return false;
        }
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alt) {
            // Re-join "op version" pairs split by whitespace (">= 1.0.3").
            $alt = preg_replace('/([<>=~^]+)\s+/', '$1', trim($alt));
            $terms = preg_split('/[\s,]+/', (string) $alt) ?: [];
            $ok = $terms !== [];
            foreach ($terms as $t) {
                $r = self::term($version, $t);
                if ($r === null) {
                    return false; // unparseable
                }
                if (!$r) {
                    $ok = false;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** @return bool|null null = could not parse the term */
    private static function term(string $v, string $t): ?bool
    {
        if ($t === '*') {
            return true;
        }
        if (preg_match('/^(\^|~|>=|<=|>|<|==|=)?v?(\d+(?:\.(?:\d+|\*)){0,2}(?:\.\*)?)(?:-[0-9A-Za-z.]+)?$/', $t, $m) !== 1) {
            return null;
        }
        $op = $m[1];
        $raw = $m[2];
        if (str_contains($raw, '*')) {
            $p = array_map('intval', explode('.', rtrim(str_replace('.*', '', $raw), '.')));
            $lo = implode('.', array_pad($p, 3, 0));
            $hi = $p[0] . '.' . (isset($p[1]) ? $p[1] + 1 : 0) . '.0';
            if (!isset($p[1])) {
                $hi = ($p[0] + 1) . '.0.0';
            }
            return version_compare($v, $lo, '>=') && version_compare($v, $hi, '<');
        }
        $parts = array_map('intval', explode('.', $raw));
        $n = count($parts);
        $base = implode('.', array_pad($parts, 3, 0));
        switch ($op) {
            case '^':
                if ($parts[0] > 0 || $n === 1) {
                    $hi = ($parts[0] + 1) . '.0.0';
                } elseif (($parts[1] ?? 0) > 0 || $n === 2) {
                    $hi = '0.' . (($parts[1] ?? 0) + 1) . '.0';
                } else {
                    $hi = '0.0.' . (($parts[2] ?? 0) + 1);
                }
                return version_compare($v, $base, '>=') && version_compare($v, $hi, '<');
            case '~':
                $hi = $n === 1 ? ($parts[0] + 1) . '.0.0'
                    : ($n === 2 ? ($parts[0] + 1) . '.0.0' : $parts[0] . '.' . ($parts[1] + 1) . '.0');
                return version_compare($v, $base, '>=') && version_compare($v, $hi, '<');
            case '>=':
                return version_compare($v, $base, '>=');
            case '<=':
                return version_compare($v, $base, '<=');
            case '>':
                return version_compare($v, $base, '>');
            case '<':
                return version_compare($v, $base, '<');
            default:
                return version_compare($v, $base, '==');
        }
    }
}

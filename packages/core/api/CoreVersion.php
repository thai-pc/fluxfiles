<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment/server (not user input); core is a WordPress-independent library

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
     * known. Order: the `FLUXFILES_CORE_VERSION` env var (the operator's override for
     * git / release-zip / Docker installs, where Composer's root package is `dev-*`),
     * then Composer's metadata for a `composer require fluxfiles/fluxfiles` install.
     */
    public static function current(): ?string
    {
        $env = $_ENV['FLUXFILES_CORE_VERSION'] ?? getenv('FLUXFILES_CORE_VERSION');
        if (is_string($env) && $env !== '') {
            $v = self::normalize($env);
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

    /**
     * `v1.0.4`, `core-v1.0.4`, `1.0.4-rc1` -> a comparable string, padded to three
     * numeric segments (`1.0` -> `1.0.0`, since PHP orders `1.0 < 1.0.0`);
     * `dev-master` -> null.
     */
    public static function normalize(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(?:core-)?v?(\d+(?:\.\d+){0,2})(-[0-9A-Za-z.]+)?$/', $v, $m) !== 1) {
            return null;
        }
        return implode('.', array_pad(explode('.', $m[1]), 3, '0')) . ($m[2] ?? '');
    }

    /**
     * True when $version satisfies $constraint. Fails closed: any part of the
     * constraint that cannot be parsed makes the whole constraint unsatisfied,
     * regardless of where it sits (`^1.0 || garbage` is false, not "true via the
     * first alternative"). A release we cannot reason about is not installed.
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
        $alts = self::parseConstraint($constraint);
        if ($alts === null) {
            return false;
        }
        foreach ($alts as $terms) {
            $ok = true;
            foreach ($terms as [$lo, $loOp, $hi]) {
                // Upper bounds are exclusive of "$hi-dev", as in Composer: 2.0.0-rc1 is NOT below ^1.0.
                if ($lo !== null && !version_compare($version, $lo, $loOp)) { $ok = false; break; }
                if ($hi !== null && !version_compare($version, $hi . '-dev', '<')) { $ok = false; break; }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** True when every alternative and term of $constraint parses (used at pack time). */
    public static function isValid(string $constraint): bool
    {
        $constraint = trim($constraint);
        return $constraint === '*' || ($constraint !== '' && self::parseConstraint($constraint) !== null);
    }

    /**
     * Parse EVERYTHING up front; one bad alternative or term rejects the lot.
     *
     * @return list<list<array{0:?string,1:string,2:?string}>>|null
     */
    private static function parseConstraint(string $constraint): ?array
    {
        $alts = [];
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alt) {
            // Re-join "op version" pairs split by whitespace (">= 1.0.3").
            $alt = preg_replace('/([<>=~^]+)\s+/', '$1', trim($alt));
            $terms = preg_split('/[\s,]+/', (string) $alt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($terms === []) {
                return null; // empty alternative ("^1.0 ||")
            }
            $parsed = [];
            foreach ($terms as $t) {
                $range = self::parseTerm($t);
                if ($range === null) {
                    return null;
                }
                $parsed[] = $range;
            }
            $alts[] = $parsed;
        }
        return $alts === [] ? null : $alts;
    }

    /**
     * One term as a range [lower|null, lowerOp, upper|null] (upper is exclusive, compared
     * against "$upper-dev"; a single-version `=` term is [v, '==', null]).
     *
     * @return array{0:?string,1:string,2:?string}|null null = could not parse the term
     */
    private static function parseTerm(string $t): ?array
    {
        if ($t === '*') {
            return [null, '>=', null];
        }
        if (preg_match('/^(\^|~|>=|<=|>|<|==|=)?v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?(?:-[0-9A-Za-z.]+)?$/', $t, $m) !== 1) {
            return null;
        }
        $op = $m[1];
        $parts = [(int) $m[2]];
        $wild = false;
        foreach ([$m[3] ?? null, $m[4] ?? null] as $seg) {
            if ($seg === null) {
                continue;
            }
            if ($seg === '*') {
                $wild = true;
            } elseif ($wild) {
                return null; // "1.*.3": a number after a wildcard
            } else {
                $parts[] = (int) $seg;
            }
        }
        if ($wild && $op !== '') {
            return null; // a wildcard takes no operator ("<1.2.*", "^1.*")
        }
        $n = count($parts);
        $base = implode('.', array_pad($parts, 3, 0));
        if ($wild) {
            // "1.*" / "1.2.*": [base, next) -- n is the count of fixed segments.
            $hi = $n === 1 ? ($parts[0] + 1) . '.0.0' : $parts[0] . '.' . ($parts[1] + 1) . '.0';
            return [$base, '>=', $hi];
        }
        switch ($op) {
            case '^':
                if ($parts[0] > 0 || $n === 1) {
                    $hi = ($parts[0] + 1) . '.0.0';
                } elseif (($parts[1] ?? 0) > 0 || $n === 2) {
                    $hi = '0.' . (($parts[1] ?? 0) + 1) . '.0';
                } else {
                    $hi = '0.0.' . (($parts[2] ?? 0) + 1);
                }
                return [$base, '>=', $hi];
            case '~':
                $hi = $n <= 2 ? ($parts[0] + 1) . '.0.0' : $parts[0] . '.' . ($parts[1] + 1) . '.0';
                return [$base, '>=', $hi];
            case '>=':
                return [$base, '>=', null];
            case '>':
                // ">1.2" means "from 1.3.0-dev" in Composer; ">1.2.3" is plain.
                if ($n < 3) {
                    $next = $n === 1 ? ($parts[0] + 1) . '.0.0' : $parts[0] . '.' . ($parts[1] + 1) . '.0';
                    return [$next . '-dev', '>=', null];
                }
                return [$base, '>', null];
            case '<':
                return [null, '>=', $base];
            case '<=':
                if ($n < 3) {
                    $next = $n === 1 ? ($parts[0] + 1) . '.0.0' : $parts[0] . '.' . ($parts[1] + 1) . '.0';
                    return [null, '>=', $next];
                }
                // "<=1.2.3" == below the next patch's -dev.
                return [null, '>=', $parts[0] . '.' . $parts[1] . '.' . ($parts[2] + 1)];
            default:
                return [$base, '==', null];
        }
    }
}

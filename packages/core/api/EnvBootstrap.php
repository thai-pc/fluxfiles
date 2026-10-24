<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Loads FluxFiles' server configuration into $_ENV, where core reads it.
 *
 * Two sources: real environment variables (php-fpm `env[...]`, Kubernetes,
 * systemd, `php -S`, CLI) and the `.env` file. Core reads $_ENV only, and with
 * the production `variables_order = GPCS` PHP leaves $_ENV empty, so a real
 * env var never reached core. Worse, Dotenv's immutable loader sees that same
 * var in $_SERVER and skips the `.env` value too, so the setting was lost
 * outright (an empty FLUXFILES_SECRET, an unlicensed install).
 *
 * The fix: copy the real env vars core uses into $_ENV first, then let Dotenv
 * fill in whatever is still missing from `.env`. A non-empty real env var
 * therefore wins over `.env`, the same rule the Laravel and WordPress adapters
 * follow. Only the prefixes core reads are copied, never the whole process
 * environment ($_ENV can surface in phpinfo() or error pages).
 */
final class EnvBootstrap
{
    /** Name prefixes core and the paid modules read (same list docker/entrypoint.sh materialises). */
    public const PREFIXES = ['FLUXFILES_', 'AWS_', 'R2_', 'SFTP_'];

    /**
     * Copy real environment variables with a known prefix into $_ENV, keeping
     * any key $_ENV already has. Returns how many keys it filled.
     *
     * @param array<string,string>|null $source the process environment (getenv()); injectable for tests
     */
    public static function importProcessEnv(?array $source = null): int
    {
        $source ??= getenv();
        $filled = 0;
        foreach ($source as $key => $value) {
            if (!is_string($key) || !is_string($value) || $value === '' || array_key_exists($key, $_ENV)) {
                continue;
            }
            foreach (self::PREFIXES as $prefix) {
                if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                    $_ENV[$key] = $value;
                    $filled++;
                    break;
                }
            }
        }
        return $filled;
    }

    /**
     * Import the real environment, then load the first `.env` found in $dirs
     * (only values not already set). Returns the directory whose `.env` was
     * loaded, or null when there was none.
     *
     * @param array<int,string|false> $dirs
     */
    public static function load(array $dirs): ?string
    {
        self::importProcessEnv();
        foreach ($dirs as $dir) {
            if (is_string($dir) && file_exists(rtrim($dir, '/') . '/.env')) {
                \Dotenv\Dotenv::createImmutable($dir)->safeLoad();
                return $dir;
            }
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The single place that loads the static disk set.
 *
 * Default is the package's own config/disks.php. An operator can point
 * FLUXFILES_DISKS_CONFIG at an absolute path to their own PHP file (returning the
 * same array) to keep the disk config outside the package, so a
 * `composer require` install survives `composer update`. The file REPLACES the
 * default entirely; it can `require` the package's file and adjust it if it wants
 * the defaults. A set-but-broken path fails closed (never a silent fallback to the
 * default `local` disk). Only the operator's environment sets this, never a JWT
 * claim or a request. Call after EnvBootstrap::load().
 */
final class DiskConfig
{
    /**
     * @param string|null $defaultFile override of the package default (tests / relocated CLI only)
     * @return array<string,array<string,mixed>>
     */
    public static function load(?string $defaultFile = null): array
    {
        $path = trim((string) ($_ENV['FLUXFILES_DISKS_CONFIG'] ?? ''));
        if ($path === '') {
            $disks = require ($defaultFile ?? __DIR__ . '/../config/disks.php');
            return $disks;
        }

        // Absolute POSIX path, Windows drive path or UNC path.
        if (!str_starts_with($path, '/') && preg_match('~^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)~', $path) !== 1) {
            throw self::fail('FLUXFILES_DISKS_CONFIG must be an absolute path, got: ' . $path);
        }
        if (!is_file($path) || !is_readable($path)) {
            throw self::fail('FLUXFILES_DISKS_CONFIG points to a file that is missing or not readable: ' . $path);
        }
        ob_start();
        try {
            $disks = (static function (string $__file) {
                return require $__file;
            })($path);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw self::fail('FLUXFILES_DISKS_CONFIG file ' . $path . ' failed to load: ' . get_class($e) . ': ' . $e->getMessage());
        }
        $out = (string) ob_get_clean();
        if ($out !== '') {
            throw self::fail('FLUXFILES_DISKS_CONFIG file ' . $path . ' printed output (' . strlen($out) . ' bytes); a config file must only return the array');
        }
        if (!is_array($disks)) {
            throw self::fail('FLUXFILES_DISKS_CONFIG file must return an array of disks: ' . $path);
        }
        return $disks;
    }

    /** Log the detail for the operator; HTTP callers only ever see the generic message. */
    private static function fail(string $detail): DiskConfigException
    {
        error_log('[fluxfiles] ' . $detail);
        return new DiskConfigException($detail);
    }
}

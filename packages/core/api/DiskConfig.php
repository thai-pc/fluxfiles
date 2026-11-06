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

        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException('FLUXFILES_DISKS_CONFIG points to a file that is missing or not readable: ' . $path, 500);
        }
        try {
            $disks = (static function (string $__file) {
                return require $__file;
            })($path);
        } catch (\Throwable $e) {
            throw new ApiException('FLUXFILES_DISKS_CONFIG file failed to load: ' . $e->getMessage(), 500);
        }
        if (!is_array($disks)) {
            throw new ApiException('FLUXFILES_DISKS_CONFIG file must return an array of disks: ' . $path, 500);
        }
        return $disks;
    }
}

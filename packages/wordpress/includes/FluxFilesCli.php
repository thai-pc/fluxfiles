<?php

defined('ABSPATH') || exit;

use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;

/**
 * WP-CLI commands for FluxFiles.
 *
 * Registered only when WP-CLI is loaded. Mirrors the Laravel `fluxfiles:seed`
 * Artisan command so WordPress users with pre-existing upload folders can make
 * them searchable without going through the REST API.
 */
class FluxFilesCli
{
    /**
     * Seed FluxFiles metadata + directory index for files/folders that already
     * exist on a disk.
     *
     * After seeding, existing content becomes searchable through the FTS5
     * metadata index and the folder search endpoint.
     *
     * ## OPTIONS
     *
     * [--disk=<disk>]
     * : Disk name (local, s3, r2). Default: local.
     *
     * [--path=<path>]
     * : Limit seeding to this sub-path (e.g. "user_1").
     *
     * [--overwrite]
     * : Overwrite existing metadata entries (default: skip).
     *
     * [--dry-run]
     * : Report what would be done without writing.
     *
     * ## EXAMPLES
     *
     *     wp fluxfiles seed --disk=local --dry-run
     *     wp fluxfiles seed --disk=local
     *     wp fluxfiles seed --disk=local --path=user_1
     *     wp fluxfiles seed --disk=local --overwrite
     *
     * @when after_wp_load
     */
    public function seed($args, $assocArgs): void
    {
        $disk      = (string) ($assocArgs['disk'] ?? 'local');
        $path      = trim((string) ($assocArgs['path'] ?? ''), '/');
        $overwrite = array_key_exists('overwrite', $assocArgs);
        $dryRun    = array_key_exists('dry-run', $assocArgs);

        $diskConfigs = FluxFilesPlugin::diskConfigs();
        if (!isset($diskConfigs[$disk])) {
            \WP_CLI::error("Disk '{$disk}' is not configured. Check Settings → FluxFiles.");
            return;
        }

        $dm   = new DiskManager($diskConfigs);
        $meta = new StorageMetadataHandler($dm);
        $fs   = $dm->disk($disk);

        \WP_CLI::log("Seeding disk {$disk}" . ($path !== '' ? " at path {$path}" : ''));
        if ($dryRun) {
            \WP_CLI::warning('Dry-run mode — no files will be written.');
        }

        $fileCount = 0;
        $dirCount  = 0;
        $skipped   = 0;

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($path, true) as $item) {
            $key  = $item->path();
            $name = basename($key);

            // Skip FluxFiles internals and sidecar metadata
            if (str_contains($key, '_fluxfiles/')
                || str_contains($key, '_variants/')
                || str_ends_with($name, '.meta.json')) {
                continue;
            }

            if ($item instanceof FileAttributes) {
                if (!$overwrite && $meta->get($disk, $key) !== null) {
                    $skipped++;
                    continue;
                }

                $title = pathinfo($name, PATHINFO_FILENAME);

                if ($dryRun) {
                    \WP_CLI::log("  [file] {$key}  →  title=\"{$title}\"");
                } else {
                    $meta->save($disk, $key, [
                        'title'       => $title,
                        'alt_text'    => '',
                        'caption'     => '',
                        'tags'        => '',
                        'uploaded_by' => null,
                    ]);
                }
                $fileCount++;
            } else {
                if ($dryRun) {
                    \WP_CLI::log("  [dir]  {$key}");
                } else {
                    $meta->trackDir($disk, $key);
                }
                $dirCount++;
            }
        }

        \WP_CLI::success("Files indexed: {$fileCount}, folders indexed: {$dirCount}, skipped: {$skipped}.");
        if ($dryRun) {
            \WP_CLI::log('Re-run without --dry-run to apply.');
        }
    }
}

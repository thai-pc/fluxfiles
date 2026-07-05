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

    /**
     * Migrate existing local Media Library attachments INTO a FluxFiles disk and turn
     * them into offloaded, FluxFiles-backed attachments (folders + cloud + offload).
     *
     * The target disk must produce a STABLE public URL (local, or a public S3/R2) —
     * private buckets / SFTP serve expiring URLs and are rejected.
     *
     * ## OPTIONS
     *
     * [--disk=<disk>]
     * : Target disk. Default: local.
     *
     * [--path=<prefix>]
     * : Storage sub-path to place files under (e.g. "wp-media"). Default: root.
     *
     * [--limit=<n>]
     * : Only migrate the first N attachments (batching). Default: all.
     *
     * [--delete-local]
     * : Delete the local file after a successful upload (reclaim disk).
     *
     * [--dry-run]
     * : Report what would happen without uploading or changing anything.
     *
     * ## EXAMPLES
     *
     *     wp fluxfiles migrate --disk=s3 --path=wp-media --dry-run
     *     wp fluxfiles migrate --disk=s3 --path=wp-media
     *     wp fluxfiles migrate --disk=local --limit=100 --delete-local
     *
     * @when after_wp_load
     */
    public function migrate($args, $assocArgs): void
    {
        require_once __DIR__ . '/FluxFilesAttachments.php';
        $disk   = (string) ($assocArgs['disk'] ?? 'local');
        $prefix = trim((string) ($assocArgs['path'] ?? ''), '/');
        $limit  = (int) ($assocArgs['limit'] ?? 0);
        $delLocal = array_key_exists('delete-local', $assocArgs);
        $dryRun = array_key_exists('dry-run', $assocArgs);

        $configs = FluxFilesPlugin::diskConfigs();
        if (!isset($configs[$disk])) {
            \WP_CLI::error("Disk '{$disk}' is not configured.");
            return;
        }
        // Reject disks that can't produce a stable URL (offload would break on expiry).
        if (FluxFilesAttachments::stableUrl($configs[$disk], 'probe.txt') === null) {
            \WP_CLI::error("Disk '{$disk}' can't serve stable public URLs (private bucket / SFTP / gated local). "
                . 'Offload needs a public S3/R2 disk or a public local disk.');
            return;
        }

        // Attachments that are NOT already FluxFiles-backed and have a local file.
        $ids = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields' => 'ids', 'meta_query' => [['key' => '_fluxfiles_url', 'compare' => 'NOT EXISTS']],
        ]);
        $fs = new \FluxFiles\DiskManager($configs);
        $target = $fs->disk($disk);

        $done = 0; $skipped = 0; $failed = 0;
        foreach ($ids as $id) {
            $local = get_attached_file($id);
            if (!$local || !is_file($local)) { $skipped++; continue; }
            $name = basename($local);
            $key  = ($prefix !== '' ? $prefix . '/' : '') . $id . '-' . $name;
            $url  = FluxFilesAttachments::stableUrl($configs[$disk], $key);

            if ($dryRun) {
                \WP_CLI::log("  [would migrate] #{$id} {$name} → {$disk}:{$key}");
                $done++;
                continue;
            }
            try {
                $target->write($key, (string) file_get_contents($local));
                update_post_meta($id, '_fluxfiles_url', esc_url_raw((string) $url));
                update_post_meta($id, '_fluxfiles_key', $key);
                update_post_meta($id, '_fluxfiles_disk', $disk);
                if ($delLocal) { @unlink($local); }
                $done++;
            } catch (\Throwable $e) {
                \WP_CLI::warning("#{$id} failed: " . $e->getMessage());
                $failed++;
            }
        }
        \WP_CLI::success("Migrated: {$done}, skipped (no local file): {$skipped}, failed: {$failed}.");
        if ($dryRun) { \WP_CLI::log('Re-run without --dry-run to apply.'); }
    }

    /**
     * Verify FluxFiles-backed attachments still point at existing files (the
     * FluxFiles→WP delete-sync direction). Reports — or removes — dead attachments
     * whose storage file is gone.
     *
     * ## OPTIONS
     *
     * [--delete]
     * : Delete attachments whose backing file no longer exists.
     *
     * [--dry-run]
     * : Only report (default behaviour without --delete).
     *
     * ## EXAMPLES
     *
     *     wp fluxfiles verify
     *     wp fluxfiles verify --delete
     *
     * @when after_wp_load
     */
    public function verify($args, $assocArgs): void
    {
        require_once __DIR__ . '/FluxFilesAttachments.php';
        $delete = array_key_exists('delete', $assocArgs);

        $ids = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => '_fluxfiles_url', 'compare' => 'EXISTS']],
        ]);
        $configs = FluxFilesPlugin::diskConfigs();
        $dm = new \FluxFiles\DiskManager($configs);

        $ok = 0; $dead = 0; $removed = 0;
        foreach ($ids as $id) {
            $loc = FluxFilesAttachments::locate((int) $id);
            if ($loc === null || !isset($configs[$loc['disk']])) { continue; }
            try {
                if ($dm->disk($loc['disk'])->fileExists($loc['key'])) { $ok++; continue; }
            } catch (\Throwable) {
                continue; // storage unreachable → don't touch anything
            }
            $dead++;
            \WP_CLI::log("  [dead] #{$id} → {$loc['disk']}:{$loc['key']}");
            if ($delete) { wp_delete_attachment((int) $id, true); $removed++; }
        }
        \WP_CLI::success("Live: {$ok}, dead: {$dead}" . ($delete ? ", removed: {$removed}" : ' (use --delete to remove)') . '.');
    }
}

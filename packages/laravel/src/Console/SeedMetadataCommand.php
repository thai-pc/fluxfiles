<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Console;

use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;
use Illuminate\Console\Command;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;

/**
 * Seed FluxFiles metadata + directory index for files/folders that already
 * exist on a disk (e.g. public/uploads/user_1/... created before FluxFiles
 * was installed).
 *
 * After seeding, existing content becomes searchable through the FTS5
 * metadata index and the folder search endpoint.
 */
class SeedMetadataCommand extends Command
{
    protected $signature = 'fluxfiles:seed
        {--disk=local : Disk name defined in config/fluxfiles.php}
        {--path= : Limit seeding to this sub-path (e.g. "user_1")}
        {--overwrite : Overwrite existing metadata entries (default: skip)}
        {--dry-run : Report what would be done without writing}';

    protected $description = 'Index existing files + folders on a disk so they appear in FluxFiles search.';

    public function handle(): int
    {
        $disk      = (string) $this->option('disk');
        $path      = trim((string) $this->option('path'), '/');
        $overwrite = (bool) $this->option('overwrite');
        $dryRun    = (bool) $this->option('dry-run');

        $diskConfigs = config('fluxfiles.disks');
        if (!isset($diskConfigs[$disk])) {
            $this->error("Disk '{$disk}' is not configured in config/fluxfiles.php.");
            return self::FAILURE;
        }

        $dm   = new DiskManager($diskConfigs);
        $meta = new StorageMetadataHandler($dm);
        $fs   = $dm->disk($disk);

        $this->line("Seeding disk <info>{$disk}</info>" . ($path !== '' ? " at path <info>{$path}</info>" : ''));
        if ($dryRun) {
            $this->warn('Dry-run mode — no files will be written.');
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
                    $this->line("  [file] {$key}  →  title=\"{$title}\"");
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
                // Directory
                if ($dryRun) {
                    $this->line("  [dir]  {$key}");
                } else {
                    $meta->trackDir($disk, $key);
                }
                $dirCount++;
            }
        }

        $this->info("Done. Files indexed: {$fileCount}, folders indexed: {$dirCount}, skipped (already had metadata): {$skipped}.");
        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}

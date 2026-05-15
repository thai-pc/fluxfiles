<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Console;

use FluxFiles\DiskManager;
use FluxFiles\ExistingFileIndexer;
use FluxFiles\StorageMetadataHandler;
use Illuminate\Console\Command;

/**
 * Seed FluxFiles metadata + directory index for files/folders that already
 * exist on a disk (e.g. public/uploads/user_1/... created before FluxFiles
 * was installed).
 *
 * After seeding, existing content becomes searchable through the storage-backed
 * metadata index and the folder search endpoint.
 */
class SeedMetadataCommand extends Command
{
    protected $signature = 'fluxfiles:seed
        {--disk=local : Disk name defined in config/fluxfiles.php}
        {--path= : Limit seeding to this sub-path (e.g. "user_1")}
        {--owner= : Assign indexed files to this FluxFiles user id}
        {--readonly : Mark indexed files as read-only for owner_only mode}
        {--overwrite : Overwrite existing metadata entries (default: skip)}
        {--dry-run : Report what would be done without writing}
        {--hash : Compute SHA-256 hashes for duplicate detection}
        {--variants : Generate image variants while indexing}
        {--persist-metadata : Persist metadata to file sidecars / S3 object metadata instead of index-only}';

    protected $description = 'Index existing files + folders on a disk so they appear in FluxFiles search.';

    public function handle(): int
    {
        $disk      = (string) $this->option('disk');
        $path      = trim((string) $this->option('path'), '/');
        $overwrite       = (bool) $this->option('overwrite');
        $dryRun          = (bool) $this->option('dry-run');
        $owner           = $this->option('owner') !== null ? (string) $this->option('owner') : null;
        $readonly        = (bool) $this->option('readonly');
        $hash            = (bool) $this->option('hash');
        $variants        = (bool) $this->option('variants');
        $persistMetadata = (bool) $this->option('persist-metadata');

        $diskConfigs = config('fluxfiles.disks');
        if (!isset($diskConfigs[$disk])) {
            $this->error("Disk '{$disk}' is not configured in config/fluxfiles.php.");
            return self::FAILURE;
        }

        $dm = new DiskManager($diskConfigs);
        $indexer = new ExistingFileIndexer($dm, new StorageMetadataHandler($dm));

        $this->line("Seeding disk <info>{$disk}</info>" . ($path !== '' ? " at path <info>{$path}</info>" : ''));
        if ($dryRun) {
            $this->warn('Dry-run mode — no files will be written.');
        }
        if (!$persistMetadata && $owner === null && !$readonly) {
            $this->line('Mode: index-only. Existing source files/objects will not be modified.');
        }
        if ($readonly) {
            $this->line('Mode: readonly. Indexed files will be assigned to an internal read-only owner.');
        } elseif ($owner !== null && $owner !== '') {
            $this->line("Mode: owner assignment to <info>{$owner}</info>.");
        }

        $stats = $indexer->index([
            'disk' => $disk,
            'path' => $path,
            'owner' => $owner,
            'readonly' => $readonly,
            'overwrite' => $overwrite,
            'dry_run' => $dryRun,
            'hash' => $hash,
            'variants' => $variants,
            'persist_metadata' => $persistMetadata,
            'on_item' => function (string $type, string $key, array $stats, ?\Throwable $error): void {
                if ($type === 'error') {
                    $this->error("  [error] {$key}: {$error->getMessage()}");
                    return;
                }
                if ((($stats['files_indexed'] + $stats['folders_indexed'] + $stats['skipped']) % 250) === 0) {
                    $this->output->write('.');
                }
                if ($this->option('dry-run') && in_array($type, ['file', 'dir', 'skip'], true)) {
                    $this->line("  [{$type}] {$key}");
                }
            },
        ]);

        $this->newLine();
        $this->info(
            "Done. Files indexed: {$stats['files_indexed']}, folders indexed: {$stats['folders_indexed']}, " .
            "skipped: {$stats['skipped']}, hashed: {$stats['hashed']}, variants: {$stats['variants']}, errors: {$stats['errors']}."
        );
        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

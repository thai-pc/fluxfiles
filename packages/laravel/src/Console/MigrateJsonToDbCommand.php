<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Console;

use FluxFiles\Db\JsonToDbMigrator;
use FluxFiles\DiskManager;
use FluxFiles\Laravel\LaravelDbMetadataHandler;
use FluxFiles\StorageMetadataHandler;
use Illuminate\Console\Command;

/**
 * Data migration for the json -> db storage-backend cutover (see
 * docs/DB-STORAGE-MIGRATION-DESIGN.md §9). Deliberately does not gate on
 * fluxfiles.storage_backend — this must run and be verified BEFORE the
 * config flips to 'db', so it always targets the db_connection tables
 * regardless of the currently-active backend.
 */
class MigrateJsonToDbCommand extends Command
{
    protected $signature = 'fluxfiles:migrate-json-to-db
        {--disk=local : Disk name defined in config/fluxfiles.php}
        {--prefix= : Limit file/folder/trash migration to this sub-path (ignored for the audit log — the whole disk always migrates together)}
        {--dry-run : Report counts without writing to the database}
        {--verify : Diff the database against the JSON source instead of migrating; exits 1 on drift}
        {--yes : Skip the confirmation prompt before a real (non-dry-run) run}';

    protected $description = 'Migrate _fluxfiles/*.json(l) metadata into the DB storage backend.';

    public function handle(): int
    {
        $disk = (string) $this->option('disk');
        $prefix = trim((string) $this->option('prefix'), '/');
        $dryRun = (bool) $this->option('dry-run');
        $verifyOnly = (bool) $this->option('verify');

        $diskConfigs = config('fluxfiles.disks');
        if (!isset($diskConfigs[$disk])) {
            $this->error("Disk '{$disk}' is not configured in config/fluxfiles.php.");
            return self::FAILURE;
        }

        $dm = new DiskManager($diskConfigs);
        $source = new StorageMetadataHandler($dm);
        $destination = new LaravelDbMetadataHandler($dm, config('fluxfiles.db_connection'));
        $migrator = new JsonToDbMigrator($dm, $source, $destination);

        if ($verifyOnly) {
            $result = $migrator->verify($disk, $prefix);
            foreach ($result as $section => $diff) {
                $missing = count($diff['missing_in_db'] ?? []);
                $mismatched = count($diff['mismatched'] ?? []);
                $this->line(sprintf('%-16s missing_in_db=%d mismatched=%d', $section, $missing, $mismatched));
            }
            $clean = JsonToDbMigrator::isClean($result);
            $clean ? $this->info('Clean — DB matches JSON source.') : $this->warn('Drift detected — see above.');
            return $clean ? self::SUCCESS : self::FAILURE;
        }

        if (!$dryRun && !$this->option('yes')) {
            if (!$this->confirm("About to migrate disk '{$disk}'" . ($prefix !== '' ? " (prefix: {$prefix})" : '') . ' from JSON to DB. Continue?')) {
                $this->comment('Aborted.');
                return self::FAILURE;
            }
        }

        $label = $dryRun ? 'would_' : '';
        $result = $migrator->migrate($disk, $prefix, $dryRun);
        foreach ($result as $section => $counts) {
            $parts = [];
            foreach ($counts as $bucket => $n) {
                $parts[] = "{$label}{$bucket}={$n}";
            }
            $this->line(sprintf('%-16s %s', $section, implode(' ', $parts)));
        }
        if ($prefix !== '') {
            $this->comment("(note: --prefix does not apply to the audit log — the whole disk's audit trail always migrates together)");
        }

        $dryRun
            ? $this->comment('Dry run — no changes written. Re-run with --verify after a real run to confirm.')
            : $this->info('Done. Run with --verify to confirm the DB now matches the JSON source.');

        return self::SUCCESS;
    }
}

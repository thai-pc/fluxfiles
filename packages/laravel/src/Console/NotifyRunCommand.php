<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Console;

use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\Laravel\LaravelDbMetadataHandler;
use FluxFiles\Laravel\MailerResolver;
use FluxFiles\StorageMetadataHandler;
use Illuminate\Console\Command;

/**
 * The e-mail notice sweep, run by the scheduler instead of a request:
 *
 *     Schedule::command('fluxfiles:notify-run --discover')->hourly();
 *
 * Same code as `POST {route_prefix}/notify/run` (core's NotifyEndpoint::run) with the
 * same mailer resolution as the proxy controller, over every store it is pointed at
 * (core's NotifyScheduler::sweep). The sweep reads one tenant store per prefix and mails
 * only the recipients sealed in each record, so there are no notify_* settings here: a run
 * covers the `--prefix` values (repeatable), the `--prefixes-file` lines and, with
 * `--discover`, every store found under a LOCAL disk's root. With none of them it sweeps
 * the empty prefix only.
 */
class NotifyRunCommand extends Command
{
    protected $signature = 'fluxfiles:notify-run
        {--disk=local : Disk to sweep}
        {--prefix=* : Tenant folder to sweep (repeatable)}
        {--prefixes-file= : File with one tenant folder per line}
        {--discover : Sweep every store found under the disk root (local disks only)}
        {--dry-run : Report what would be sent without sending}
        {--max=50 : Most e-mails per run (1-200)}';

    protected $description = 'Send the scheduled FluxFiles e-mail notices (expiry warnings, digests, reminders).';

    public function handle(): int
    {
        try {
            return $this->sweep();
        } catch (\Throwable $e) {
            // No stack dump into the scheduler's log (it may carry paths); the class is enough.
            $this->error('Notify sweep failed (' . get_class($e) . ')');
            return 2;
        }
    }

    private function sweep(): int
    {
        if (!class_exists(\FluxFiles\NotifyScheduler::class)) {
            $this->error('E-mail notices need a newer FluxFiles core (composer update fluxfiles/fluxfiles).');
            return self::FAILURE;
        }
        $disk = trim((string) $this->option('disk')) ?: 'local';
        $max = (string) $this->option('max');
        if (!ctype_digit($max) || (int) $max < 1) {
            $this->error('--max needs a positive number.');
            return self::FAILURE;
        }
        $secret = (string) config('fluxfiles.secret');
        if (strlen($secret) < 32) {
            $this->error('FLUXFILES_SECRET is not configured (32+ characters); the sweep cannot open the sealed recipients.');
            return self::FAILURE;
        }
        $disks = (array) config('fluxfiles.disks', []);
        if (!isset($disks[$disk])) {
            $this->error("Disk '{$disk}' is not configured.");
            return self::FAILURE;
        }

        $prefixes = array_values(array_filter((array) $this->option('prefix'), 'is_string'));
        $file = trim((string) $this->option('prefixes-file'));
        if ($file !== '') {
            $lines = is_file($file) && is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
            if ($lines === false) {
                $this->error('--prefixes-file: cannot read that file.');
                return self::FAILURE;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $prefixes[] = $line;
                }
            }
        }

        $why = null;
        try {
            $dm = new DiskManager($disks);
            \FluxFiles\AuditChain::configure($secret);
            $meta = config('fluxfiles.storage_backend') === 'db'
                ? new LaravelDbMetadataHandler($dm, config('fluxfiles.db_connection'))
                : new StorageMetadataHandler($dm);
            $report = \FluxFiles\NotifyScheduler::sweep(
                $dm,
                $secret,
                [
                    'disk'     => $disk,
                    'prefixes' => $prefixes,
                    'discover' => (bool) $this->option('discover'),
                    'dry_run'  => (bool) $this->option('dry-run'),
                    'max'      => (int) $max,
                ],
                MailerResolver::resolve($why),
                $meta
            );
        } catch (ApiException $e) {
            $this->error($e->getMessage() . ($why !== null ? " ({$why})" : ''));
            // 500/502/503/504 = storage trouble (the sweep failed); the rest is usage/config/gate.
            return in_array($e->getHttpCode(), \FluxFiles\NotifyScheduler::STORAGE_FAILURES, true) ? 2 : self::FAILURE;
        }

        $this->info(sprintf(
            '%s: swept %d store%s, sent %d, coalesced %d, rate-limited %d%s.',
            $report['dry_run'] ? 'Dry run' : 'Done',
            $report['stores'],
            $report['stores'] === 1 ? '' : 's',
            $report['sent'],
            $report['coalesced'],
            $report['rate_limited'],
            !empty($report['truncated']) ? ', truncated (raise --max or run again)' : ''
        ));
        if (!empty($report['discovery_truncated'])) {
            $this->warn('Discovery stopped at ' . \FluxFiles\NotifyScheduler::DISCOVER_MAX_STORES . ' stores; list the rest with --prefixes-file.');
        }
        foreach ($report['skipped'] as $sk) {
            $this->warn("Skipped {$sk['module']}: {$sk['reason']} (update the module).");
        }
        foreach ($report['failed'] as $f) {
            $this->warn("Store '{$f['prefix']}' failed ({$f['error']}).");
        }
        return $report['failed'] === [] ? self::SUCCESS : 2;
    }
}

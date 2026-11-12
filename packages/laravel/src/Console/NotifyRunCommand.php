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
 *     Schedule::command('fluxfiles:notify-run')->hourly();
 *
 * Same code as `POST {route_prefix}/notify/run` (core's NotifyEndpoint::run) with the
 * same mailer resolution as the proxy controller. There is no JWT, so the claims are
 * built server-side from `config('fluxfiles.notify')` (env FLUXFILES_NOTIFY_*).
 */
class NotifyRunCommand extends Command
{
    protected $signature = 'fluxfiles:notify-run
        {--disk=local : Disk to sweep}
        {--prefix= : Limit the sweep to one tenant folder}
        {--dry-run : Report what would be sent without sending}
        {--max=50 : Most e-mails per run (1-200)}';

    protected $description = 'Send the scheduled FluxFiles e-mail notices (expiry warnings, digests, reminders).';

    public function handle(): int
    {
        if (!class_exists(\FluxFiles\NotifyEndpoint::class)) {
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
        $disks = (array) config('fluxfiles.disks', []);
        if (!isset($disks[$disk])) {
            $this->error("Disk '{$disk}' is not configured.");
            return self::FAILURE;
        }

        $n = (array) config('fluxfiles.notify', []);
        $payload = [
            'sub'          => 'notify-scheduler',
            'perms'        => ['read', 'write'],
            'disks'        => [$disk],
            'prefix'       => trim((string) $this->option('prefix'), '/'),
            'allow_intake' => true,
            'allow_share'  => true,
            'notify_to'    => trim((string) ($n['to'] ?? '')),
        ];
        foreach (['locale', 'from_name', 'reply_to', 'app_url'] as $k) {
            $v = trim((string) ($n[$k] ?? ''));
            if ($v !== '') {
                $payload['notify_' . $k] = $v;
            }
        }

        try {
            $claims = \FluxFiles\Claims::fromJwtPayload((object) $payload, $secret);
            $dm = new DiskManager($disks);
            \FluxFiles\AuditChain::configure($secret);
            $meta = config('fluxfiles.storage_backend') === 'db'
                ? new LaravelDbMetadataHandler($dm, config('fluxfiles.db_connection'))
                : new StorageMetadataHandler($dm);
            $auditLog = new \FluxFiles\AuditLogStorage($meta, $claims->allowedDisks);
            $audit = static function (string $d, string $key, string $detail) use ($auditLog, $claims): void {
                try {
                    $auditLog->log($claims->userId, 'notify_sent', $d, $key, null, null, $detail);
                } catch (\Throwable $ignored) {
                }
            };

            $why = null;
            $dry = (bool) $this->option('dry-run');
            $report = \FluxFiles\NotifyEndpoint::run(
                $dm,
                $claims,
                MailerResolver::resolve($why),
                ['disk' => $disk, 'dry_run' => $dry, 'max_emails' => (int) $max],
                null,
                $secret,
                $audit
            );
            try {
                $auditLog->log($claims->userId, 'notify_run', $disk, '', null, null, sprintf(
                    '%s sent=%d coalesced=%d',
                    $dry ? 'dry-run' : 'applied',
                    (int) $report['sent'],
                    (int) $report['coalesced']
                ));
            } catch (\Throwable $ignored) {
            }
        } catch (ApiException $e) {
            $this->error($e->getMessage() . (isset($why) && $why !== null ? " ({$why})" : ''));
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: sent %d, coalesced %d, rate-limited %d%s.',
            $report['dry_run'] ? 'Dry run' : 'Done',
            $report['sent'],
            $report['coalesced'],
            $report['rate_limited'],
            !empty($report['truncated']) ? ', truncated (raise --max or run again)' : ''
        ));
        foreach ($report['skipped'] as $sk) {
            $this->warn("Skipped {$sk['module']}: {$sk['reason']} (update the module).");
        }
        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace FluxFiles;

use FluxFiles\Mail\MailTransportInterface;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment (not user input); core is a WordPress-independent library

/**
 * The standalone scheduler for e-mail notices (`bin/fluxfiles notify:run`): the same
 * sweep as `POST /api/fm/notify/run`, started by the operator's cron instead of a
 * request, so there is no JWT. The claims are built server-side from the
 * `FLUXFILES_NOTIFY_*` env vars, which are read ONLY here and never as request claims.
 */
final class NotifyScheduler
{
    /**
     * Operator claims for the sweep: read+write on one disk, the module gates opened
     * (the operator running the cron is the trust anchor), the notify_* claims from env.
     *
     * @param array<string,mixed> $env
     */
    public static function claims(array $env, string $disk, string $prefix, string $secret): Claims
    {
        $get = static fn (string $k): string => trim((string) ($env[$k] ?? ''));
        $payload = [
            'sub'          => 'notify-scheduler',
            'perms'        => ['read', 'write'],
            'disks'        => [$disk],
            'prefix'       => trim($prefix, '/'),
            'allow_intake' => true,
            'allow_share'  => true,
            'notify_to'    => $get('FLUXFILES_NOTIFY_TO'),
        ];
        foreach (['locale' => 'LOCALE', 'from_name' => 'FROM_NAME', 'reply_to' => 'REPLY_TO', 'app_url' => 'APP_URL'] as $claim => $suffix) {
            $v = $get('FLUXFILES_NOTIFY_' . $suffix);
            if ($v !== '') {
                $payload['notify_' . $claim] = $v;
            }
        }
        return Claims::fromJwtPayload((object) $payload, $secret);
    }

    /**
     * Run one sweep. `$opts`: disk (default local), prefix, dry_run, max (1..200, default 50).
     * Throws ApiException for gate / config problems (no module, no mailer on a live run, ...).
     *
     * @param array<string,mixed> $env
     * @param array<string,mixed> $opts
     * @return array<string,mixed> the NotifyEndpoint::run() report
     */
    public static function run(array $env, array $opts, DiskManager $dm, ?MailTransportInterface $mailer, ?MetadataRepositoryInterface $meta = null, ?LicenseCheckInterface $license = null): array
    {
        $secret = (string) ($env['FLUXFILES_SECRET'] ?? '');
        if (strlen($secret) < 32) {
            throw new ApiException('FLUXFILES_SECRET is not configured (32+ characters)', 500);
        }
        $disk = is_string($opts['disk'] ?? null) && $opts['disk'] !== '' ? $opts['disk'] : 'local';
        $claims = self::claims($env, $disk, (string) ($opts['prefix'] ?? ''), $secret);
        $meta ??= new StorageMetadataHandler($dm);
        $auditLog = new AuditLogStorage($meta, $claims->allowedDisks);
        $audit = static function (string $d, string $key, string $detail) use ($auditLog, $claims): void {
            try {
                $auditLog->log($claims->userId, 'notify_sent', $d, $key, null, null, $detail);
            } catch (\Throwable $ignored) {
            }
        };
        $body = ['disk' => $disk, 'dry_run' => !empty($opts['dry_run']), 'max_emails' => (int) ($opts['max'] ?? 50)];
        $report = NotifyEndpoint::run($dm, $claims, $mailer, $body, $license, $secret, $audit);
        try {
            $auditLog->log($claims->userId, 'notify_run', $disk, '', null, null, sprintf('%s sent=%d coalesced=%d', $body['dry_run'] ? 'dry-run' : 'applied', (int) $report['sent'], (int) $report['coalesced']));
        } catch (\Throwable $ignored) {
        }
        return $report;
    }
}

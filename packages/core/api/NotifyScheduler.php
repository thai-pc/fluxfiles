<?php

declare(strict_types=1);

namespace FluxFiles;

use FluxFiles\Mail\MailTransportInterface;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment (not user input); core is a WordPress-independent library

/**
 * The standalone scheduler for e-mail notices (`bin/fluxfiles notify:run`): the same
 * sweep as `POST /api/fm/notify/run`, started by the operator's cron instead of a
 * request, so there is no JWT. The claims are built server-side.
 *
 * What one run covers: the sweep reads ONE tenant store per prefix
 * (`<prefix>/_fluxfiles/{intakes,shares}.json`) and mails only the recipients sealed in
 * each record, so the claims carry just the gates, perms, disk and prefix, and no
 * `notify_*` setting can change who is mailed. A run therefore covers exactly the prefixes
 * it is given: `prefixes` (repeatable `--prefix`, `--prefixes-file`), and/or every store
 * found by `discover` on a LOCAL disk. With neither, it sweeps the empty prefix only.
 */
final class NotifyScheduler
{
    public const DISCOVER_MAX_DEPTH = 6;
    public const DISCOVER_MAX_STORES = 10000;
    /** HTTP codes meaning "storage / the server failed" (the CLI's exit 2), not a gate or config problem (501 is a gate). */
    public const STORAGE_FAILURES = [500, 502, 503, 504];

    /**
     * Operator claims for the sweep of one store: read+write on one disk, the module gates
     * opened (the operator running the cron is the trust anchor).
     */
    public static function claims(string $disk, string $prefix, #[\SensitiveParameter] string $secret): Claims
    {
        return Claims::fromJwtPayload((object) [
            'sub'          => 'notify-scheduler',
            'perms'        => ['read', 'write'],
            'disks'        => [$disk],
            'prefix'       => trim($prefix, '/'),
            'allow_intake' => true,
            'allow_share'  => true,
        ], $secret);
    }

    /**
     * Normalise operator-supplied prefixes: trimmed, de-duplicated, `..` and NUL rejected.
     *
     * @param list<string> $prefixes
     * @return list<string>
     */
    public static function cleanPrefixes(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $p) {
            $p = trim(str_replace('\\', '/', (string) $p), "/ \t\r\n");
            if (str_contains($p, "\0") || in_array('..', explode('/', $p), true)) {
                throw new ApiException('Invalid prefix: ' . substr(preg_replace('/[^\x20-\x7e]/', '?', $p) ?? '', 0, 80), 400);
            }
            $out[$p] = true;
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Find the tenant stores under a LOCAL disk root: every directory (depth 0 = the root,
     * at most {@see DISCOVER_MAX_DEPTH}) holding `_fluxfiles/intakes.json` or `shares.json`.
     * `_fluxfiles` itself is never descended into (so trash is skipped) and symlinks are
     * not followed. Stops at {@see DISCOVER_MAX_STORES} stores.
     *
     * @return array{prefixes:list<string>, truncated:bool}
     */
    public static function discover(string $root, int $maxStores = self::DISCOVER_MAX_STORES, int $maxDepth = self::DISCOVER_MAX_DEPTH): array
    {
        $root = rtrim($root, '/\\');
        $found = [];
        $stack = [['', 0]];
        while ($stack !== []) {
            [$rel, $depth] = array_pop($stack);
            $dir = $rel === '' ? $root : $root . '/' . $rel;
            if (is_file($dir . '/_fluxfiles/intakes.json') || is_file($dir . '/_fluxfiles/shares.json')) {
                if (count($found) >= $maxStores) {
                    sort($found);
                    return ['prefixes' => $found, 'truncated' => true];
                }
                $found[] = $rel;
            }
            if ($depth >= $maxDepth) {
                continue;
            }
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..' || $e === '_fluxfiles') {
                    continue;
                }
                $path = $dir . '/' . $e;
                if (is_dir($path) && !is_link($path)) {
                    $stack[] = [$rel === '' ? $e : $rel . '/' . $e, $depth + 1];
                }
            }
        }
        sort($found);
        return ['prefixes' => $found, 'truncated' => false];
    }

    /**
     * Run one sweep over many stores. `$opts`: disk (default local), prefix (string) and/or
     * prefixes (list), discover (bool, local disks only), dry_run, max (1..200, default 50;
     * shared by the whole run). Throws ApiException for gate / config problems (no module,
     * no mailer on a live run, a bad prefix, `discover` on a non-local disk). A store whose
     * sweep fails (storage trouble) is listed in `failed` and the others still run.
     *
     * The report is the NotifyEndpoint::run() one plus `stores` (swept), `stores_total`,
     * `failed` (list of {prefix, error}) and, with `discover`, `discovery_truncated`.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function sweep(DiskManager $dm, #[\SensitiveParameter] string $secret, array $opts, ?MailTransportInterface $mailer, MetadataRepositoryInterface $meta, ?LicenseCheckInterface $license = null): array
    {
        $disk = is_string($opts['disk'] ?? null) && $opts['disk'] !== '' ? $opts['disk'] : 'local';
        $dry = !empty($opts['dry_run']);
        $max = max(1, min(200, (int) ($opts['max'] ?? 50)));

        $list = [];
        if (isset($opts['prefix']) && is_string($opts['prefix'])) {
            $list[] = $opts['prefix'];
        }
        foreach ((array) ($opts['prefixes'] ?? []) as $p) {
            $list[] = (string) $p;
        }
        $report = ['dry_run' => $dry, 'sent' => 0, 'coalesced' => 0, 'rate_limited' => 0, 'truncated' => false, 'items' => [], 'skipped' => [], 'stores' => 0, 'stores_total' => 0, 'failed' => []];
        if (!empty($opts['discover'])) {
            $cfg = $dm->config($disk);
            if (($cfg['driver'] ?? '') !== 'local') {
                throw new ApiException("--discover needs a local disk; list the prefixes of '{$disk}' with --prefix or --prefixes-file", 400);
            }
            $found = self::discover((string) ($cfg['root'] ?? ''));
            $list = array_merge($list, $found['prefixes']);
            $report['discovery_truncated'] = $found['truncated'];
        } elseif ($list === []) {
            $list = [''];
        }
        $prefixes = self::cleanPrefixes($list);
        $report['stores_total'] = count($prefixes);

        foreach ($prefixes as $prefix) {
            $budget = $max - $report['sent'];
            if ($budget <= 0) {
                $report['truncated'] = true;
                break;
            }
            $claims = self::claims($disk, $prefix, $secret);
            $auditLog = new AuditLogStorage($meta, $claims->allowedDisks);
            $audit = static function (string $d, string $key, string $detail) use ($auditLog, $claims): void {
                try {
                    $auditLog->log($claims->userId, 'notify_sent', $d, $key, null, null, $detail);
                } catch (\Throwable $ignored) {
                }
            };
            try {
                $r = NotifyEndpoint::run($dm, $claims, $mailer, ['disk' => $disk, 'dry_run' => $dry, 'max_emails' => $budget], $license, $secret, $audit);
            } catch (ApiException $e) {
                if (!in_array($e->getHttpCode(), self::STORAGE_FAILURES, true)) {
                    throw $e;   // gate (501/402/403) / config: the same for every store
                }
                $report['failed'][] = ['prefix' => $prefix, 'error' => $e->getHttpCode() . ' ' . $e->getErrorCode()];
                continue;
            } catch (\Throwable $e) {
                $report['failed'][] = ['prefix' => $prefix, 'error' => get_class($e)];
                continue;
            }
            $report['stores']++;
            foreach (['sent', 'coalesced', 'rate_limited'] as $k) {
                $report[$k] += (int) $r[$k];
            }
            $report['truncated'] = $report['truncated'] || !empty($r['truncated']);
            array_push($report['items'], ...$r['items']);
            foreach ($r['skipped'] as $sk) {
                if (!in_array($sk, $report['skipped'], true)) {
                    $report['skipped'][] = $sk;
                }
            }
            try {
                $auditLog->log($claims->userId, 'notify_run', $disk, '', null, null, sprintf('%s sent=%d coalesced=%d', $dry ? 'dry-run' : 'applied', (int) $r['sent'], (int) $r['coalesced']));
            } catch (\Throwable $ignored) {
            }
        }
        return $report;
    }

    /**
     * {@see sweep()} for the standalone CLI: the secret comes from `$env`, the metadata
     * handler defaults to the storage-resident one.
     *
     * @param array<string,mixed> $env
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function run(array $env, array $opts, DiskManager $dm, ?MailTransportInterface $mailer, ?MetadataRepositoryInterface $meta = null, ?LicenseCheckInterface $license = null): array
    {
        $secret = (string) ($env['FLUXFILES_SECRET'] ?? '');
        if (strlen($secret) < 32) {
            throw new ApiException('FLUXFILES_SECRET is not configured (32+ characters)', 500);
        }
        return self::sweep($dm, $secret, $opts, $mailer, $meta ?? new StorageMetadataHandler($dm), $license);
    }
}

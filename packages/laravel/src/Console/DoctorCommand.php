<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Console;

use FluxFiles\Laravel\Doctor;
use Illuminate\Console\Command;

/**
 * Host-app health check: is the bookkeeping dir (`_fluxfiles/`) web-served, and
 * has config/fluxfiles.php drifted from the package's. Same checks as
 * `GET {route_prefix}/disk/doctor`, plus an optional loopback probe (safe here:
 * the CLI is not the single-threaded server being probed).
 */
class DoctorCommand extends Command
{
    protected $signature = 'fluxfiles:doctor
        {--no-probe : Skip the loopback HTTP probe of /_fluxfiles/index.json}';

    protected $description = 'Check that _fluxfiles/ is not web-served and config/fluxfiles.php is up to date.';

    public function handle(): int
    {
        $disks  = (array) config('fluxfiles.disks', []);
        $public = function_exists('public_path') ? public_path() : null;

        $checks = Doctor::exposureChecks($disks, $public, null, true);
        $checks = array_merge($checks, Doctor::driftChecks(
            (array) config('fluxfiles'),
            (array) require __DIR__ . '/../../config/fluxfiles.php'
        ));

        $problem = false;
        foreach ($checks as $c) {
            $problem = $problem || in_array($c['status'], ['warn', 'fail'], true);
            $line = strtoupper($c['status']) . '  ' . $c['id'] . ' - ' . $c['message'];
            $c['status'] === 'ok' || $c['status'] === 'info' ? $this->line($line) : $this->warn($line);
            if (!empty($c['fix'])) {
                $this->line('      fix: ' . $c['fix']);
            }
        }

        if (!$this->option('no-probe')) {
            $base = (string) config('app.url', '');
            foreach (Doctor::exposedDisks($disks, $public) as $e) {
                if ($base === '') {
                    $this->line("probe  {$e['disk']}: skipped (app.url is empty)");
                    continue;
                }
                $r = Doctor::probe($base, $e['url_path']);
                if ($r === 'served') {
                    $problem = true;
                    $this->error("probe  {$e['disk']}: SERVED (fix it) - {$base}{$e['url_path']}/_fluxfiles/index.json answered 200");
                } elseif ($r === 'blocked') {
                    $this->info("probe  {$e['disk']}: blocked");
                } else {
                    $this->line("probe  {$e['disk']}: inconclusive ({$r})");
                }
            }
        }

        $problem ? $this->error('Problems found.') : $this->info('All good.');
        return $problem ? self::FAILURE : self::SUCCESS;
    }
}

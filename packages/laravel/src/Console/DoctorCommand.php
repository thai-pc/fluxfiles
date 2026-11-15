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
        {--no-probe : Skip the loopback HTTP probe of /_fluxfiles/index.json}
        {--ai : Also run a live AI connection test (one tiny vision call)}
        {--cheap : With --ai, only look the model up at the provider (free) instead of a vision call}';

    protected $description = 'Check that _fluxfiles/ is not web-served and config/fluxfiles.php is up to date (--ai: also test the AI provider).';

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

        if ($this->option('ai') && !$this->aiCheck((bool) $this->option('cheap'))) {
            $problem = true;
        }

        $problem ? $this->error('Problems found.') : $this->info('All good.');
        return $problem ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run the AI connection test with the same tagger `/ai-tag` uses (built from
     * `config/fluxfiles.php`). Never prints the API key; the base URL, its host and
     * host:port are replaced in any provider text (a gateway's error body may echo them).
     */
    private function aiCheck(bool $cheap): bool
    {
        $scrubs = [];
        $base = \FluxFiles\Laravel\AiTaggerFactory::baseUrl();
        if ($base !== '') {
            $scrubs[rtrim($base, '/')] = '<base-url>';
            $parts = parse_url($base);
            if (!empty($parts['host'])) {
                if (!empty($parts['port'])) {
                    $scrubs[$parts['host'] . ':' . $parts['port']] = '<host>';
                }
                $scrubs[$parts['host']] = '<host>';
            }
            uksort($scrubs, static fn($a, $b) => strlen($b) <=> strlen($a));
        }
        $scrub = static fn(string $t): string => $scrubs === [] ? $t : strtr($t, $scrubs);
        // Core exposes no getter for the current sink, so it is reset to the default afterwards
        // (this adapter installs none).
        \FluxFiles\Log::setSink(static function (string $m) use ($scrub): void {
            fwrite(STDERR, $scrub($m) . "\n");
        });

        try {
            $tagger = \FluxFiles\Laravel\AiTaggerFactory::make();
            if ($tagger === null) {
                $code = \FluxFiles\AiError::NOT_CONFIGURED;
                $this->warn("FAIL  ai - {$code}: AI tagging is not configured");
                $this->line('      fix: ' . \FluxFiles\AiError::hint($code));
                return false;
            }
            $res = $cheap ? $tagger->checkModel() : $tagger->testConnection();
        } finally {
            \FluxFiles\Log::setSink(null);
        }

        if ($res['ok']) {
            $line = "OK    ai - {$res['provider']}/{$res['model']} ({$res['latency_ms']} ms)";
            if (!$res['verified']) {
                $line .= ' - not verified: this endpoint has no model lookup, run without --cheap';
            }
            $this->line($line);
            if (!empty($res['warning'])) {
                $this->warn($scrub("WARN  ai - {$res['warning']}") . (!empty($res['retires_at']) ? " (retires {$res['retires_at']})" : ''));
            }
            return true;
        }

        $this->warn($scrub("FAIL  ai - {$res['code']} ({$res['provider']}/{$res['model']}): {$res['message']}"));
        $this->line('      fix: ' . $scrub((string) $res['hint']));
        if (!empty($res['detail'])) {
            $this->line('      detail: ' . $scrub((string) $res['detail']));
        }
        return false;
    }
}

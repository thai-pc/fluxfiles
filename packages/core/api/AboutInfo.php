<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * The operator's "About" report: what version is running, which modules are
 * installed/licensed, which external tools exist. One provider shared by
 * `fluxfiles about`, the WordPress Site Health panel and `php artisan about`.
 *
 * Operator-side only: it is never routed over HTTP, and it is deliberately
 * boring about secrets. It reports booleans, versions, counts and disk DRIVERS;
 * never a licence key, a disk credential/endpoint/bucket/root path, a token, or an
 * environment value (SSRF allow-hosts are reported as a count). Everything
 * external is probed cheaply (a binary lookup + `--version`, 3 s cap) and through
 * {@see ProcessEnv::minimal()}, so a probed tool never sees FLUXFILES_SECRET.
 */
final class AboutInfo
{
    private const PROBE_TIMEOUT = 3;

    /**
     * @param array{
     *   env?: array<string,mixed>,
     *   disks?: array<string,array<string,mixed>>|null,
     *   license?: LicenseCheckInterface|null,
     *   probe_tools?: bool
     * } $opts  env: defaults to $_ENV · disks: the configured disks (null = DiskConfig::load()) ·
     *          license: defaults to ModuleRegistry::license() · probe_tools: false skips all subprocesses
     * @return array<string,mixed>
     */
    public static function collect(array $opts = []): array
    {
        $env = $opts['env'] ?? $_ENV;
        $license = $opts['license'] ?? ModuleRegistry::license();
        $probe = $opts['probe_tools'] ?? true;

        [$coreVersion, $coreSource] = self::coreVersion($env);

        $disks = $opts['disks'] ?? null;
        if ($disks === null) {
            try {
                $disks = DiskConfig::load();
            } catch (\Throwable $e) {
                $disks = [];
            }
        }
        $diskList = [];
        foreach ($disks as $name => $cfg) {
            $diskList[] = ['name' => (string) $name, 'driver' => is_array($cfg) ? (string) ($cfg['driver'] ?? 'unknown') : 'unknown'];
        }

        $backend = strtolower(trim((string) ($env['FLUXFILES_STORAGE_BACKEND'] ?? 'json')));
        $backend = $backend === 'db' ? 'db' : 'json';

        $modules = [];
        foreach (ModuleRegistry::ids() as $id) {
            $modules[$id] = self::module($id, $license, $coreVersion, $env);
        }

        $allow = array_filter(array_map('trim', explode(',', (string) ($env['FLUXFILES_SSRF_ALLOW_HOSTS'] ?? ''))), static fn ($h) => $h !== '');
        $info = $license->info();

        return [
            'core' => ['version' => $coreVersion, 'source' => $coreSource],
            'php' => ['version' => PHP_VERSION],
            'storage_backend' => $backend,
            'disks' => $diskList,
            'license' => ['edition' => (string) ($info['edition'] ?? 'free'), 'status' => $license->status()],
            'modules' => $modules,
            'scanning' => [
                'virus' => (bool) ($modules['virus']['enabled'] ?? false),
                'dlp' => (bool) ($modules['dlp']['enabled'] ?? false),
            ],
            'tools' => self::tools($probe, (bool) ($modules['virus']['installed'] ?? false)),
            'ssrf_allow_hosts' => count($allow),
        ];
    }

    /**
     * Flatten for table-style consumers (CLI, WordPress Site Health, `artisan about`).
     * `private` marks rows an operator may not want pasted into a public support ticket.
     *
     * @param array<string,mixed> $info the result of {@see collect()}
     * @return list<array{label:string,value:string,private:bool}>
     */
    public static function rows(array $info): array
    {
        $yn = static fn ($b): string => $b ? 'yes' : 'no';
        $rows = [];
        $add = static function (string $label, string $value, bool $private = false) use (&$rows): void {
            $rows[] = ['label' => $label, 'value' => $value, 'private' => $private];
        };

        $core = $info['core'];
        $add('Core version', $core['version'] === null ? 'unknown (set FLUXFILES_CORE_VERSION)' : $core['version'] . ' (' . $core['source'] . ')');
        $add('PHP', (string) $info['php']['version']);
        $add('Storage backend', (string) $info['storage_backend']);
        $disks = array_map(static fn ($d) => $d['name'] . ' (' . $d['driver'] . ')', $info['disks']);
        $add('Disks', $disks === [] ? 'none' : implode(', ', $disks), true);
        $add('Licence', $info['license']['edition'] . ' / ' . $info['license']['status'], true);
        foreach ($info['modules'] as $id => $m) {
            if (!$m['installed']) {
                $add("Module {$id}", 'not installed');
                continue;
            }
            $bits = [$m['version'] ?? 'version unknown', 'licensed: ' . $yn($m['licensed']), 'enabled: ' . $yn($m['enabled'])];
            if ($m['requires_core'] !== null) {
                $bits[] = 'needs core ' . $m['requires_core'] . ($m['core_compatible'] === false ? ' (NOT satisfied)' : '');
            }
            $add("Module {$id}", implode(', ', $bits));
        }
        $add('Virus scanning', $yn($info['scanning']['virus']));
        $add('DLP scanning', $yn($info['scanning']['dlp']));
        $t = $info['tools'];
        $add('Image library', implode(' + ', array_keys(array_filter(['GD' => $t['gd'], 'Imagick' => $t['imagick']]))) ?: 'none');
        foreach (['ffmpeg' => 'ffmpeg', 'ghostscript' => 'Ghostscript', 'tesseract' => 'Tesseract'] as $k => $label) {
            $tool = $t[$k];
            $v = $tool['available'] ? ($tool['version'] ?? 'installed') : 'not found';
            if ($k === 'ghostscript' && $tool['available']) {
                $v .= $tool['meets_minimum'] === true ? ' (ok, >= ' . PdfOptimizer::MIN_GS_VERSION . ')'
                    : ($tool['meets_minimum'] === false ? ' (TOO OLD, PDF features off; need >= ' . PdfOptimizer::MIN_GS_VERSION . ')' : '');
            }
            $add($label, $v);
        }
        $c = $t['clamd'];
        $add('ClamAV', !$c['available'] ? 'not found' : ($c['reachable'] === null ? 'installed' : ($c['reachable'] ? 'daemon reachable' : 'installed, daemon NOT reachable')));
        $add('SSRF allow-hosts', (string) $info['ssrf_allow_hosts'] . ' configured', true);
        return $rows;
    }

    /** Plain-text table for the terminal. @param array<string,mixed> $info */
    public static function renderText(array $info): string
    {
        $rows = self::rows($info);
        $w = 0;
        foreach ($rows as $r) {
            $w = max($w, strlen($r['label']));
        }
        $out = "FluxFiles\n";
        foreach ($rows as $r) {
            $out .= '  ' . str_pad($r['label'], $w) . '  ' . $r['value'] . "\n";
        }
        return $out;
    }

    /** @param array<string,mixed> $env @return array{0:?string,1:string} */
    private static function coreVersion(array $env): array
    {
        $raw = $env['FLUXFILES_CORE_VERSION'] ?? getenv('FLUXFILES_CORE_VERSION');
        if (is_string($raw) && $raw !== '' && CoreVersion::normalize($raw) !== null) {
            return [CoreVersion::normalize($raw), 'env'];
        }
        $v = CoreVersion::current();
        return $v === null ? [null, 'unknown'] : [$v, 'composer'];
    }

    /**
     * @param array<string,mixed> $env
     * @return array{installed:bool,version:?string,licensed:bool,enabled:bool,requires_core:?string,core_compatible:?bool}
     */
    private static function module(string $id, LicenseCheckInterface $license, ?string $coreVersion, array $env): array
    {
        $class = ModuleRegistry::classFor($id);
        $out = ['installed' => $class !== null, 'version' => null, 'licensed' => false, 'enabled' => false, 'requires_core' => null, 'core_compatible' => null];
        if ($class === null) {
            return $out;
        }
        $out['licensed'] = $license->licensed($id);
        // "Enabled" server-side = code present AND entitled; the per-token claim is layer 3.
        // SSO additionally needs its explicit server flag (it runs before any token exists).
        $out['enabled'] = $out['licensed'] && ($id !== 'sso' || in_array(strtolower((string) ($env['FLUXFILES_SSO_ENABLED'] ?? '')), ['1', 'true', 'yes', 'on'], true));

        $root = null;
        try {
            $file = (new \ReflectionClass($class))->getFileName();
            $root = is_string($file) ? dirname($file, 2) : null;
        } catch (\Throwable $e) {
        }
        $pkg = null;
        if ($root !== null && is_file($root . '/composer.json')) {
            $decoded = json_decode((string) @file_get_contents($root . '/composer.json'), true);
            $pkg = is_array($decoded) ? $decoded : null;
        }
        $version = null;
        if ($root !== null && is_file($root . '/.fluxfiles-version')) {
            $version = trim((string) @file_get_contents($root . '/.fluxfiles-version'));
        }
        if (($version === null || $version === '') && is_array($pkg) && is_string($pkg['version'] ?? null)) {
            $version = $pkg['version'];
        }
        if (($version === null || $version === '') && class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('fluxfiles/' . $id)) {
            $version = (string) \Composer\InstalledVersions::getPrettyVersion('fluxfiles/' . $id);
        }
        $out['version'] = $version !== null ? self::clean($version) : null;

        $need = is_array($pkg) ? ($pkg['require']['fluxfiles/fluxfiles'] ?? null) : null;
        if (is_string($need) && trim($need) !== '') {
            $out['requires_core'] = trim($need);
            $out['core_compatible'] = $coreVersion === null ? null : CoreVersion::satisfies($coreVersion, $out['requires_core']);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function tools(bool $probe, bool $virusInstalled): array
    {
        $tools = [
            'gd' => extension_loaded('gd'),
            'imagick' => extension_loaded('imagick'),
            'ffmpeg' => ['available' => false, 'version' => null],
            'ghostscript' => ['available' => false, 'version' => null, 'meets_minimum' => null],
            'tesseract' => ['available' => false, 'version' => null],
            'clamd' => ['available' => false, 'reachable' => null],
        ];
        if (!$probe) {
            return $tools;
        }
        if (($bin = self::locate('ffmpeg')) !== null) {
            $out = self::run([$bin, '-version']);
            $tools['ffmpeg'] = ['available' => true, 'version' => preg_match('/version\s+(\S+)/', (string) $out, $m) ? self::clean($m[1]) : null];
        }
        if (($bin = self::locate('gs')) !== null) {
            $out = trim((string) self::run([$bin, '--version']));
            $v = preg_match('/^\d+\.\d+(\.\d+)?$/', $out) ? $out : null;
            $tools['ghostscript'] = ['available' => true, 'version' => $v, 'meets_minimum' => $v === null ? null : version_compare($v, PdfOptimizer::MIN_GS_VERSION, '>=')];
        }
        if (($bin = self::locate('tesseract')) !== null) {
            $out = self::run([$bin, '--version']);
            $tools['tesseract'] = ['available' => true, 'version' => preg_match('/tesseract\s+(\S+)/i', (string) $out, $m) ? self::clean($m[1]) : null];
        }
        if (($bin = self::locate('clamdscan')) !== null) {
            $tools['clamd']['available'] = true;
            // `clamdscan --version` asks the daemon itself, so exit 0 means it answered.
            // Only worth the subprocess when the virus module could use it.
            if ($virusInstalled) {
                $tools['clamd']['reachable'] = self::run([$bin, '--version']) !== null;
            }
        }
        return $tools;
    }

    /** Version strings end up in a UI/clipboard: allow only a conservative charset. */
    private static function clean(string $v): ?string
    {
        return preg_match('/^[0-9A-Za-z.+_-]{1,40}$/', $v) === 1 ? $v : null;
    }

    private static function locate(string $name): ?string
    {
        foreach (["/usr/bin/{$name}", "/usr/local/bin/{$name}", "/opt/homebrew/bin/{$name}", "/bin/{$name}"] as $cand) {
            if (@is_executable($cand)) {
                return $cand;
            }
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && @is_executable($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }
        return null;
    }

    /**
     * Stdout (+stderr) of a command, or null on non-zero exit / timeout.
     *
     * @param list<string> $cmd
     */
    private static function run(array $cmd): ?string
    {
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, ProcessEnv::minimal());
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + self::PROBE_TIMEOUT;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            }
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                proc_close($proc);
                return $st['exitcode'] === 0 ? $out : null;
            }
            usleep(20000);
        }
        proc_terminate($proc, 9);
        fclose($pipes[1]);
        proc_close($proc);
        return null;
    }
}

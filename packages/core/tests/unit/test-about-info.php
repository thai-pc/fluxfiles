<?php

/**
 * Operator About report (AboutInfo): structure, module discovery, and the
 * no-secrets guard, plus `fluxfiles about [--json]` end to end.
 *
 * Usage: php packages/core/tests/unit/test-about-info.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\AboutInfo;
use FluxFiles\LicenseCheckInterface;
use FluxFiles\ModuleRegistry;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

echo "\n{$cyan}══ AboutInfo ══{$reset}\n\n";

// A throwaway "installed" module package: <pkg>/src/AboutFakeModule.php + composer.json.
$pkg = sys_get_temp_dir() . '/ff-about-' . bin2hex(random_bytes(4));
mkdir($pkg . '/src', 0777, true);
file_put_contents($pkg . '/composer.json', json_encode(['name' => 'fluxfiles/virus', 'require' => ['fluxfiles/fluxfiles' => '^1.0']]));
file_put_contents($pkg . '/.fluxfiles-version', "2.3.4\n");
file_put_contents($pkg . '/src/AboutFakeModule.php', '<?php namespace FluxFiles\\AboutFake; final class AboutFakeModule implements \\FluxFiles\\ModuleInterface { public static function id(): string { return "virus"; } public static function claim(): string { return "allow_virus_scan"; } }');
require_once $pkg . '/src/AboutFakeModule.php';

$license = new class implements LicenseCheckInterface {
    public function licensed(string $module): bool { return $module === 'virus'; }
    public function status(): string { return 'active'; }
    public function info(): array { return ['edition' => 'pro', 'status' => 'active']; }
};

$secret = 'SUPER-SECRET-VALUE-0123456789abcdef0123456789';
$env = [
    'FLUXFILES_SECRET' => $secret,
    'FLUXFILES_LICENSE_KEY' => 'LICENCE-KEY-SHOULD-NEVER-APPEAR',
    'FLUXFILES_CORE_VERSION' => 'core-v1.2.3',
    'FLUXFILES_SSRF_ALLOW_HOSTS' => 'sftp.internal.example, 10.0.0.5',
    'AWS_SECRET_ACCESS_KEY' => 'AWS-SECRET-VALUE',
];
$disks = [
    'local' => ['driver' => 'local', 'root' => '/srv/very/private/path'],
    'media' => ['driver' => 's3', 'bucket' => 'secret-bucket', 'key' => 'AKIAKEYSHOULDNOTAPPEAR', 'secret' => 'DISK-SECRET', 'endpoint' => 'https://user:pass@minio.internal'],
];

ModuleRegistry::register('virus', '\\FluxFiles\\AboutFake\\AboutFakeModule');
$info = AboutInfo::collect(['env' => $env, 'disks' => $disks, 'license' => $license, 'probe_tools' => false]);
ModuleRegistry::reset();

test('core version comes from the env override and says so', function () use ($info) {
    assertEqual(['version' => '1.2.3', 'source' => 'env', 'bundled_with' => null], $info['core']);
});

test('PHP version and storage backend are reported', function () use ($info) {
    assertEqual(PHP_VERSION, $info['php']['version']);
    assertEqual('json', $info['storage_backend']);
    $db = AboutInfo::collect(['env' => ['FLUXFILES_STORAGE_BACKEND' => 'DB'], 'disks' => [], 'license' => new \FluxFiles\NoLicense(), 'probe_tools' => false]);
    assertEqual('db', $db['storage_backend']);
});

test('disks are listed by name and driver only', function () use ($info) {
    assertEqual([['name' => 'local', 'driver' => 'local'], ['name' => 'media', 'driver' => 's3']], $info['disks']);
});

test('every registry module appears; absent ones are not installed', function () use ($info) {
    assertEqual(ModuleRegistry::ids(), array_keys($info['modules']));
    assertEqual(false, $info['modules']['share']['installed']);
    assertEqual(false, $info['modules']['share']['licensed']);
});

test('installed module: version, licence, enabled, requires_core and compatibility', function () use ($info) {
    $m = $info['modules']['virus'];
    assertEqual(true, $m['installed']);
    assertEqual('2.3.4', $m['version']);
    assertEqual(true, $m['licensed']);
    assertEqual(true, $m['enabled']);
    assertEqual('^1.0', $m['requires_core']);
    assertEqual(true, $m['core_compatible']);
    assertEqual(true, $info['scanning']['virus']);
    assertEqual(false, $info['scanning']['dlp']);
});

test('SSRF allow-hosts are a count, never the hosts', function () use ($info) {
    assertEqual(2, $info['ssrf_allow_hosts']);
});

test('no secret, key, credential, endpoint or path leaks (array, rows, text, json)', function () use ($info, $pkg) {
    $blob = json_encode($info) . json_encode(AboutInfo::rows($info)) . AboutInfo::renderText($info);
    foreach (['SUPER-SECRET', 'LICENCE-KEY', 'AWS-SECRET', 'AKIAKEY', 'DISK-SECRET', 'secret-bucket', 'minio.internal', 'user:pass', '/srv/very/private', 'sftp.internal', '10.0.0.5', $pkg] as $needle) {
        assertTrue(!str_contains($blob, $needle), "leaked: {$needle}");
    }
});

test('rows mark disks, licence and SSRF as private', function () use ($info) {
    $private = array_column(array_filter(AboutInfo::rows($info), static fn ($r) => $r['private']), 'label');
    assertEqual(['Disks', 'Licence', 'SSRF allow-hosts'], array_values($private));
});

test('tool probing returns the documented shape', function () {
    $i = AboutInfo::collect(['env' => [], 'disks' => [], 'license' => new \FluxFiles\NoLicense(), 'probe_tools' => true]);
    foreach (['ffmpeg', 'ghostscript', 'tesseract', 'clamd'] as $k) {
        assertTrue(is_bool($i['tools'][$k]['available']), $k);
    }
    if ($i['tools']['ghostscript']['version'] !== null) {
        assertEqual(version_compare($i['tools']['ghostscript']['version'], '10.05.0', '>='), $i['tools']['ghostscript']['meets_minimum']);
    }
});

test('proc functions unavailable: probes skipped with a reason, never throws', function () {
    $i = AboutInfo::collect(['env' => [], 'disks' => [], 'license' => new \FluxFiles\NoLicense(), 'probe_tools' => true, 'proc_available' => false]);
    assertEqual('proc_open disabled', $i['tools']['probe_skipped']);
    assertEqual(false, $i['tools']['ffmpeg']['available']);
    $row = array_values(array_filter(AboutInfo::rows($i), static fn ($r) => $r['label'] === 'External tools'));
    assertEqual('unavailable: proc_open disabled', $row[0]['value'] ?? null);
});

test('unknown core version: CLI hint by default, "bundled with" for an embedder', function () {
    $mk = static fn (array $o) => AboutInfo::collect($o + ['env' => [], 'disks' => [], 'license' => new \FluxFiles\NoLicense(), 'probe_tools' => false]);
    $cv = static fn (array $i) => array_values(array_filter(AboutInfo::rows($i), static fn ($r) => $r['label'] === 'Core version'))[0]['value'];
    $a = $mk([]);
    if ($a['core']['version'] === null) {
        assertTrue(str_contains($cv($a), 'FLUXFILES_CORE_VERSION'));
        $b = $mk(['bundled_with' => 'plugin 1.0.4']);
        assertEqual('bundled with plugin 1.0.4', $cv($b));
        assertTrue(!str_contains($cv($b), 'FLUXFILES_CORE_VERSION'));
    }
});

// CLI: spawn it with a poisoned environment and check stdout.
$run = function (array $args) use ($secret): array {
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/../../bin/fluxfiles', 'about'], $args);
    $env = ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir(), 'FLUXFILES_SECRET' => $secret,
        'FLUXFILES_LICENSE_KEY' => 'LICENCE-KEY-SHOULD-NEVER-APPEAR', 'AWS_SECRET_ACCESS_KEY' => 'AWS-SECRET-VALUE',
        'AWS_ACCESS_KEY_ID' => 'AKIAKEYSHOULDNOTAPPEAR', 'FLUXFILES_CORE_VERSION' => '1.2.3'];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
};

test('fluxfiles about prints a table and leaks nothing', function () use ($run) {
    [$code, $out, $err] = $run([]);
    assertEqual(0, $code, $err);
    assertTrue(str_contains($out, 'Core version') && str_contains($out, '1.2.3 (env)'), $out);
    foreach (['SUPER-SECRET', 'LICENCE-KEY', 'AWS-SECRET', 'AKIAKEY'] as $needle) {
        assertTrue(!str_contains($out, $needle), "leaked: {$needle}");
    }
});

test('fluxfiles about --json is valid JSON with the documented keys', function () use ($run) {
    [$code, $out, $err] = $run(['--json']);
    assertEqual(0, $code, $err);
    $j = json_decode($out, true);
    assertTrue(is_array($j), 'invalid json');
    foreach (['core', 'php', 'storage_backend', 'disks', 'license', 'modules', 'scanning', 'tools', 'ssrf_allow_hosts'] as $k) {
        assertTrue(array_key_exists($k, $j), "missing {$k}");
    }
    assertTrue(!str_contains($out, 'SUPER-SECRET') && !str_contains($out, 'AKIAKEY'), 'leak');
});

test('fluxfiles about rejects unknown options', function () use ($run) {
    [$code] = $run(['--bogus']);
    assertEqual(1, $code);
});

@unlink($pkg . '/src/AboutFakeModule.php');
@unlink($pkg . '/composer.json');
@unlink($pkg . '/.fluxfiles-version');
@rmdir($pkg . '/src');
@rmdir($pkg);

echo "\n  {$passed} passed, {$failed} failed\n\n";
exit($failed > 0 ? 1 : 0);

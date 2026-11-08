<?php

/**
 * bin/fluxfiles finds the HOST project's .env in a Composer install. The script
 * lives at <project>/vendor/fluxfiles/fluxfiles/bin/fluxfiles, and it used to look
 * only in the package root and <project>/vendor, so a FLUXFILES_LICENSE_KEY kept in
 * the project's .env was never seen ("No FLUXFILES_LICENSE_KEY set"). This builds a
 * fake project and runs the REAL CLI (`update share --check`) against it. The update
 * server is pointed at a closed local port, so nothing leaves the machine.
 *
 * Usage: php tests/unit/test-cli-env-discovery.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\EnvBootstrap;

$core = dirname(__DIR__, 2);

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function rrmdir(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

/** Run the fake project's CLI from $cwd with a scrubbed environment. */
function runCli(string $project, string $cwd): string
{
    $env = [
        'PATH' => (string) getenv('PATH'),
        'HOME' => $project,
        'FLUXFILES_UPDATE_URL' => 'http://127.0.0.1:1',
    ];
    $cmd = [PHP_BINARY, $project . '/vendor/fluxfiles/fluxfiles/bin/fluxfiles', 'update', 'share', '--check'];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($p);
    return $out;
}

$tmp = realpath(sys_get_temp_dir()) . '/fx-cli-env-' . bin2hex(random_bytes(4));
$project = $tmp . '/project';
$pkg = $project . '/vendor/fluxfiles/fluxfiles';
mkdir($pkg . '/bin', 0777, true);
mkdir($tmp . '/elsewhere', 0777, true);
copy($core . '/bin/fluxfiles', $pkg . '/bin/fluxfiles');
copy($core . '/autoload.php', $pkg . '/autoload.php');
copy($core . '/modules-autoload.php', $pkg . '/modules-autoload.php');
// Composer's host autoloader, standing in with the real core's dependencies.
file_put_contents($project . '/vendor/autoload.php', "<?php\nrequire " . var_export($core . '/vendor/autoload.php', true) . ";\n");
file_put_contents($project . '/composer.json', '{}');

echo "\nCLI .env discovery (Composer layout)\n";

test('cliDirs puts the host project root ahead of the package root', function () use ($pkg, $project, $tmp) {
    $dirs = EnvBootstrap::cliDirs($pkg . '/bin', $tmp . '/elsewhere');
    assertTrue($dirs[0] === realpath($project), 'host root first: ' . json_encode($dirs));
    assertTrue(in_array(realpath($pkg), $dirs, true), 'package root kept');
    assertTrue(!in_array(realpath($tmp . '/elsewhere'), $dirs, true), 'a cwd without composer.json/.env is ignored');
});

test('cliDirs leads with the cwd when it holds composer.json or .env', function () use ($core, $tmp) {
    $work = $tmp . '/cwd';
    mkdir($work);
    file_put_contents($work . '/.env', "X=1\n");
    $dirs = EnvBootstrap::cliDirs($core . '/bin', $work);
    assertTrue($dirs[0] === realpath($work), 'cwd first: ' . json_encode($dirs));
    assertTrue(count($dirs) === count(array_unique($dirs)), 'no duplicates');
});

test('no .env anywhere: the CLI reports the missing license key', function () use ($project, $tmp) {
    $out = runCli($project, $tmp . '/elsewhere');
    assertTrue(str_contains($out, 'No FLUXFILES_LICENSE_KEY'), "got: {$out}");
});

file_put_contents($project . '/.env', "FLUXFILES_LICENSE_KEY=dummy\n");

test('host project .env is read when run from an unrelated directory', function () use ($project, $tmp) {
    $out = runCli($project, $tmp . '/elsewhere');
    assertTrue(!str_contains($out, 'No FLUXFILES_LICENSE_KEY'), "license key not seen: {$out}");
});

test('host project .env is read when run from the project root', function () use ($project) {
    $out = runCli($project, $project);
    assertTrue(!str_contains($out, 'No FLUXFILES_LICENSE_KEY'), "license key not seen: {$out}");
});

rrmdir($tmp);
echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

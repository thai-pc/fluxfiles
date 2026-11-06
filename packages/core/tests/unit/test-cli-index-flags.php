<?php

/**
 * `fluxfiles index` flag parsing. getopt() stops at the first non-option arg
 * ("index"), so every flag used to be silently dropped: --dry-run wrote the
 * index for real and --disk/--path/--owner were ignored. This runs the REAL
 * bin/fluxfiles as a subprocess against a throwaway package copy (its own
 * storage/uploads), so nothing in the repo's storage is touched.
 *
 * Usage:
 *   php tests/unit/test-cli-index-flags.php
 */

declare(strict_types=1);

$core = dirname(__DIR__, 2);

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
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

// Throwaway "package": the real CLI + disks config, with storage/ beside it.
$pkg = sys_get_temp_dir() . '/fx-cli-' . bin2hex(random_bytes(4));
mkdir($pkg . '/bin', 0777, true);
mkdir($pkg . '/config', 0777, true);
copy($core . '/bin/fluxfiles', $pkg . '/bin/fluxfiles');
copy($core . '/config/disks.php', $pkg . '/config/disks.php');
file_put_contents($pkg . '/autoload.php', "<?php\nrequire_once " . var_export($core . '/autoload.php', true) . ";\n");

$root = $pkg . '/storage/uploads';
mkdir($root . '/a', 0777, true);
mkdir($root . '/b', 0777, true);
file_put_contents($root . '/a/one.txt', 'one');
file_put_contents($root . '/b/two.txt', 'two');

function cli(string $pkg, array $args, array $env = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pkg . '/bin/fluxfiles');
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $pkg, [
        'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'),
    ] + $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return ['out' => (string) $out, 'err' => (string) $err, 'code' => proc_close($p)];
}
function wipe(string $root): void { rrmdir($root . '/_fluxfiles'); }
function stat_of(string $out, string $key): int
{
    return preg_match('/' . preg_quote($key, '/') . ': (\d+)/', $out, $m) ? (int) $m[1] : -1;
}

test('--dry-run counts but writes nothing', function () use ($pkg, $root) {
    wipe($root);
    $r = cli($pkg, ['index', '--dry-run']);
    assertTrue($r['code'] === 0, 'exit ' . $r['code'] . ' ' . $r['err']);
    assertTrue(stat_of($r['out'], 'Files indexed') === 2, 'dry run still scans: ' . $r['out']);
    assertTrue(!is_dir($root . '/_fluxfiles'), 'dry run must not create _fluxfiles/');
});

test('without --dry-run the index is written', function () use ($pkg, $root) {
    wipe($root);
    $r = cli($pkg, ['index']);
    assertTrue($r['code'] === 0 && stat_of($r['out'], 'Files indexed') === 2, $r['out'] . $r['err']);
    assertTrue(is_dir($root . '/_fluxfiles'), 'index must be written');
});

test('--path limits the scan', function () use ($pkg, $root) {
    wipe($root);
    $r = cli($pkg, ['index', '--path=a', '--dry-run']);
    assertTrue(stat_of($r['out'], 'Files indexed') === 1, 'only a/ expected: ' . $r['out']);
});

test('--disk is honoured (unknown disk is rejected)', function () use ($pkg) {
    $r = cli($pkg, ['index', '--disk=nope']);
    assertTrue($r['code'] === 1 && str_contains($r['err'], "Disk 'nope' is not configured"), $r['err']);
});

test('--disk=local works explicitly', function () use ($pkg, $root) {
    wipe($root);
    $r = cli($pkg, ['index', '--disk=local', '--dry-run']);
    assertTrue($r['code'] === 0 && stat_of($r['out'], 'Files indexed') === 2, $r['out'] . $r['err']);
});

test('an unknown option is rejected, nothing is written', function () use ($pkg, $root) {
    wipe($root);
    $r = cli($pkg, ['index', '--dryrun']);
    assertTrue($r['code'] === 1 && str_contains($r['err'], 'Unknown option --dryrun'), $r['err']);
    assertTrue(!is_dir($root . '/_fluxfiles'), 'must not have indexed');
});

test('a value option without a value is rejected', function () use ($pkg) {
    $r = cli($pkg, ['index', '--path']);
    assertTrue($r['code'] === 1 && str_contains($r['err'], 'needs a value'), $r['err']);
});

// FLUXFILES_DISKS_CONFIG: an operator file outside the package replaces the default.
$alt = sys_get_temp_dir() . '/fx-cli-alt-' . bin2hex(random_bytes(4));
mkdir($alt . '/files', 0777, true);
file_put_contents($alt . '/files/x.txt', 'x');
file_put_contents($alt . '/files/y.txt', 'y');
file_put_contents($alt . '/files/z.txt', 'z');
file_put_contents($alt . '/disks.php', "<?php return ['alt' => ['driver' => 'local', 'root' => " . var_export($alt . '/files', true) . "]];\n");

test('FLUXFILES_DISKS_CONFIG replaces the default disks in the CLI', function () use ($pkg, $alt) {
    $r = cli($pkg, ['index', '--disk=alt', '--dry-run'], ['FLUXFILES_DISKS_CONFIG' => $alt . '/disks.php']);
    assertTrue($r['code'] === 0 && stat_of($r['out'], 'Files indexed') === 3, $r['out'] . $r['err']);
    $r = cli($pkg, ['index', '--disk=local', '--dry-run'], ['FLUXFILES_DISKS_CONFIG' => $alt . '/disks.php']);
    assertTrue($r['code'] === 1 && str_contains($r['err'], "Disk 'local' is not configured"), 'replaced, not merged: ' . $r['err']);
});

test('a broken FLUXFILES_DISKS_CONFIG fails with exit 1, no fallback to local', function () use ($pkg, $root) {
    wipe($root);
    $r = cli($pkg, ['index'], ['FLUXFILES_DISKS_CONFIG' => '/nonexistent/disks.php']);
    assertTrue($r['code'] === 1 && str_contains($r['err'], 'FLUXFILES_DISKS_CONFIG'), $r['err']);
    assertTrue(!is_dir($root . '/_fluxfiles'), 'must not have indexed the default disk');
});

rrmdir($alt);

rrmdir($pkg);
echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n\n";
exit($failed > 0 ? 1 : 0);

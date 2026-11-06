<?php

/**
 * DiskConfig::load(): FLUXFILES_DISKS_CONFIG replaces config/disks.php, and a
 * set-but-broken path fails closed instead of falling back to the default disk.
 *
 * Usage:
 *   php tests/unit/test-disk-config.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskConfig;

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function fails(callable $fn): ?\FluxFiles\DiskConfigException
{
    try { $fn(); } catch (\FluxFiles\DiskConfigException $e) { return $e; }
    return null;
}

$tmp = sys_get_temp_dir() . '/fx-diskcfg-' . bin2hex(random_bytes(4));
mkdir($tmp);
$good = $tmp . '/disks.php';
file_put_contents($good, "<?php return ['mine' => ['driver' => 'local', 'root' => '/tmp/x']];\n");
$bad = $tmp . '/bad.php';
file_put_contents($bad, "<?php return 'nope';\n");
$boom = $tmp . '/boom.php';
file_put_contents($boom, "<?php throw new \\RuntimeException('kaput');\n");
$locked = $tmp . '/locked.php';
file_put_contents($locked, "<?php return [];\n");
chmod($locked, 0000);
$noisy = $tmp . '/noisy.php';
file_put_contents($noisy, "<?php echo 'oops'; return ['a' => []];\n");

test('unset uses the package default', function () {
    unset($_ENV['FLUXFILES_DISKS_CONFIG']);
    assertTrue(isset(DiskConfig::load()['local']), 'default has local');
});
test('empty string uses the package default', function () {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = '';
    assertTrue(isset(DiskConfig::load()['local']), 'default has local');
});
test('a valid file replaces the default entirely', function () use ($good) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $good;
    $d = DiskConfig::load();
    assertTrue(array_keys($d) === ['mine'], 'only the operator disks: ' . implode(',', array_keys($d)));
});
test('a missing file fails closed', function () use ($tmp) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $tmp . '/absent.php';
    $e = fails(fn () => DiskConfig::load());
    assertTrue($e !== null && $e->getHttpCode() === 500 && $e->getMessage() === 'Server disk configuration is invalid' && !str_contains($e->getMessage(), '/') && str_contains($e->getDetail(), 'absent.php'), 'expected generic 500 with detail kept apart');
});
test('a file returning a non-array fails closed', function () use ($bad) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $bad;
    assertTrue(fails(fn () => DiskConfig::load()) !== null, 'expected failure');
});
test('a file that throws fails closed', function () use ($boom) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $boom;
    $e = fails(fn () => DiskConfig::load());
    assertTrue($e !== null && str_contains($e->getDetail(), 'kaput') && !str_contains($e->getMessage(), 'kaput'), 'detail only in getDetail');
});
test('an unreadable file fails closed', function () use ($locked) {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) { return; } // root reads anything
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $locked;
    assertTrue(fails(fn () => DiskConfig::load()) !== null, 'expected failure');
});
test('a relative path fails closed', function () {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = 'config/disks.php';
    $e = fails(fn () => DiskConfig::load());
    assertTrue($e !== null && str_contains($e->getDetail(), 'absolute'), 'expected absolute-path error');
});
test('a file that prints output fails closed and leaks no buffer', function () use ($noisy) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $noisy;
    $lvl = ob_get_level();
    $e = fails(fn () => DiskConfig::load());
    assertTrue($e !== null && str_contains($e->getDetail(), 'printed output'), 'expected output error');
    assertTrue(ob_get_level() === $lvl, 'output buffer balanced');
});
test('a directory path fails closed', function () use ($tmp) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $tmp;
    assertTrue(fails(fn () => DiskConfig::load()) !== null, 'expected failure');
});

chmod($locked, 0600);
array_map('unlink', glob($tmp . '/*.php'));
rmdir($tmp);
echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n\n";
exit($failed > 0 ? 1 : 0);

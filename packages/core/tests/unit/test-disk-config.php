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

use FluxFiles\ApiException;
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
function fails(callable $fn): ?ApiException
{
    try { $fn(); } catch (ApiException $e) { return $e; }
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
    assertTrue($e !== null && $e->getHttpCode() === 500 && str_contains($e->getMessage(), 'FLUXFILES_DISKS_CONFIG'), 'expected 500');
});
test('a file returning a non-array fails closed', function () use ($bad) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $bad;
    assertTrue(fails(fn () => DiskConfig::load()) !== null, 'expected failure');
});
test('a file that throws fails closed', function () use ($boom) {
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $boom;
    $e = fails(fn () => DiskConfig::load());
    assertTrue($e !== null && str_contains($e->getMessage(), 'kaput'), 'expected wrapped error');
});
test('an unreadable file fails closed', function () use ($locked) {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) { return; } // root reads anything
    $_ENV['FLUXFILES_DISKS_CONFIG'] = $locked;
    assertTrue(fails(fn () => DiskConfig::load()) !== null, 'expected failure');
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

<?php

/**
 * OptimizeStats — the storage-resident "bytes saved" counter that the (paid)
 * optimize module records into and /api/fm/usage surfaces. Core MIT primitive.
 *
 * Usage: php tests/integration/test-optimize-stats.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\OptimizeStats;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

function diskFs(): array {
    $root = sys_get_temp_dir() . '/ff-optstats-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    return [$dm->disk('local'), $root];
}

echo "\n{$cyan}══ OptimizeStats (M2) ══{$reset}\n\n";

test('read on a fresh disk → zeros', function () {
    [$fs] = diskFs();
    assertEqual(['total_saved_bytes' => 0, 'files_optimized' => 0, 'updated_at' => null], OptimizeStats::read($fs, ''));
});

test('record accumulates total + count, sets updated_at', function () {
    [$fs] = diskFs();
    OptimizeStats::record($fs, '', 1000, 1);
    OptimizeStats::record($fs, '', 500, 2);
    $s = OptimizeStats::read($fs, '');
    assertEqual(1500, $s['total_saved_bytes']);
    assertEqual(3, $s['files_optimized']);
    assertTrue(is_int($s['updated_at']) && $s['updated_at'] > 0, 'updated_at set');
});

test('zero / negative savings are ignored', function () {
    [$fs] = diskFs();
    OptimizeStats::record($fs, '', 0, 1);
    OptimizeStats::record($fs, '', -50, 1);
    assertEqual(0, OptimizeStats::read($fs, '')['files_optimized']);
});

test('per-prefix isolation (separate files under each prefix)', function () {
    [$fs] = diskFs();
    OptimizeStats::record($fs, 'users/1', 100, 1);
    OptimizeStats::record($fs, 'users/2', 200, 1);
    assertEqual(100, OptimizeStats::read($fs, 'users/1')['total_saved_bytes']);
    assertEqual(200, OptimizeStats::read($fs, 'users/2')['total_saved_bytes']);
    assertEqual(0, OptimizeStats::read($fs, '')['total_saved_bytes'], 'root untouched');
    // file lands under the prefix tree
    assertTrue($fs->fileExists('users/1/_fluxfiles/optimize.json'), 'prefix-scoped file');
});

test('corrupt json → zeros (never throws)', function () {
    [$fs, $root] = diskFs();
    @mkdir("$root/_fluxfiles", 0777, true);
    file_put_contents("$root/_fluxfiles/optimize.json", 'not json{');
    assertEqual(0, OptimizeStats::read($fs, '')['files_optimized']);
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

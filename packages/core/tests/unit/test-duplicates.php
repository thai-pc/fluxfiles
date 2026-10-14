<?php

/**
 * FileManager::findDuplicates() — the usage dashboard's duplicate finder.
 *
 * Usage: php tests/unit/test-duplicates.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

/** @return array{0:FileManager,1:string} root = the tenant's directory */
function dupEnv(array $files, string $prefix = 't1', array $props = []): array {
    $base = sys_get_temp_dir() . '/fluxfiles-dup-' . uniqid();
    foreach ($files as $rel => $bytes) {
        @mkdir(dirname("{$base}/{$rel}"), 0777, true);
        file_put_contents("{$base}/{$rel}", $bytes);
        if (str_contains($rel, 'old')) { touch("{$base}/{$rel}", time() - 3600); }
    }
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $base, 'url' => '/s']]);
    $c = new Claims('u', ['read'], ['local'], $prefix, 50, null, 0, false);
    foreach ($props as $k => $v) { $c->{$k} = $v; }
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $base];
}

echo "\n{$cyan}══ Duplicate finder ══{$reset}\n\n";

test('groups byte-identical files, oldest first, with the wasted bytes', function () {
    $big = str_repeat('A', 5000);
    [$fm] = dupEnv([
        't1/photos/old-copy.jpg' => $big, 't1/a.jpg' => $big, 't1/b/c.jpg' => $big,
        't1/same-size.txt' => str_repeat('B', 5000),   // same size, different bytes
        't1/unique.txt' => 'u',
    ]);
    $r = $fm->findDuplicates('local');
    assertTrue(count($r['groups']) === 1, json_encode($r));
    $g = $r['groups'][0];
    assertTrue($g['count'] === 3 && $g['size'] === 5000 && $g['wasted'] === 10000, 'group sums');
    assertTrue($g['files'][0]['key'] === 'photos/old-copy.jpg', 'oldest first, prefix stripped: ' . json_encode($g['files']));
    assertTrue($r['total_wasted'] === 10000 && $r['scanned_files'] === 5 && $r['truncated'] === false, 'totals');
});

test('another tenant\'s identical copy is not reported', function () {
    [$fm] = dupEnv(['t1/a.txt' => 'same-bytes', 't2/a.txt' => 'same-bytes']);
    assertTrue($fm->findDuplicates('local')['groups'] === [], 'scoped to the prefix');
});

test('reserved and hidden paths are skipped (unless show_hidden)', function () {
    $files = ['t1/a.txt' => 'dup!', 't1/_fluxfiles/x.txt' => 'dup!', 't1/.env' => 'dup!'];
    [$fm] = dupEnv($files);
    assertTrue($fm->findDuplicates('local')['groups'] === [], 'nothing visible duplicates a.txt');
    [$fm] = dupEnv($files, 't1', ['showHidden' => true]);
    assertTrue($fm->findDuplicates('local')['groups'][0]['count'] === 2, '.env counted with show_hidden');
});

test('the hashing budget stops early and says so', function () {
    [$fm] = dupEnv(['t1/a.bin' => str_repeat('x', 4000), 't1/b.bin' => str_repeat('x', 4000)]);
    $r = $fm->findDuplicates('local', 50, 5000); // room for one file, not two
    assertTrue($r['truncated'] === true && $r['groups'] === [], json_encode($r));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

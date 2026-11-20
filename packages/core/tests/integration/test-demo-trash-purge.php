<?php

/**
 * DemoMode::purge() must also purge (and budget) a sandbox's trashed files.
 *
 * Regression (audit 2026-11-20, R1-2): the demo UI soft-deletes into the
 * disk-wide `_fluxfiles/trash/<id>/`, outside `demo/<sandbox>/`, which purge()
 * neither counted nor removed — upload → trash → repeat grew the disk without
 * bound past both the per-visitor quota and FLUXFILES_DEMO_TOTAL_MB.
 *
 * Usage: php tests/integration/test-demo-trash-purge.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DemoMode;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

putenv('FLUXFILES_STORAGE_BACKEND=json');
putenv('FLUXFILES_DEMO_TTL_HOURS=6');
putenv('FLUXFILES_DEMO_TOTAL_MB=50');

function newRoot(): string
{
    $root = sys_get_temp_dir() . '/fluxfiles-demo-trash-' . uniqid();
    mkdir($root, 0777, true);
    return $root;
}
/** Upload one file into demo/<sandbox>/ and trash it; returns the trash id. */
function uploadAndTrash(string $root, string $sandbox, string $name, int $bytes = 4): string
{
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    $fm = new FileManager($dm, new Claims('demo-' . $sandbox, ['read', 'write', 'delete'], ['local'], 'demo/' . $sandbox, 50, null, 0, false), new StorageMetadataHandler($dm));
    $tmp = tempnam(sys_get_temp_dir(), 'fxd');
    file_put_contents($tmp, 'data');
    $fm->upload('local', '', ['name' => $name, 'size' => 4, 'tmp_name' => $tmp], true);
    $r = $fm->trash('local', $name);
    $id = (string) ($r['id'] ?? $r['trash_id'] ?? '');
    assertTrue($id !== '', 'trash returned no id: ' . json_encode($r));
    if ($bytes > 4) {
        // Sparse: counted by filesize() like real bytes, costs nothing on disk.
        $fh = fopen("$root/_fluxfiles/trash/$id/$name", 'r+');
        ftruncate($fh, $bytes);
        fclose($fh);
    }
    return $id;
}
function trashIndex(string $root): array
{
    $f = "$root/_fluxfiles/trash.json";
    return is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
}

echo "\ndemo purge covers trash\n";

test('an expired sandbox takes its trashed files and index entries with it', function () {
    $root = newRoot();
    $id = uploadAndTrash($root, 'old1', 'a.txt');
    assertTrue(is_dir("$root/_fluxfiles/trash/$id") && isset(trashIndex($root)[$id]), 'precondition: file is in the trash');
    touch("$root/demo/old1", time() - 7 * 3600);
    DemoMode::purge($root);
    assertTrue(!is_dir("$root/demo/old1"), 'sandbox removed');
    assertTrue(!is_dir("$root/_fluxfiles/trash/$id"), 'trashed bytes removed');
    assertTrue(!isset(trashIndex($root)[$id]), 'trash.json entry removed');
});

test('trash left by a sandbox that no longer exists is swept', function () {
    $root = newRoot();
    $id = uploadAndTrash($root, 'gone', 'b.txt');
    $keep = uploadAndTrash($root, 'live', 'c.txt');
    // the sandbox folder was removed earlier (e.g. by the pre-fix purge)
    array_map('unlink', glob("$root/demo/gone/*") ?: []);
    @rmdir("$root/demo/gone");
    DemoMode::purge($root);
    assertTrue(!is_dir("$root/_fluxfiles/trash/$id") && !isset(trashIndex($root)[$id]), 'orphan trash swept');
    assertTrue(is_dir("$root/_fluxfiles/trash/$keep") && isset(trashIndex($root)[$keep]), 'a live sandbox keeps its trash');
});

test('trashed bytes count toward FLUXFILES_DEMO_TOTAL_MB (oldest sandbox goes)', function () {
    $root = newRoot();
    $big = uploadAndTrash($root, 'older', 'big.bin', 60 * 1024 * 1024);
    $small = uploadAndTrash($root, 'newer', 's.txt');
    touch("$root/demo/older", time() - 3600);
    DemoMode::purge($root);
    assertTrue(!is_dir("$root/demo/older") && !is_dir("$root/_fluxfiles/trash/$big"), 'over budget: the oldest sandbox and its trash go');
    assertTrue(is_dir("$root/demo/newer") && is_dir("$root/_fluxfiles/trash/$small"), 'the newer sandbox stays');
});

test('trash that is not from a demo sandbox is never touched', function () {
    $root = newRoot();
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    $fm = new FileManager($dm, new Claims('op', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false), new StorageMetadataHandler($dm));
    $tmp = tempnam(sys_get_temp_dir(), 'fxd');
    file_put_contents($tmp, 'ops');
    $fm->upload('local', '', ['name' => 'ops.txt', 'size' => 3, 'tmp_name' => $tmp], true);
    $r = $fm->trash('local', 'ops.txt');
    $id = (string) ($r['id'] ?? $r['trash_id'] ?? '');
    $old = uploadAndTrash($root, 'old2', 'd.txt');
    touch("$root/demo/old2", time() - 7 * 3600);
    DemoMode::purge($root);
    assertTrue(is_dir("$root/_fluxfiles/trash/$id") && isset(trashIndex($root)[$id]), 'operator trash kept');
    assertTrue(!isset(trashIndex($root)[$old]), 'demo trash still purged');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

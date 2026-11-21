<?php

/**
 * Trashed files count toward the quota of the prefix that trashed them.
 *
 * Regression (audit 2026-11-20, R2-2): trash lives in the disk-wide
 * `_fluxfiles/trash/`, outside every prefix, so a tenant with a 1 MB quota could
 * upload → trash → repeat without limit while /quota reported 0 used.
 *
 * Usage: php tests/integration/test-quota-counts-trash.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\FileManagerFactory;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function setup(string $prefix, int $quotaMb): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-qt-' . uniqid();
    mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local'], $prefix, 50, null, $quotaMb, false);
    $fm = FileManagerFactory::wire(new FileManager($dm, $claims, $meta), $claims, $dm, $meta, str_repeat('s', 40));
    return [$fm, $dm, $meta, $root];
}
function put(FileManager $fm, string $name, int $bytes): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxq');
    file_put_contents($tmp, str_repeat('x', $bytes));
    $fm->upload('local', '', ['name' => $name, 'size' => $bytes, 'tmp_name' => $tmp], true);
}

echo "\nquota counts trash\n";

test('scoped tenant: upload → trash → upload again is refused once trash fills the quota', function () {
    [$fm] = setup('users/1', 1);
    put($fm, 'a.bin', 600 * 1024);
    $fm->trash('local', 'a.bin');
    try {
        put($fm, 'b.bin', 600 * 1024);
    } catch (ApiException $e) {
        assertTrue($e->getCode() === 413, 'expected 413, got ' . $e->getCode());
        return;
    }
    throw new \RuntimeException('second upload was allowed although the trash holds 600 KB of a 1 MB quota');
});

test('emptying the trash frees the quota again', function () {
    [$fm] = setup('users/1', 1);
    put($fm, 'a.bin', 600 * 1024);
    $fm->trash('local', 'a.bin');
    $fm->emptyTrash('local');
    put($fm, 'b.bin', 600 * 1024);
    assertTrue(true, '');
});

test('another prefix\'s trash does not count against this tenant', function () {
    [$fmA, $dm, $meta] = setup('users/1', 1);
    $claimsB = new Claims('u2', ['read', 'write', 'delete'], ['local'], 'users/2', 50, null, 1, false);
    $fmB = FileManagerFactory::wire(new FileManager($dm, $claimsB, $meta), $claimsB, $dm, $meta, str_repeat('s', 40));
    put($fmB, 'b.bin', 900 * 1024);
    $fmB->trash('local', 'b.bin');
    put($fmA, 'a.bin', 900 * 1024);   // must still fit
    $q = (new QuotaManager($dm))->setMetadata($meta);
    assertTrue($q->trashBytes('local', 'users/2') === 900 * 1024, 'users/2 owns its trash');
    assertTrue($q->trashBytes('local', 'users/1') === 0, 'users/1 has none');
    assertTrue($q->trashBytes('local', 'users') === 900 * 1024, 'a parent prefix sees its children\'s trash');
});

test('unscoped token: the trash is counted once, not twice', function () {
    [$fm, $dm, $meta] = setup('', 0);
    put($fm, 'a.bin', 1000);
    $fm->trash('local', 'a.bin');
    $q = (new QuotaManager($dm))->setMetadata($meta);
    $bytes = $q->walk('local', '')['bytes'];
    $data = 0;
    foreach ($dm->disk('local')->listContents('_fluxfiles/trash', true) as $i) {
        if ($i->isFile()) { $data += $i->fileSize(); }
    }
    assertTrue($data === 1000, 'precondition: 1000 bytes in trash');
    assertTrue($q->trashBytes('local', '') === 0, 'no extra trash bytes for the empty prefix');
    assertTrue($bytes >= 1000 && $bytes < 2000, 'walk counts the trashed bytes exactly once (got ' . $bytes . ')');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

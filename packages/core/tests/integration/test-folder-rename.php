<?php

/**
 * Folder rename/move — relocating a directory must carry the WHOLE subtree,
 * including directories that hold no files at any depth, and must never remove
 * the source before the destination exists.
 *
 * Regression: `rename()` used to move only `isFile()` entries and then
 * `deleteDirectory()` the source, so a folder with no files anywhere (empty, or
 * containing only subfolders) was destroyed and the folder index was left
 * pointing at a directory that no longer existed.
 *
 * Usage:
 *   php tests/integration/test-folder-rename.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . "/../..", __DIR__ . "/../../../.."] as $envDir) {
    if (is_file($envDir . "/.env")) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

function tmpTxt(string $c): string
{
    $p = sys_get_temp_dir() . '/fxfr-' . uniqid() . '.txt';
    file_put_contents($p, $c);
    return $p;
}
function imgFile(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 40, 120, 200));
    $p = sys_get_temp_dir() . '/fxfr-' . uniqid() . '.png';
    imagepng($im, $p); imagedestroy($im);
    return $p;
}
function up(FileManager $fm, string $dir, string $name, string $tmp): void
{
    $fm->upload('local', $dir, ['name' => $name, 'size' => filesize($tmp), 'tmp_name' => $tmp], true);
}
function makeFM(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-fr-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    return [new FileManager($dm, $claims, $meta), $dm->disk('local'), $meta, $root];
}

/** Every folder the index claims must actually exist on disk, and vice versa. */
function assertIndexMatchesDisk($fs, array $expectDirs, array $goneDirs): void
{
    $dirs = $fs->fileExists('_fluxfiles/dirs.json')
        ? (json_decode($fs->read('_fluxfiles/dirs.json'), true) ?: [])
        : [];
    foreach (array_keys($dirs) as $k) {
        assertTrue($fs->directoryExists((string) $k), "phantom folder-index entry: {$k}");
    }
    foreach ($expectDirs as $k) {
        assertTrue(array_key_exists($k, $dirs), "folder index missing {$k}");
    }
    foreach ($goneDirs as $k) {
        assertTrue(!array_key_exists($k, $dirs), "stale folder-index entry: {$k}");
    }
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Folder Rename/Move Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

// ── rename ──────────────────────────────────────────────────────────────────

test('rename an EMPTY folder keeps it alive under the new name', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'empty_dir');
    assertTrue($fs->directoryExists('empty_dir'), 'created');

    $fm->rename('local', 'empty_dir', 'empty_renamed');

    assertTrue($fs->directoryExists('empty_renamed'), 'destination exists on disk');
    assertTrue(!$fs->directoryExists('empty_dir'), 'source gone');
});

test('renamed empty folder is still listed by the API', function () {
    [$fm] = makeFM();
    $fm->mkdir('local', 'empty_dir');
    $fm->rename('local', 'empty_dir', 'empty_renamed');

    $names = array_map(fn($i) => $i['name'], $fm->list('local', ''));
    assertTrue(in_array('empty_renamed', $names, true), 'new name listed');
    assertTrue(!in_array('empty_dir', $names, true), 'old name not listed');
});

test('rename a folder whose ONLY content is a subfolder → child survives', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'parent');
    $fm->mkdir('local', 'parent/child');

    $fm->rename('local', 'parent', 'parent2');

    assertTrue($fs->directoryExists('parent2'), 'parent relocated');
    assertTrue($fs->directoryExists('parent2/child'), 'child survived');
    assertTrue(!$fs->directoryExists('parent'), 'source gone');
});

test('rename a deep tree mixing empty dirs and files relocates everything', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'tree', 'top.txt', tmpTxt('top'));
    up($fm, 'tree/docs', 'a.txt', tmpTxt('a'));
    up($fm, 'tree/docs/deep', 'b.txt', tmpTxt('b'));
    $fm->mkdir('local', 'tree/empty');
    $fm->mkdir('local', 'tree/docs/also_empty');
    $fm->mkdir('local', 'tree/empty/nested_empty');

    $fm->rename('local', 'tree', 'tree2');

    foreach (['tree2/top.txt', 'tree2/docs/a.txt', 'tree2/docs/deep/b.txt'] as $k) {
        assertTrue($fs->fileExists($k), "file relocated: {$k}");
    }
    foreach (['tree2/empty', 'tree2/docs/also_empty', 'tree2/empty/nested_empty'] as $d) {
        assertTrue($fs->directoryExists($d), "empty dir relocated: {$d}");
    }
    assertTrue(!$fs->directoryExists('tree'), 'source tree gone');
});

test('rename keeps the folder index consistent with the disk (no phantoms)', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'empty_dir');
    up($fm, 'tree/docs', 'a.txt', tmpTxt('a'));
    $fm->mkdir('local', 'tree/empty');

    $fm->rename('local', 'empty_dir', 'empty_renamed');
    $fm->rename('local', 'tree', 'tree2');

    assertIndexMatchesDisk(
        $fs,
        ['empty_renamed', 'tree2', 'tree2/docs', 'tree2/empty'],
        ['empty_dir', 'tree', 'tree/docs', 'tree/empty']
    );
});

test('rename relocates image variants with the folder', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'album', 'a.png', imgFile(300, 200));
    up($fm, 'album/sub', 'b.png', imgFile(300, 200));
    assertTrue($fs->fileExists('album/_variants/a.png_thumb.webp'), 'variant created (top)');
    assertTrue($fs->fileExists('album/sub/_variants/b.png_thumb.webp'), 'variant created (nested)');

    $fm->rename('local', 'album', 'album2');

    assertTrue($fs->fileExists('album2/_variants/a.png_thumb.webp'), 'top variant relocated');
    assertTrue($fs->fileExists('album2/sub/_variants/b.png_thumb.webp'), 'nested variant relocated');
    assertTrue(!$fs->directoryExists('album/_variants'), 'old variants gone');
});

test('rename moves child metadata to the new prefix', function () {
    [$fm, , $meta] = makeFM();
    up($fm, 'docs', 'invoice.txt', tmpTxt('x'));
    $meta->save('local', 'docs/invoice.txt', ['title' => 'Invoice 2024']);

    $fm->rename('local', 'docs', 'bills');

    assertEqual(null, $meta->get('local', 'docs/invoice.txt'), 'old metadata gone');
    assertTrue($meta->get('local', 'bills/invoice.txt') !== null, 'metadata followed the folder');
});

test('rename onto an existing name → 409 name_exists, source untouched', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'a');
    $fm->mkdir('local', 'b');
    try { $fm->rename('local', 'a', 'b'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('name_exists', $e->getErrorCode(), 'expected name_exists'); }
    assertTrue($fs->directoryExists('a'), 'source preserved');
});

// ── move ────────────────────────────────────────────────────────────────────

test('move an EMPTY folder keeps it alive at the destination', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'box');
    $fm->mkdir('local', 'empty_dir');

    $fm->move('local', 'empty_dir', 'box/empty_dir');

    assertTrue($fs->directoryExists('box/empty_dir'), 'destination exists');
    assertTrue(!$fs->directoryExists('empty_dir'), 'source gone');
});

test('move a folder whose only content is a subfolder → child survives', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'parent');
    $fm->mkdir('local', 'parent/child');
    $fm->mkdir('local', 'box');

    $fm->move('local', 'parent', 'box/parent');

    assertTrue($fs->directoryExists('box/parent/child'), 'child survived');
    assertTrue(!$fs->directoryExists('parent'), 'source gone');
});

test('move a deep mixed tree relocates files and empty dirs', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'tree/docs', 'a.txt', tmpTxt('a'));
    $fm->mkdir('local', 'tree/empty/nested_empty');
    $fm->mkdir('local', 'box');

    $fm->move('local', 'tree', 'box/tree');

    assertTrue($fs->fileExists('box/tree/docs/a.txt'), 'file relocated');
    assertTrue($fs->directoryExists('box/tree/empty/nested_empty'), 'empty dir relocated');
    assertTrue(!$fs->directoryExists('tree'), 'source gone');
});

test('move keeps the folder index consistent with the disk', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'tree/docs', 'a.txt', tmpTxt('a'));
    $fm->mkdir('local', 'tree/empty');
    $fm->mkdir('local', 'box');

    $fm->move('local', 'tree', 'box/tree');

    assertIndexMatchesDisk(
        $fs,
        ['box', 'box/tree', 'box/tree/docs', 'box/tree/empty'],
        ['tree', 'tree/docs', 'tree/empty']
    );
});

test('move a folder into its own subtree → 400, nothing destroyed', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'tree/docs', 'a.txt', tmpTxt('a'));

    try { $fm->move('local', 'tree', 'tree/inner'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('move_into_self', $e->getErrorCode(), 'expected move_into_self'); }

    assertTrue($fs->fileExists('tree/docs/a.txt'), 'source subtree intact');
});

// ── object-store branch ─────────────────────────────────────────────────────
// S3/R2 have no real directories, so `moveDirectoryTree` walks the tree and
// recreates directory markers instead of issuing one adapter move(). Live S3
// coverage lives in tests/e2e/test-s3-live.php (needs credentials); here the
// walk is driven directly against a local-backed Filesystem so the branch is
// exercised in CI. `$disk` only selects the strategy, `$fs` does the work.
function walkMove(string $root, string $from, string $to): void
{
    $dm = new DiskManager([
        'local'    => ['driver' => 'local', 'root' => $root, 'url' => '/storage'],
        'objstore' => ['driver' => 's3'],   // no client needed: $fs is passed in
    ]);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));

    $m = new \ReflectionMethod(FileManager::class, 'moveDirectoryTree');
    $m->setAccessible(true);
    $m->invoke($fm, 'objstore', $dm->disk('local'), $from, $to);
}

test('object-store walk relocates an empty folder', function () {
    [$fm, $fs, , $root] = makeFM();
    $fm->mkdir('local', 'empty_dir');

    walkMove($root, 'empty_dir', 'empty_renamed');

    assertTrue($fs->directoryExists('empty_renamed'), 'destination created');
    assertTrue(!$fs->directoryExists('empty_dir'), 'source gone');
});

test('object-store walk relocates files plus empty dirs at every depth', function () {
    [$fm, $fs, , $root] = makeFM();
    up($fm, 'tree/docs', 'a.txt', tmpTxt('a'));
    up($fm, 'tree', 'top.txt', tmpTxt('top'));
    $fm->mkdir('local', 'tree/empty/nested_empty');

    walkMove($root, 'tree', 'tree2');

    assertTrue($fs->fileExists('tree2/top.txt'), 'top file relocated');
    assertTrue($fs->fileExists('tree2/docs/a.txt'), 'nested file relocated');
    assertTrue($fs->directoryExists('tree2/empty/nested_empty'), 'empty dir relocated');
    assertTrue(!$fs->directoryExists('tree'), 'source gone');
});

// ── copy ────────────────────────────────────────────────────────────────────

test('copy of a folder fails with a clean API error, not a raw adapter throw', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'src', 'a.txt', tmpTxt('a'));
    try {
        $fm->copy('local', 'src', 'dst');
        throw new \RuntimeException('should throw');
    } catch (ApiException $e) {
        assertEqual('copy_dir_unsupported', $e->getErrorCode(), 'clean error code');
        assertEqual(400, $e->getCode(), 'clean status');
    }
    assertTrue($fs->fileExists('src/a.txt'), 'source untouched');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

<?php

/**
 * Pre-existing files — files placed DIRECTLY on storage (not via FluxFiles upload),
 * so they have no sidecar, no index entry, no hash, no variants. Covers TEST-PLAN
 * section 2bis: every operation in State A (un-indexed) and State B (after
 * ExistingFileIndexer), plus indexer idempotency.
 *
 * Usage:
 *   php tests/integration/test-existing-files.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . "/../..", __DIR__ . "/../../../.."] as $envDir) {
    if (is_file($envDir . "/.env")) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\Claims;
use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\ExistingFileIndexer;

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

/** Write a file DIRECTLY to the disk root (simulating a pre-existing file). */
function place(string $root, string $rel, string $content): void
{
    $full = $root . '/' . $rel;
    @mkdir(dirname($full), 0777, true);
    file_put_contents($full, $content);
}
/** A real PNG of given size, written directly to the disk root. */
function placeImage(string $root, string $rel, int $w, int $h): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 20, 110, 190));
    $full = $root . '/' . $rel;
    @mkdir(dirname($full), 0777, true);
    imagepng($im, $full);
    imagedestroy($im);
}
function fileArray(string $tmpPath, string $name): array
{
    return ['name' => $name, 'size' => filesize($tmpPath), 'tmp_name' => $tmpPath];
}

/** Build env over a fresh temp local disk; pre-existing files are placed by the caller. */
function makeEnv(bool $ownerOnly = false, string $userId = 'tester'): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-pre-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims($userId, ['read', 'write', 'delete'], ['local'], '', 50, null, 0, $ownerOnly);
    $fm = new FileManager($dm, $claims, $meta);
    $indexer = new ExistingFileIndexer($dm, $meta);
    return [$fm, $dm->disk('local'), $meta, $indexer, $root];
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Pre-existing Files Test Suite (section 2bis)\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

// ════════════════════════════════════════════════════════════════════
echo "{$yellow}► State A — un-indexed pre-existing files{$reset}\n";
// ════════════════════════════════════════════════════════════════════

test('list shows pre-existing files + hides system entries', function () {
    [$fm, , , , $root] = makeEnv();
    place($root, 'a.txt', 'hello');
    placeImage($root, 'photos/b.png', 80, 60);
    place($root, '_fluxfiles/index.json', '{}');          // system — must stay hidden
    place($root, 'photos/_variants/b_thumb.webp', 'x');   // system — hidden
    place($root, 'a.txt.meta.json', '{}');                // sidecar — hidden

    $rootList = $fm->list('local', '');
    $names = array_map(fn($i) => $i['name'], $rootList);
    assertTrue(in_array('a.txt', $names, true), 'a.txt listed');
    assertTrue(in_array('photos', $names, true), 'photos dir listed');
    assertTrue(!in_array('_fluxfiles', $names, true), '_fluxfiles hidden');
    assertTrue(!in_array('a.txt.meta.json', $names, true), 'sidecar hidden');

    $photos = $fm->list('local', 'photos');
    $pnames = array_map(fn($i) => $i['name'], $photos);
    assertTrue(in_array('b.png', $pnames, true), 'b.png listed');
    assertTrue(!in_array('_variants', $pnames, true), '_variants hidden');
});

test('meta on pre-existing image → size/mime, no variants on disk', function () {
    [$fm, $fs, , , $root] = makeEnv();
    placeImage($root, 'pic.png', 200, 150);
    $meta = $fm->fileMeta('local', 'pic.png');
    assertTrue(($meta['size'] ?? 0) > 0, 'size present');
    assertTrue(strpos((string) ($meta['mime'] ?? ''), 'image/') === 0, 'image mime');
    assertTrue(!$fs->fileExists('_variants/pic_thumb.webp'), 'no variants for un-indexed image');
});

test('metadata GET on pre-existing → null (graceful, no error)', function () {
    [, , $meta, , $root] = makeEnv();
    place($root, 'doc.txt', 'x');
    assertEqual(null, $meta->get('local', 'doc.txt'), 'no metadata yet');
});

test('metadata PUT creates a sidecar for a pre-existing file', function () {
    [, , $meta, , $root] = makeEnv();
    place($root, 'doc.txt', 'x');
    $meta->save('local', 'doc.txt', ['title' => 'My Doc', 'tags' => 'a,b']);
    $got = $meta->get('local', 'doc.txt');
    assertTrue(is_array($got) && ($got['title'] ?? '') === 'My Doc', 'metadata round-trips');
});

test('search does NOT find pre-existing file before indexing', function () {
    [, , $meta, , $root] = makeEnv();
    place($root, 'invoice-2024.txt', 'x');
    $hits = $meta->search('local', 'invoice');
    assertEqual(0, count($hits), 'not searchable until indexed');
});

test('dedup does NOT trigger for un-indexed pre-existing content', function () {
    [$fm, , , , $root] = makeEnv();
    place($root, 'pre/orig.txt', 'DUPLICATE-ME');   // pre-existing, no hash index
    $tmp = sys_get_temp_dir() . '/fx-dup-' . uniqid() . '.txt';
    file_put_contents($tmp, 'DUPLICATE-ME');          // identical bytes
    $r = $fm->upload('local', 'uploads', fileArray($tmp, 'copy.txt'));
    assertTrue(empty($r['duplicate']), 'should not be flagged duplicate (no hash for pre-existing)');
});

test('rename / move / copy / delete a pre-existing file (no variants/meta) work', function () {
    [$fm, $fs, , , $root] = makeEnv();
    place($root, 'old.txt', 'data');
    $fm->rename('local', 'old.txt', 'new.txt');
    assertTrue($fs->fileExists('new.txt') && !$fs->fileExists('old.txt'), 'renamed');

    $fm->move('local', 'new.txt', 'sub/moved.txt');
    assertTrue($fs->fileExists('sub/moved.txt'), 'moved');

    $fm->copy('local', 'sub/moved.txt', 'sub/copied.txt');
    assertTrue($fs->fileExists('sub/copied.txt') && $fs->fileExists('sub/moved.txt'), 'copied');

    $fm->delete('local', 'sub/copied.txt');
    assertTrue(!$fs->fileExists('sub/copied.txt'), 'deleted without error');
});

test('crop a pre-existing image works', function () {
    [$fm, $fs, , , $root] = makeEnv();
    placeImage($root, 'crop-me.png', 400, 300);
    $r = $fm->cropImage('local', 'crop-me.png', 50, 40, 200, 150, 'crop-me-cropped.png');
    assertTrue(is_array($r), 'crop returned result');
    assertTrue($fs->fileExists('crop-me-cropped.png'), 'cropped file written');
});

test('owner_only allows operating on legacy pre-existing file (no uploaded_by)', function () {
    [$fm, $fs, , , $root] = makeEnv(true, 'someone-else');
    place($root, 'legacy.txt', 'x');
    $fm->delete('local', 'legacy.txt');   // assertOwner must allow (no owner recorded)
    assertTrue(!$fs->fileExists('legacy.txt'), 'legacy file deletable under owner_only');
});

// ════════════════════════════════════════════════════════════════════
echo "\n{$yellow}► State B — after ExistingFileIndexer.index(){$reset}\n";
// ════════════════════════════════════════════════════════════════════

test('default index counts files + folders, skips system paths', function () {
    [, , , $indexer, $root] = makeEnv();
    place($root, 'a.txt', 'x');
    placeImage($root, 'photos/b.png', 60, 60);
    place($root, '_fluxfiles/index.json', '{}');
    place($root, 'photos/_variants/b_thumb.webp', 'x');
    $stats = $indexer->index(['disk' => 'local']);
    assertEqual(2, $stats['files_indexed'], 'a.txt + b.png indexed, system skipped');
    assertTrue($stats['folders_indexed'] >= 1, 'photos folder indexed');
    assertEqual(0, $stats['errors'], 'no errors');
});

test('index hash + persist → dedup then works for pre-existing content', function () {
    [$fm, , , $indexer, $root] = makeEnv();
    place($root, 'pre/orig.txt', 'INDEX-DUP');
    $indexer->index(['disk' => 'local', 'hash' => true, 'persist_metadata' => true]);
    $tmp = sys_get_temp_dir() . '/fx-' . uniqid() . '.txt';
    file_put_contents($tmp, 'INDEX-DUP');
    $r = $fm->upload('local', 'x', fileArray($tmp, 'dup.txt'));
    assertEqual(true, $r['duplicate'] ?? false, 'dedup detects pre-existing after hash index');
});

test('index variants → generates _variants for pre-existing image', function () {
    [, $fs, , $indexer, $root] = makeEnv();
    placeImage($root, 'gallery/big.png', 1000, 700);
    $stats = $indexer->index(['disk' => 'local', 'variants' => true]);
    assertTrue($stats['variants'] > 0, 'variants generated');
    assertTrue($fs->fileExists('gallery/_variants/big_thumb.webp'), 'thumb variant on disk');
});


test("variants can be generated after metadata already exists", function () {
    [, $fs, , $indexer, $root] = makeEnv();
    placeImage($root, "gallery/already.png", 1000, 700);
    $first = $indexer->index(["disk" => "local", "persist_metadata" => true]);
    assertEqual(1, $first["files_indexed"], "first run indexes metadata");
    $second = $indexer->index(["disk" => "local", "variants" => true]);
    assertEqual(0, $second["files_indexed"], "metadata was skipped");
    assertEqual(1, $second["skipped"], "existing metadata counted as skipped");
    assertTrue($second["variants"] > 0, "variants generated for skipped file");
    assertTrue($fs->fileExists("gallery/_variants/already_thumb.webp"), "thumb variant on disk");
});

test('index owner → owner_only then enforced for other users', function () {
    [, , $meta, $indexer, $root] = makeEnv();
    placeImage($root, 'owned.png', 80, 80);
    $indexer->index(['disk' => 'local', 'owner' => 'owner-a']);
    // a different user with owner_only should be blocked from deleting
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $claims = new Claims('intruder', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, true);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    try {
        $fm->delete('local', 'owned.png');
        throw new \RuntimeException('should have thrown owner_only');
    } catch (ApiException $e) {
        assertEqual('owner_only', $e->getErrorCode(), 'expected owner_only');
    }
});

test('re-index is idempotent (overwrite=false skips already-indexed)', function () {
    [, , , $indexer, $root] = makeEnv();
    place($root, 'a.txt', 'x');
    place($root, 'b.txt', 'y');
    $first = $indexer->index(['disk' => 'local', 'persist_metadata' => true]);
    assertEqual(2, $first['files_indexed'], 'first run indexes 2');
    $second = $indexer->index(['disk' => 'local', 'persist_metadata' => true]);
    assertEqual(0, $second['files_indexed'], 're-run indexes 0 new');
    assertEqual(2, $second['skipped'], 're-run skips 2');
});

test('dry_run counts but writes nothing', function () {
    [, , $meta, $indexer, $root] = makeEnv();
    place($root, 'a.txt', 'x');
    $stats = $indexer->index(['disk' => 'local', 'persist_metadata' => true, 'dry_run' => true]);
    assertEqual(true, $stats['dry_run'], 'dry_run flag set');
    assertEqual(1, $stats['files_indexed'], 'counted');
    assertEqual(null, $meta->get('local', 'a.txt'), 'nothing persisted');
});

test('index path scopes to a subtree only', function () {
    [, , , $indexer, $root] = makeEnv();
    place($root, 'top.txt', 'x');
    place($root, 'sub/inner.txt', 'y');
    $stats = $indexer->index(['disk' => 'local', 'path' => 'sub', 'persist_metadata' => true]);
    assertEqual(1, $stats['files_indexed'], 'only sub/ indexed');
});

test('after indexing, search finds the file', function () {
    [, , $meta, $indexer, $root] = makeEnv();
    place($root, 'invoice-2024.txt', 'x');
    $indexer->index(['disk' => 'local', 'persist_metadata' => true]);
    $hits = $meta->search('local', 'invoice');
    assertTrue(count($hits) >= 1, 'searchable after index');
});

// ════════════════════════════════════════════════════════════════════
echo "\n{$yellow}► State C — large pre-existing tree + pagination{$reset}\n";
// ════════════════════════════════════════════════════════════════════

test('list limit>0 returns items + next_cursor + total', function () {
    [$fm, , , , $root] = makeEnv();
    for ($i = 0; $i < 25; $i++) {
        place($root, 'big/f' . sprintf('%02d', $i) . '.txt', "x{$i}");
    }
    $page = $fm->list('local', 'big', 10);
    assertTrue(isset($page['items'], $page['next_cursor'], $page['total']), 'paged shape');
    assertEqual(25, $page['total'], 'total counts all');
    assertEqual(10, count($page['items']), 'first page has limit items');
    assertTrue($page['next_cursor'] !== null, 'has next_cursor');
});

test('cursor pagination walks the whole tree with no gaps or dupes', function () {
    [$fm, , , , $root] = makeEnv();
    $expected = [];
    for ($i = 0; $i < 25; $i++) {
        $name = 'f' . sprintf('%02d', $i) . '.txt';
        place($root, 'big/' . $name, "x{$i}");
        $expected[] = 'big/' . $name;
    }
    $seen = [];
    $cursor = '';
    $pages = 0;
    do {
        $page = $fm->list('local', 'big', 10, $cursor);
        foreach ($page['items'] as $it) {
            assertTrue(!in_array($it['key'], $seen, true), 'no duplicate key across pages: ' . $it['key']);
            $seen[] = $it['key'];
        }
        $cursor = $page['next_cursor'] ?? null;
        $pages++;
        assertTrue($pages <= 10, 'pagination terminates');
    } while ($cursor !== null);
    sort($seen); sort($expected);
    assertEqual($expected, $seen, 'every file seen exactly once');
    assertEqual(3, $pages, '25 items / 10 per page = 3 pages');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

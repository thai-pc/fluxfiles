<?php

/**
 * Recursive folder delete — `FileManager::delete()` on a directory must remove the
 * whole subtree from disk plus image variants, child metadata, and folder-index
 * entries. Covers TEST-PLAN section 10 (delete folder đệ quy).
 *
 * Usage:
 *   php tests/integration/test-delete-folder.php
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

function imgFile(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 40, 120, 200));
    $p = sys_get_temp_dir() . '/fxdf-' . uniqid() . '.png';
    imagepng($im, $p); imagedestroy($im);
    return $p;
}
function up(FileManager $fm, string $dir, string $name, string $tmp): void
{
    $fm->upload('local', $dir, ['name' => $name, 'size' => filesize($tmp), 'tmp_name' => $tmp], true);
}
function place(string $root, string $rel, string $c): void
{
    $f = $root . '/' . $rel; @mkdir(dirname($f), 0777, true); file_put_contents($f, $c);
}
function makeFM(bool $ownerOnly = false, string $user = 'u', array $perms = ['read', 'write', 'delete']): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-df-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims($user, $perms, ['local'], '', 50, null, 0, $ownerOnly);
    return [new FileManager($dm, $claims, $meta), $dm->disk('local'), $meta, $root];
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Recursive Folder Delete Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

test('delete folder removes every child file + nested subdirs from disk', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'folder', 'a.png', imgFile(300, 200));
    up($fm, 'folder', 'note.txt', tmpTxt('hi'));
    up($fm, 'folder/sub', 'b.png', imgFile(300, 200));
    up($fm, 'folder/sub/deep', 'c.txt', tmpTxt('deep'));

    $fm->delete('local', 'folder');

    foreach (['folder/a.png', 'folder/note.txt', 'folder/sub/b.png', 'folder/sub/deep/c.txt'] as $k) {
        assertTrue(!$fs->fileExists($k), "gone: {$k}");
    }
    assertTrue(!$fs->directoryExists('folder'), 'folder removed');
});

test('image variants at every depth are removed', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'folder', 'a.png', imgFile(300, 200));        // → folder/_variants/a_thumb.webp
    up($fm, 'folder/sub', 'b.png', imgFile(300, 200));    // → folder/sub/_variants/b_thumb.webp
    assertTrue($fs->fileExists('folder/_variants/a_thumb.webp'), 'variant created (top)');
    assertTrue($fs->fileExists('folder/sub/_variants/b_thumb.webp'), 'variant created (nested)');

    $fm->delete('local', 'folder');
    assertTrue(!$fs->directoryExists('folder/_variants'), 'top variants gone');
    assertTrue(!$fs->directoryExists('folder/sub/_variants'), 'nested variants gone');
});

test('child metadata + search index are cleared', function () {
    [$fm, , $meta] = makeFM();
    up($fm, 'folder', 'invoice.png', imgFile(300, 200));
    up($fm, 'folder/sub', 'report.txt', tmpTxt('x'));
    $meta->save('local', 'folder/invoice.png', ['title' => 'Invoice 2024']);
    assertTrue($meta->get('local', 'folder/invoice.png') !== null, 'metadata present before');

    $fm->delete('local', 'folder');
    assertEqual(null, $meta->get('local', 'folder/invoice.png'), 'metadata gone');
    assertEqual(0, count($meta->search('local', 'invoice')), 'not searchable after delete');
});

test('folder index entries removed (searchFolders no longer finds it)', function () {
    [$fm, , $meta] = makeFM();
    up($fm, 'reports/q1', 'a.txt', tmpTxt('x'));
    assertTrue(count($meta->searchFolders('local', 'reports')) >= 1, 'folder tracked before');

    $fm->delete('local', 'reports');
    $hits = $meta->searchFolders('local', 'reports');
    foreach ($hits as $h) {
        assertTrue(strpos(($h['dir_key'] ?? $h['key'] ?? ''), 'reports') !== 0, 'reports/* not in folder index');
    }
});

test('delete returns deleted:true and parent no longer lists folder', function () {
    [$fm] = makeFM();
    up($fm, 'gone', 'a.txt', tmpTxt('x'));
    $r = $fm->delete('local', 'gone');
    assertEqual(true, $r['deleted'] ?? false, 'deleted flag');
    $names = array_map(fn($i) => $i['name'], $fm->list('local', ''));
    assertTrue(!in_array('gone', $names, true), 'folder not listed');
});

test('delete an empty folder works', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'empty');
    assertTrue($fs->directoryExists('empty'), 'created');
    $fm->delete('local', 'empty');
    assertTrue(!$fs->directoryExists('empty'), 'empty folder removed');
});

test('deleting a subfolder leaves siblings intact', function () {
    [$fm, $fs] = makeFM();
    up($fm, 'top', 'keep.txt', tmpTxt('keep'));
    up($fm, 'top/sub', 'x.txt', tmpTxt('x'));
    $fm->delete('local', 'top/sub');
    assertTrue(!$fs->directoryExists('top/sub'), 'sub gone');
    assertTrue($fs->fileExists('top/keep.txt'), 'sibling kept');
});

test('folder with mixed pre-existing + uploaded files → all removed', function () {
    [$fm, $fs, , $root] = makeFM();
    up($fm, 'mix', 'uploaded.png', imgFile(300, 200));
    place($root, 'mix/preexisting.txt', 'placed directly');   // no metadata
    $fm->delete('local', 'mix');
    assertTrue(!$fs->fileExists('mix/uploaded.png') && !$fs->fileExists('mix/preexisting.txt'), 'both gone');
});

test('delete non-existent folder → 404', function () {
    [$fm] = makeFM();
    try { $fm->delete('local', 'nope'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('not_found', $e->getErrorCode(), 'expected not_found'); }
});

test('delete without delete permission → 403', function () {
    [$fm] = makeFM(false, 'u', ['read', 'write']);  // no 'delete'
    $fm->mkdir('local', 'x');
    try { $fm->delete('local', 'x'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('permission_denied', $e->getErrorCode(), 'expected permission_denied'); }
});

test('cannot delete a system folder (_fluxfiles)', function () {
    [$fm] = makeFM();
    try { $fm->delete('local', '_fluxfiles'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertTrue($e->getErrorCode() !== null, 'blocked: ' . $e->getErrorCode()); }
});

// ── owner_only over a whole folder (assertOwnsTree) ──
/** Two FileManagers (different users) over one shared disk/root. */
function sharedDisk(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-df-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $mk = fn(string $user, bool $ownerOnly) => new FileManager(
        $dm, new Claims($user, ['read', 'write', 'delete'], ['local'], '', 50, null, 0, $ownerOnly), $meta
    );
    return [$dm->disk('local'), $mk];
}

test('owner_only BLOCKS deleting a folder containing another user\'s files', function () {
    [$fs, $mk] = sharedDisk();
    up($mk('alice', false), 'shared', 'a.txt', tmpTxt('x'));   // alice owns it
    $bob = $mk('bob', true);                                    // bob is owner_only
    try { $bob->delete('local', 'shared'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertEqual('owner_only', $e->getErrorCode(), 'blocked'); }
    assertTrue($fs->fileExists('shared/a.txt'), "alice's file preserved");
});

test('owner_only ALLOWS deleting a folder of only own + legacy files', function () {
    [$fs, $mk] = sharedDisk();
    $owner = $mk('owner', true);
    up($owner, 'mine', 'a.png', imgFile(300, 200));            // owner uploads → uploaded_by=owner
    // a legacy file with no owner is fine too
    up($mk('owner', false), 'mine', 'b.txt', tmpTxt('y'));
    $owner->delete('local', 'mine');
    assertTrue(!$fs->directoryExists('mine'), 'own folder deletable');
});

test('owner_only BLOCKS rename/move of a folder with others\' files', function () {
    [, $mk] = sharedDisk();
    up($mk('alice', false), 'x', 'a.txt', tmpTxt('x'));
    $bob = $mk('bob', true);
    try { $bob->rename('local', 'x', 'y'); throw new \RuntimeException('rename should throw'); }
    catch (ApiException $e) { assertEqual('owner_only', $e->getErrorCode(), 'rename blocked'); }
    try { $bob->move('local', 'x', 'z'); throw new \RuntimeException('move should throw'); }
    catch (ApiException $e) { assertEqual('owner_only', $e->getErrorCode(), 'move blocked'); }
});

test('list() exposes a modified timestamp on folders (so the UI can sort by date)', function () {
    [$fm] = makeFM();
    $fm->mkdir('local', 'albums');
    $dir = null;
    foreach ($fm->list('local', '') as $it) {
        if ($it['name'] === 'albums') { $dir = $it; break; }
    }
    assertTrue($dir !== null && $dir['type'] === 'dir', 'folder listed');
    assertTrue(isset($dir['modified']) && is_int($dir['modified']) && $dir['modified'] > 0, 'folder has a numeric modified');
});

test('files and folders carry a stable created timestamp in list()', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'Albums');
    $tmp = tmpTxt('hi');
    $fm->upload('local', '', ['name' => 'pic.txt', 'size' => filesize($tmp), 'tmp_name' => $tmp], true);

    $byName = [];
    foreach ($fm->list('local', '') as $it) { $byName[$it['name']] = $it; }
    assertTrue(is_int($byName['Albums']['created'] ?? null) && $byName['Albums']['created'] > 0, 'folder has created');
    assertTrue(is_int($byName['pic.txt']['created'] ?? null) && $byName['pic.txt']['created'] > 0, 'file has created');

    // dirs.json is the new map shape {dirKey: created}
    $dirs = json_decode($fs->read('_fluxfiles/dirs.json'), true);
    assertTrue(isset($dirs['Albums']) && is_int($dirs['Albums']), 'dirs.json stores folder created');
});

test('legacy dirs.json (list shape) still loads + folder created stays stable on re-track', function () {
    [$fm, $fs, $meta] = makeFM();
    // Simulate a pre-0.2.10 index: a plain list of keys.
    $fs->write('_fluxfiles/dirs.json', json_encode(['Legacy']));
    $meta->trackDir('local', 'Legacy');          // re-track must not crash / must keep it
    $dirs = json_decode($fs->read('_fluxfiles/dirs.json'), true);
    assertTrue(array_key_exists('Legacy', $dirs), 'legacy folder preserved after migration');
    assertTrue(count($meta->searchFolders('local', 'leg')) >= 1, 'legacy folder still searchable');
});

test('search index carries size + modified (so results can sort by size/date)', function () {
    [$fm, , $meta] = makeFM();
    $tmp = tmpTxt(str_repeat('x', 777));
    $fm->upload('local', '', ['name' => 'quarterly-report.txt', 'size' => filesize($tmp), 'tmp_name' => $tmp], true);
    $rows = $meta->search('local', 'quarterly');
    assertTrue(count($rows) >= 1, 'found by search');
    assertEqual(777, (int) ($rows[0]['size'] ?? 0), 'row carries size');
    assertTrue(is_int($rows[0]['modified'] ?? null) && $rows[0]['modified'] > 0, 'row carries modified');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

function tmpTxt(string $c): string
{
    $p = sys_get_temp_dir() . '/fxdf-' . uniqid() . '.txt';
    file_put_contents($p, $c);
    return $p;
}

exit($failed > 0 ? 1 : 0);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** Fresh local disk + FileManager. Returns [fm, fs, root, meta, dm]. */
function makeFM(string $prefix = '', string $userId = 'tester', bool $ownerOnly = false, ?string $root = null): array
{
    $root = $root ?? sys_get_temp_dir() . '/fluxfiles-trash-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims($userId, ['read', 'write', 'delete'], ['local'], $prefix, 50, null, 0, $ownerOnly);
    $fm = new FileManager($dm, $claims, $meta);
    return [$fm, $dm->disk('local'), $root, $meta, $dm];
}

function upload(FileManager $fm, string $path, string $name, string $content = 'hello', string $disk = 'local'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxt');
    file_put_contents($tmp, $content);
    return $fm->upload($disk, $path, ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'type' => 'text/plain', 'error' => 0]);
}

/**
 * FileManager whose only disk reports the `s3` driver but is backed by local
 * storage. `config()['driver']` picks the relocation strategy while the
 * Filesystem does the work, so the object-store walk (no real directories) is
 * exercised in CI with no network — same trick as test-folder-rename.php.
 * Returns [fm, fs, root, meta, dm].
 */
function makeObjStoreFM(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-trash-obj-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['objstore' => ['driver' => 's3', 'bucket' => 'fake']]);
    $fs = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($root));
    $p = new \ReflectionProperty(DiskManager::class, 'disks');
    $p->setAccessible(true);
    $p->setValue($dm, ['objstore' => $fs]);

    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('tester', ['read', 'write', 'delete'], ['objstore'], '', 50, null, 0, false);
    return [new FileManager($dm, $claims, $meta), $fs, $root, $meta, $dm];
}

echo "\n{$cyan}══ FluxFiles Trash / Restore ══{$reset}\n\n";

test('trash a file → removed from active, appears in listTrash, payload in trash dir', function () {
    [$fm, $fs] = makeFM();
    upload($fm, '', 'a.txt');
    $r = $fm->trash('local', 'a.txt');
    assertTrue($r['trashed'] === true && !empty($r['trash_id']), 'trashed + id');
    assertTrue(!$fs->fileExists('a.txt'), 'gone from original key');
    assertTrue($fs->fileExists('_fluxfiles/trash/' . $r['trash_id'] . '/a.txt'), 'payload in trash dir');
    $list = $fm->listTrash('local');
    assertEqual(1, count($list), 'one trash entry');
    assertEqual('a.txt', $list[0]['name'], 'name');
});

test('restore → file back at original key, removed from trash', function () {
    [$fm, $fs] = makeFM();
    upload($fm, '', 'b.txt', 'content-b');
    $id = $fm->trash('local', 'b.txt')['trash_id'];
    $r = $fm->restore('local', $id);
    assertEqual('b.txt', $r['key'], 'restored key');
    assertTrue($fs->fileExists('b.txt'), 'file back');
    assertEqual('content-b', $fs->read('b.txt'), 'content intact');
    assertEqual(0, count($fm->listTrash('local')), 'trash empty after restore');
});

test('restore re-creates metadata snapshot', function () {
    [$fm, $fs, $root, $meta] = makeFM();
    upload($fm, '', 'm.txt');
    $meta->save('local', 'm.txt', ['title' => 'My Title', 'tags' => 'x,y']);
    $id = $fm->trash('local', 'm.txt')['trash_id'];
    assertTrue($meta->get('local', 'm.txt') === null, 'meta removed while trashed');
    $fm->restore('local', $id);
    $got = $meta->get('local', 'm.txt');
    assertTrue(is_array($got) && ($got['title'] ?? '') === 'My Title', 'metadata restored');
});

test('restore onto an occupied key → 409 name_exists', function () {
    [$fm, $fs] = makeFM();
    upload($fm, '', 'c.txt', 'old');
    $id = $fm->trash('local', 'c.txt')['trash_id'];
    upload($fm, '', 'c.txt', 'new');  // a new file now occupies the key
    try {
        $fm->restore('local', $id);
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('name_exists', $e->getErrorCode(), 'expected name_exists');
    }
    assertEqual('new', $fs->read('c.txt'), 'occupying file untouched');
    assertEqual(1, count($fm->listTrash('local')), 'item still in trash after failed restore');
});

test('purge → trash item permanently gone', function () {
    [$fm, $fs] = makeFM();
    upload($fm, '', 'p.txt');
    $id = $fm->trash('local', 'p.txt')['trash_id'];
    $fm->purgeTrash('local', $id);
    assertEqual(0, count($fm->listTrash('local')), 'trash empty after purge');
    assertTrue(!$fs->fileExists('_fluxfiles/trash/' . $id . '/p.txt'), 'payload deleted');
});

test('emptyTrash → all visible items purged', function () {
    [$fm] = makeFM();
    upload($fm, '', 'e1.txt'); $fm->trash('local', 'e1.txt');
    upload($fm, '', 'e2.txt'); $fm->trash('local', 'e2.txt');
    assertEqual(2, count($fm->listTrash('local')), 'two before');
    $r = $fm->emptyTrash('local');
    assertEqual(2, $r['purged'], 'purged count');
    assertEqual(0, count($fm->listTrash('local')), 'empty after');
});

test('trash is scoped: a tenant cannot see another tenant\'s trash', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-trash-scope-' . uniqid();
    [$a] = makeFM('users/42', 'user-42', false, $root);
    [$b] = makeFM('users/99', 'user-99', false, $root);  // same disk, different prefix
    upload($a, '', 'secret.txt');                 // → users/42/secret.txt
    $a->trash('local', 'users/42/secret.txt');
    assertEqual(1, count($a->listTrash('local')), 'owner sees own trash');
    assertEqual(0, count($b->listTrash('local')), 'other tenant sees nothing');
});

test('trash a folder → whole subtree moves to trash and restore brings it back', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'docs');
    upload($fm, 'docs', 'one.txt', 'c1');
    upload($fm, 'docs', 'two.txt', 'c2');

    $r = $fm->trash('local', 'docs');
    assertTrue($r['trashed'] === true, 'trashed');
    assertTrue(!$fs->fileExists('docs/one.txt') && !$fs->fileExists('docs/two.txt'), 'subtree files gone');
    $list = $fm->listTrash('local');
    assertEqual(1, count($list), 'one trash entry (the folder)');
    assertTrue($list[0]['is_dir'] === true, 'entry marked is_dir');
    assertEqual('docs', $list[0]['name'], 'folder name');

    $fm->restore('local', $r['trash_id']);
    assertTrue($fs->fileExists('docs/one.txt') && $fs->fileExists('docs/two.txt'), 'subtree restored');
    assertEqual('c1', $fs->read('docs/one.txt'), 'content intact');
    assertEqual(0, count($fm->listTrash('local')), 'trash empty after restore');
});

test('trashed folder is searchable-clean: restore re-tracks the dir index', function () {
    [$fm, $fs, $root, $meta] = makeFM();
    $fm->mkdir('local', 'album');
    upload($fm, 'album', 'p.txt');
    $id = $fm->trash('local', 'album')['trash_id'];
    assertEqual(0, count($meta->searchFolders('local', 'album')), 'folder gone from dir index while trashed');
    $fm->restore('local', $id);
    assertTrue(count($meta->searchFolders('local', 'album')) >= 1, 'folder back in dir index after restore');
});

// ── directory shapes ────────────────────────────────────────────────────────
// The UI soft-deletes everything, so trash → restore must be lossless. A
// subdirectory that holds no files has nothing in `files[]` to imply it, so it
// only survives if the manifest records directories in their own right.

test('trash+restore a folder containing an EMPTY subdirectory keeps the subdirectory', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'box');
    upload($fm, 'box', 'doc.txt', 'd');
    $fm->mkdir('local', 'box/empty_sub');

    $id = $fm->trash('local', 'box')['trash_id'];
    assertTrue(!$fs->directoryExists('box'), 'source folder gone while trashed');
    $fm->restore('local', $id);

    assertTrue($fs->fileExists('box/doc.txt'), 'file restored');
    assertTrue($fs->directoryExists('box/empty_sub'), 'empty subdirectory restored');
});

test('trash+restore a folder whose ONLY content is a subdirectory', function () {
    [$fm, $fs] = makeFM();
    $fm->mkdir('local', 'parent/child');

    $id = $fm->trash('local', 'parent')['trash_id'];
    assertTrue(!$fs->directoryExists('parent'), 'source gone');
    assertEqual(1, count($fm->listTrash('local')), 'entry recorded');
    $fm->restore('local', $id);

    assertTrue($fs->directoryExists('parent'), 'parent restored');
    assertTrue($fs->directoryExists('parent/child'), 'child directory restored');
});

test('trash+restore a deep tree mixing empty dirs and files', function () {
    [$fm, $fs] = makeFM();
    upload($fm, 'tree', 'top.txt', 'top');
    upload($fm, 'tree/docs', 'a.txt', 'a');
    upload($fm, 'tree/docs/deep', 'b.txt', 'b');
    $fm->mkdir('local', 'tree/empty');
    $fm->mkdir('local', 'tree/docs/also_empty');
    $fm->mkdir('local', 'tree/empty/nested_empty');

    $id = $fm->trash('local', 'tree')['trash_id'];
    $fm->restore('local', $id);

    foreach (['tree/top.txt', 'tree/docs/a.txt', 'tree/docs/deep/b.txt'] as $k) {
        assertTrue($fs->fileExists($k), "file restored: {$k}");
    }
    foreach (['tree/empty', 'tree/docs/also_empty', 'tree/empty/nested_empty'] as $d) {
        assertTrue($fs->directoryExists($d), "empty dir restored: {$d}");
    }
    assertEqual('b', $fs->read('tree/docs/deep/b.txt'), 'content intact');
});

test('restore a directory to a NEW path preserves empty subdirectories', function () {
    [$fm, $fs] = makeFM();
    upload($fm, 'src', 'a.txt', 'a');
    $fm->mkdir('local', 'src/empty_sub');

    $id = $fm->trash('local', 'src')['trash_id'];
    $r = $fm->restore('local', $id, 'moved');

    assertEqual('moved', $r['key'], 'restored to the new key');
    assertTrue($fs->fileExists('moved/a.txt'), 'file at new path');
    assertTrue($fs->directoryExists('moved/empty_sub'), 'empty subdirectory at new path');
    assertTrue(!$fs->directoryExists('src'), 'original path not recreated');
});

test('restored empty subdirectories are back in the folder index', function () {
    [$fm, , , $meta] = makeFM();
    $fm->mkdir('local', 'album/empty_sub');
    upload($fm, 'album', 'p.txt');

    $id = $fm->trash('local', 'album')['trash_id'];
    assertEqual(0, count($meta->searchFolders('local', 'empty_sub')), 'gone from dir index while trashed');
    $fm->restore('local', $id);

    assertTrue(count($meta->searchFolders('local', 'empty_sub')) >= 1, 'empty subdir back in dir index');
});

test('trash keeps image variants of a folder subtree and restore brings them back', function () {
    [$fm, $fs] = makeFM();
    $png = imagecreatetruecolor(60, 40);
    $tmp = sys_get_temp_dir() . '/fxt-' . uniqid() . '.png';
    imagepng($png, $tmp); imagedestroy($png);
    $fm->upload('local', 'gallery', ['name' => 'a.png', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => 0], true);
    assertTrue($fs->fileExists('gallery/_variants/a.png_thumb.webp'), 'variant created');
    $fm->mkdir('local', 'gallery/empty_sub');

    $id = $fm->trash('local', 'gallery')['trash_id'];
    $fm->restore('local', $id);

    assertTrue($fs->fileExists('gallery/a.png'), 'image restored');
    assertTrue($fs->fileExists('gallery/_variants/a.png_thumb.webp'), 'variant restored');
    assertTrue($fs->directoryExists('gallery/empty_sub'), 'empty subdir restored');
});

test('an old-format manifest (no dirs[]) still restores its files', function () {
    [$fm, $fs] = makeFM();
    upload($fm, 'legacy', 'one.txt', 'c1');
    upload($fm, 'legacy/sub', 'two.txt', 'c2');
    $fm->mkdir('local', 'legacy/empty_sub');
    $id = $fm->trash('local', 'legacy')['trash_id'];

    // Rewrite the entry the way pre-dirs[] versions wrote it, and drop the
    // directory markers their payload never carried.
    $all = json_decode($fs->read('_fluxfiles/trash.json'), true);
    unset($all[$id]['dirs']);
    $fs->write('_fluxfiles/trash.json', json_encode($all));
    try { $fs->deleteDirectory('_fluxfiles/trash/' . $id . '/payload/empty_sub'); } catch (\Throwable $e) { /* nothing to drop */ }

    $fm->restore('local', $id);

    assertTrue($fs->fileExists('legacy/one.txt'), 'top file restored');
    assertTrue($fs->fileExists('legacy/sub/two.txt'), 'nested file restored');
    assertEqual('c2', $fs->read('legacy/sub/two.txt'), 'content intact');
    assertEqual(0, count($fm->listTrash('local')), 'entry removed after restore');
});

// ── object-store branch (no real directories) ───────────────────────────────

test('object-store: trash+restore keeps empty subdirectories', function () {
    [$fm, $fs] = makeObjStoreFM();
    $fs->write('box/doc.txt', 'd');   // written directly: object metadata needs a live client
    $fm->mkdir('objstore', 'box/empty_sub');
    $fm->mkdir('objstore', 'box/deep/nested');

    $id = $fm->trash('objstore', 'box')['trash_id'];
    assertTrue(!$fs->fileExists('box/doc.txt'), 'payload moved out');
    $fm->restore('objstore', $id);

    assertTrue($fs->fileExists('box/doc.txt'), 'file restored');
    assertTrue($fs->directoryExists('box/empty_sub'), 'empty subdirectory restored');
    assertTrue($fs->directoryExists('box/deep/nested'), 'nested empty subdirectory restored');
});

test('object-store: a folder whose only content is subfolders trashes with a non-empty manifest', function () {
    [$fm, $fs, , $meta] = makeObjStoreFM();
    $fm->mkdir('objstore', 'parent/child');

    $id = $fm->trash('objstore', 'parent')['trash_id'];
    $entry = $meta->getTrash('objstore', $id);
    assertTrue(!empty($entry['dirs']), 'manifest records the subdirectory');

    $fm->restore('objstore', $id);
    assertTrue($fs->directoryExists('parent/child'), 'child directory restored');
});

test('restore/purge reject a path-traversal trash id', function () {
    [$fm] = makeFM();
    foreach (['../../etc', 'a/b', '..', ''] as $bad) {
        try { $fm->restore('local', $bad); throw new \RuntimeException('restore should reject'); }
        catch (ApiException $e) { assertTrue(in_array($e->getErrorCode(), ['invalid_trash_id', 'not_found'], true), 'restore guard'); }
        try { $fm->purgeTrash('local', $bad); throw new \RuntimeException('purge should reject'); }
        catch (ApiException $e) { assertTrue(in_array($e->getErrorCode(), ['invalid_trash_id', 'not_found'], true), 'purge guard'); }
    }
});

test('unwritable index dir surfaces a clear storage_not_writable error (not a raw fopen warning)', function () {
    // Reproduce the Laravel field report: the disk root is writable but
    // `_fluxfiles/` cannot host the lock. Put a *file* where the lock dir should
    // be — deterministic on any OS/user (even root cannot mkdir over a file).
    $root = sys_get_temp_dir() . '/fluxfiles-lockfail-' . uniqid();
    @mkdir($root, 0777, true);
    file_put_contents($root . '/_fluxfiles', 'x');
    [$fm] = makeFM('', 'tester', false, $root);
    try {
        $fm->mkdir('local', 'whatever');
        throw new \RuntimeException('mkdir should fail when the index lock dir is unwritable');
    } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === 'storage_not_writable', 'clear storage_not_writable code');
        assertTrue($e->getHttpCode() === 500, 'reported as 500');
    } finally {
        @unlink($root . '/_fluxfiles');
        @rmdir($root);
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

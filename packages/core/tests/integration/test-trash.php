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

function upload(FileManager $fm, string $path, string $name, string $content = 'hello'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxt');
    file_put_contents($tmp, $content);
    return $fm->upload('local', $path, ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'type' => 'text/plain', 'error' => 0]);
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

test('trashing a folder is rejected (files-only in P0)', function () {
    [$fm] = makeFM();
    $fm->mkdir('local', 'folder1');
    try {
        $fm->trash('local', 'folder1');
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('trash_dir_unsupported', $e->getErrorCode(), 'expected trash_dir_unsupported');
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

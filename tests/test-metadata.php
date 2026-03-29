<?php

/**
 * Test script for StorageMetadataHandler with LOCAL disk operations.
 *
 * Usage:
 *   php tests/test-metadata.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

// ═══════════════════════════════════════════════════════════════
// Setup: create a temp local disk
// ═══════════════════════════════════════════════════════════════

$tmpRoot = sys_get_temp_dir() . '/ff_test_meta_' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0755, true);

$diskName = 'test-local';
$dm = new FluxFiles\DiskManager([
    $diskName => ['driver' => 'local', 'root' => $tmpRoot],
]);

$handler = new FluxFiles\StorageMetadataHandler($dm);

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║   StorageMetadataHandler (Local) Test Suite       ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// Create some dummy files so listContents works for children tests
$fs = $dm->disk($diskName);
$fs->write('photo.jpg', 'fake-image-data');
$fs->write('docs/readme.txt', 'hello');
$fs->write('docs/notes.txt', 'world');
$fs->write('docs/sub/deep.txt', 'deep');

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► save() + get() roundtrip{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('save() + get() roundtrip - title, alt_text, caption, tags', function () use ($handler, $diskName) {
    $data = [
        'title'    => 'Sunset Photo',
        'alt_text' => 'A beautiful sunset over the ocean',
        'caption'  => 'Taken at Malibu Beach',
        'tags'     => 'sunset,beach,ocean',
    ];
    $handler->save($diskName, 'photo.jpg', $data);
    $result = $handler->get($diskName, 'photo.jpg');

    assertEqual('Sunset Photo', $result['title']);
    assertEqual('A beautiful sunset over the ocean', $result['alt_text']);
    assertEqual('Taken at Malibu Beach', $result['caption']);
    assertEqual('sunset,beach,ocean', $result['tags']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► get() for non-existent key{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('get() returns null for non-existent key', function () use ($handler, $diskName) {
    $result = $handler->get($diskName, 'does-not-exist.png');
    assertEqual(null, $result);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► save() overwrites existing metadata{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('save() overwrites existing metadata', function () use ($handler, $diskName) {
    $handler->save($diskName, 'photo.jpg', [
        'title'    => 'Original Title',
        'alt_text' => 'Original Alt',
        'caption'  => 'Original Caption',
        'tags'     => 'original',
    ]);

    $handler->save($diskName, 'photo.jpg', [
        'title'    => 'Updated Title',
        'alt_text' => 'Updated Alt',
        'caption'  => 'Updated Caption',
        'tags'     => 'updated',
    ]);

    $result = $handler->get($diskName, 'photo.jpg');
    assertEqual('Updated Title', $result['title']);
    assertEqual('Updated Alt', $result['alt_text']);
    assertEqual('Updated Caption', $result['caption']);
    assertEqual('updated', $result['tags']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► delete() removes metadata{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('delete() removes metadata, get() returns null after', function () use ($handler, $diskName) {
    $handler->save($diskName, 'to-delete.jpg', [
        'title'    => 'Delete Me',
        'alt_text' => '',
        'caption'  => '',
        'tags'     => '',
    ]);

    // Confirm it exists
    $result = $handler->get($diskName, 'to-delete.jpg');
    assertEqual('Delete Me', $result['title']);

    $handler->delete($diskName, 'to-delete.jpg');

    $result = $handler->get($diskName, 'to-delete.jpg');
    assertEqual(null, $result);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► getBulk(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('getBulk() returns metadata for multiple keys', function () use ($handler, $diskName) {
    $handler->save($diskName, 'bulk-a.jpg', [
        'title' => 'Bulk A', 'alt_text' => '', 'caption' => '', 'tags' => 'a',
    ]);
    $handler->save($diskName, 'bulk-b.jpg', [
        'title' => 'Bulk B', 'alt_text' => '', 'caption' => '', 'tags' => 'b',
    ]);

    $result = $handler->getBulk($diskName, ['bulk-a.jpg', 'bulk-b.jpg', 'nonexistent.jpg']);

    assertEqual('Bulk A', $result['bulk-a.jpg']['title']);
    assertEqual('Bulk B', $result['bulk-b.jpg']['title']);
    assertEqual(null, $result['nonexistent.jpg']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► saveHash() + findByHash(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('saveHash() + findByHash() for duplicate detection', function () use ($handler, $diskName) {
    $handler->save($diskName, 'hashed-file.jpg', [
        'title' => 'Hashed', 'alt_text' => '', 'caption' => '', 'tags' => '',
    ]);
    $hash = md5('fake-content-for-hash');
    $handler->saveHash($diskName, 'hashed-file.jpg', $hash);

    $found = $handler->findByHash($diskName, $hash);
    assertEqual('hashed-file.jpg', $found['file_key']);
    assertEqual('Hashed', $found['title']);

    // Non-existent hash returns null
    $notFound = $handler->findByHash($diskName, md5('no-match'));
    assertEqual(null, $notFound);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► updateIndex() - index.json{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('updateIndex() - verify index.json is written', function () use ($handler, $diskName, $fs) {
    $handler->save($diskName, 'indexed-file.txt', [
        'title' => 'Indexed', 'alt_text' => 'idx', 'caption' => 'cap', 'tags' => 'tag1',
    ]);

    // Read index.json directly from the filesystem
    assertEqual(true, $fs->fileExists('_fluxfiles/index.json'), 'index.json should exist');

    $indexJson = $fs->read('_fluxfiles/index.json');
    $index = json_decode($indexJson, true);

    assertEqual(true, isset($index['indexed-file.txt']), 'indexed-file.txt should be in index');
    assertEqual('Indexed', $index['indexed-file.txt']['title']);
    assertEqual('idx', $index['indexed-file.txt']['alt_text']);
    assertEqual('cap', $index['indexed-file.txt']['caption']);
    assertEqual('tag1', $index['indexed-file.txt']['tags']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► deleteChildren(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('deleteChildren() removes children from index', function () use ($handler, $diskName) {
    // Save metadata for docs children so they appear in index
    $handler->save($diskName, 'docs/readme.txt', [
        'title' => 'Readme', 'alt_text' => '', 'caption' => '', 'tags' => '',
    ]);
    $handler->save($diskName, 'docs/notes.txt', [
        'title' => 'Notes', 'alt_text' => '', 'caption' => '', 'tags' => '',
    ]);
    $handler->save($diskName, 'docs/sub/deep.txt', [
        'title' => 'Deep', 'alt_text' => '', 'caption' => '', 'tags' => '',
    ]);

    $count = $handler->deleteChildren($diskName, 'docs');
    assertEqual(true, $count >= 3, "Expected at least 3 children deleted, got {$count}");

    // Should be removed from index
    assertEqual(null, $handler->get($diskName, 'docs/readme.txt'));
    assertEqual(null, $handler->get($diskName, 'docs/notes.txt'));
    assertEqual(null, $handler->get($diskName, 'docs/sub/deep.txt'));
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

rrmdir($tmpRoot);

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

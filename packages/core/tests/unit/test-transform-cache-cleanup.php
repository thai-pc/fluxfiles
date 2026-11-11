<?php

/**
 * The on-demand /img transforms cached in `_variants/` (ImageOptimizer::transformCacheKey())
 * are keyed by the file NAME. A single-file delete, trash, rename or move used to leave them
 * behind, so resized copies of a deleted or trashed image stayed on the disk at the old path.
 * FileManager::deleteTransformCache() now drops exactly that file's entries.
 *
 * The cache names are produced by transformCacheKey() itself, so a change to the key shape
 * that the cleanup regex does not follow fails here.
 *
 * Usage: php tests/unit/test-transform-cache-cleanup.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\ImageOptimizer;
use FluxFiles\StorageMetadataHandler;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

$root = sys_get_temp_dir() . '/ff-tcc-' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
register_shutdown_function(static function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });

$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
$fm = new FileManager($dm, new Claims('u', ['read', 'write', 'delete'], ['local'], '', 10, null, 0), new StorageMetadataHandler($dm));
$fs = $dm->disk('local');

/** Lay down a source file plus every shape of transform cache the endpoint can write for it. */
function seed($fs, string $key): array {
    $fs->write($key, 'source-bytes');
    $caches = [
        ImageOptimizer::transformCacheKey($key, 400, 80, '1794224276'),
        ImageOptimizer::transformCacheKey($key, 800, 75, '1794224276', '', 'avif'),
        ImageOptimizer::transformCacheKey($key, 300, 90, '1794224276', 'wm1a2b3c4d', 'webp', 200, 'cover'),
        ImageOptimizer::transformCacheKey($key, 300, 60, '', '', 'webp', 300),
    ];
    foreach ($caches as $c) { $fs->write($c, 'cached'); }
    return $caches;
}
function remaining($fs, array $paths): array {
    return array_values(array_filter($paths, static fn ($p) => $fs->fileExists($p)));
}

echo "\n══ /img transform cache cleanup ══\n\n";

// Neighbours that must survive every operation below.
$fs->write('a.png_w4', 'other-file');
$neighbours = [
    ImageOptimizer::transformCacheKey('a.png_w4', 400, 80, '1'),   // file whose name starts with "a.png_w"
    ImageOptimizer::transformCacheKey('b.png', 400, 80, '1'),      // unrelated file
    '_variants/b.png_thumb.webp',                                  // another file's upload-time variant
];
foreach ($neighbours as $n) { $fs->write($n, 'keep'); }

test('delete drops every cached transform of the file', function () use ($fm, $fs) {
    $c = seed($fs, 'a.png');
    $fm->delete('local', 'a.png');
    assertTrue(remaining($fs, $c) === [], 'left behind: ' . implode(', ', remaining($fs, $c)));
});

test('trash drops them (a trashed image must not stay viewable through its cache)', function () use ($fm, $fs) {
    $c = seed($fs, 'a.png');
    $fm->trash('local', 'a.png');
    assertTrue(remaining($fs, $c) === [], 'left behind: ' . implode(', ', remaining($fs, $c)));
});

test('rename drops the old name\'s transforms', function () use ($fm, $fs) {
    $c = seed($fs, 'pic.jpg');
    $fm->rename('local', 'pic.jpg', 'pic2.jpg');
    assertTrue(remaining($fs, $c) === [], 'left behind: ' . implode(', ', remaining($fs, $c)));
    assertTrue($fs->fileExists('pic2.jpg'), 'the file itself was renamed');
    $fm->delete('local', 'pic2.jpg');
});

test('move into a folder drops the source transforms', function () use ($fm, $fs) {
    $fs->createDirectory('sub');
    $c = seed($fs, 'm.png');
    $fm->move('local', 'm.png', 'sub/m.png');
    assertTrue(remaining($fs, $c) === [], 'left behind: ' . implode(', ', remaining($fs, $c)));
    $fm->delete('local', 'sub/m.png');
});

test('files in a sub-folder use that folder\'s _variants', function () use ($fm, $fs) {
    $fs->createDirectory('deep/er');
    $c = seed($fs, 'deep/er/x.webp');
    $fm->delete('local', 'deep/er/x.webp');
    assertTrue(remaining($fs, $c) === [], 'left behind: ' . implode(', ', remaining($fs, $c)));
});

test('neighbours are untouched (prefix-alike name, other file, upload-time variant)', function () use ($fs, $neighbours) {
    assertTrue(remaining($fs, $neighbours) === $neighbours, 'removed: ' . implode(', ', array_diff($neighbours, remaining($fs, $neighbours))));
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);

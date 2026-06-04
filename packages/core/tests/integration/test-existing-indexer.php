<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\ExistingFileIndexer;
use FluxFiles\StorageMetadataHandler;

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

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

function makeIndexer(string $root): array
{
    $dm = new DiskManager([
        'local' => [
            'driver' => 'local',
            'root' => $root,
            'url' => '/storage',
        ],
    ]);
    $meta = new StorageMetadataHandler($dm);
    return [$dm, $meta, new ExistingFileIndexer($dm, $meta)];
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles ExistingFileIndexer Tests         ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

echo "{$yellow}► local indexing{$reset}\n";

test('index-only indexes existing files and folders without sidecars', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-indexer-' . uniqid();
    mkdir($root . '/photos/2024', 0777, true);
    file_put_contents($root . '/photos/2024/sunset.jpg', 'image-ish');
    file_put_contents($root . '/photos/2024/readme.txt', 'hello');
    mkdir($root . '/_fluxfiles', 0777, true);
    file_put_contents($root . '/_fluxfiles/hidden.txt', 'hidden');

    try {
        [, $meta, $indexer] = makeIndexer($root);
        $stats = $indexer->index(['disk' => 'local']);

        assertEqual(2, $stats['files_indexed'], 'file count');
        assertEqual(true, $stats['folders_indexed'] >= 2, 'folder count');
        assertEqual(null, $meta->get('local', 'photos/2024/sunset.jpg'), 'index-only should not create sidecar metadata');

        $rows = $meta->search('local', 'sunset');
        assertEqual('photos/2024/sunset.jpg', $rows[0]['file_key'] ?? null, 'search should find indexed file');

        $folders = $meta->searchFolders('local', '2024');
        assertEqual('photos/2024', $folders[0]['dir_key'] ?? null, 'folder search should find indexed folder');

        $hidden = $meta->search('local', 'hidden');
        assertEqual(0, count($hidden), 'internal files should be skipped');
    } finally {
        rrmdir($root);
    }
});

test('_variants directories never enter folder search', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-variants-' . uniqid();
    mkdir($root, 0777, true);

    try {
        [, $meta] = makeIndexer($root);

        // Mirror what variant creation does: track the variant dir and the parents
        // of a variant file. A real sibling folder is tracked alongside it.
        $meta->trackDir('local', 'photos/_variants');
        $meta->trackParents('local', 'photos/_variants/sunset/thumb.webp');
        $meta->trackDir('local', 'photos/album');

        // Reserved namespace must not surface for any query…
        assertEqual(0, count($meta->searchFolders('local', 'variants')), 'no _variants in folder search');
        foreach ($meta->searchFolders('local', 'photos') as $f) {
            assertEqual(false, strpos($f['dir_key'], '_variants') !== false, 'photos search excludes _variants subtree');
        }
        // …while the genuine folder is still searchable.
        $album = $meta->searchFolders('local', 'album');
        assertEqual('photos/album', $album[0]['dir_key'] ?? null, 'real folder still searchable');
    } finally {
        rrmdir($root);
    }
});

test('owner assignment persists metadata for owner_only checks', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-indexer-owner-' . uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root . '/legacy.jpg', 'legacy');

    try {
        [, $meta, $indexer] = makeIndexer($root);
        $stats = $indexer->index(['disk' => 'local', 'owner' => 'user-123']);

        assertEqual(1, $stats['files_indexed']);
        $stored = $meta->get('local', 'legacy.jpg');
        assertEqual('user-123', $stored['uploaded_by'] ?? null);
    } finally {
        rrmdir($root);
    }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  ";
echo "{$green}Passed: {$passed}{$reset}  ";
echo "{$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

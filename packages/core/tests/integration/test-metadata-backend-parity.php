<?php

/**
 * Differential parity test: runs one identical operation sequence
 * (tag -> search -> rename -> trash/restore -> audit) against both
 * MetadataRepositoryInterface implementers — StorageMetadataHandler (JSON/file
 * backend, temp local disk) and FluxFiles\Db\DbMetadataHandler (DB backend,
 * temp SQLite) — and asserts the same *observable* outcomes at each step.
 *
 * This is not blanket field-for-field equality on every column: the two
 * backends' internal null/'' defaulting for never-set fields legitimately
 * differs (documented in DbMetadataHandler's get()/save() notes), so this
 * test only compares outcomes an API caller actually observes.
 *
 * Usage:
 *   php tests/integration/test-metadata-backend-parity.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\DiskManager;
use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\MetadataRepositoryInterface;
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
            $msg ?: 'Expected ' . json_encode($expected) . ' but got ' . json_encode($actual)
        );
    }
}

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
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Runs the identical scenario against any MetadataRepositoryInterface and
 * returns the subset of outcomes we compare for parity.
 */
function runScenario(MetadataRepositoryInterface $repo, string $disk): array
{
    // 1. tag
    $repo->save($disk, 'original.txt', [
        'title' => 'Parity Report', 'alt_text' => 'alt', 'caption' => 'cap',
        'tags' => 'parity,test', 'uploaded_by' => 'user-parity',
    ]);
    $afterTag = $repo->get($disk, 'original.txt');

    // 2. search
    $searchHits = array_column($repo->search($disk, 'Parity Report', 50, ''), 'file_key');

    // 3. rename
    $repo->renameChildren($disk, 'original.txt', 'renamed.txt');
    $afterRenameOld = $repo->get($disk, 'original.txt');
    $afterRenameNew = $repo->get($disk, 'renamed.txt');
    $searchHitsAfterRename = array_column($repo->search($disk, 'Parity Report', 50, ''), 'file_key');

    // 4. trash + restore
    $repo->addTrash($disk, 'parity-trash-1', [
        'original_key' => 'renamed.txt', 'disk' => $disk, 'basename' => 'renamed.txt',
        'size' => 7, 'deleted_at' => 1000, 'deleted_by' => 'user-parity',
        'meta' => ['title' => 'Parity Report'],
    ]);
    $repo->delete($disk, 'renamed.txt');
    $trashEntry = $repo->getTrash($disk, 'parity-trash-1');
    $getAfterTrashDelete = $repo->get($disk, 'renamed.txt');

    // restore: re-save metadata from the trash entry, remove the trash row
    $repo->save($disk, 'renamed.txt', $trashEntry['meta'] + ['uploaded_by' => $trashEntry['deleted_by']]);
    $repo->removeTrash($disk, 'parity-trash-1');
    $afterRestore = $repo->get($disk, 'renamed.txt');
    $trashAfterRemove = $repo->getTrash($disk, 'parity-trash-1');

    // 5. audit
    $repo->audit($disk, 'tag', ['user_id' => 'user-parity', 'file_key' => 'original.txt']);
    $repo->audit($disk, 'rename', ['user_id' => 'user-parity', 'file_key' => 'renamed.txt']);
    $repo->audit($disk, 'delete', ['user_id' => 'user-parity', 'file_key' => 'renamed.txt']);
    $repo->audit($disk, 'restore', ['user_id' => 'user-parity', 'file_key' => 'renamed.txt']);
    $auditActions = array_column($repo->readAudit($disk), 'action');

    return [
        'afterTag_title'          => $afterTag['title'] ?? null,
        'afterTag_tags'           => $afterTag['tags'] ?? null,
        'searchHits'              => $searchHits,
        'afterRenameOld'          => $afterRenameOld,
        'afterRenameNew_title'    => $afterRenameNew['title'] ?? null,
        'searchHitsAfterRename'   => $searchHitsAfterRename,
        'trashEntry_originalKey'  => $trashEntry['original_key'] ?? null,
        'trashEntry_isDir'        => $trashEntry['is_dir'] ?? null,
        'getAfterTrashDelete'     => $getAfterTrashDelete,
        'afterRestore_title'      => $afterRestore['title'] ?? null,
        'trashAfterRemove'        => $trashAfterRemove,
        'auditActions'            => $auditActions,
    ];
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║   Metadata Backend Parity (JSON vs DB) Suite      ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
// Setup: one JSON-backed handler (temp local disk), one DB-backed
// handler (temp SQLite), same disk name for both.
// ═══════════════════════════════════════════════════════════════

$disk = 'parity-disk';

$tmpRoot = sys_get_temp_dir() . '/ff_test_parity_' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0755, true);
$jsonDm = new DiskManager([$disk => ['driver' => 'local', 'root' => $tmpRoot]]);
$jsonDm->disk($disk)->write('original.txt', 'content');
$jsonRepo = new StorageMetadataHandler($jsonDm);

$dbFile = '/tmp/ff_test_parity_' . getmypid() . '.sqlite3';
@unlink($dbFile);
$dbTmpRoot = sys_get_temp_dir() . '/ff_test_parity_db_' . bin2hex(random_bytes(4));
mkdir($dbTmpRoot, 0755, true);
$conn = new Connection('sqlite:' . $dbFile);
(new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
$dbDm = new DiskManager([$disk => ['driver' => 'local', 'root' => $dbTmpRoot]]);
$dbDm->disk($disk)->write('original.txt', 'content');
$dbRepo = new DbMetadataHandler($conn, $dbDm);

$jsonResult = runScenario($jsonRepo, $disk);
$dbResult = runScenario($dbRepo, $disk);

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► tag{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('both backends report the same title after tagging', function () use ($jsonResult, $dbResult) {
    assertEqual($jsonResult['afterTag_title'], $dbResult['afterTag_title']);
    assertEqual('Parity Report', $jsonResult['afterTag_title']);
});

test('both backends report the same tags after tagging', function () use ($jsonResult, $dbResult) {
    assertEqual($jsonResult['afterTag_tags'], $dbResult['afterTag_tags']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► search{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('both backends find the tagged file by title substring', function () use ($jsonResult, $dbResult) {
    assertEqual(['original.txt'], $jsonResult['searchHits']);
    assertEqual(['original.txt'], $dbResult['searchHits']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► rename{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('both backends clear the old key and populate the new key after rename', function () use ($jsonResult, $dbResult) {
    assertEqual(null, $jsonResult['afterRenameOld']);
    assertEqual(null, $dbResult['afterRenameOld']);
    assertEqual('Parity Report', $jsonResult['afterRenameNew_title']);
    assertEqual('Parity Report', $dbResult['afterRenameNew_title']);
});

test('both backends update search results to the renamed key', function () use ($jsonResult, $dbResult) {
    assertEqual(['renamed.txt'], $jsonResult['searchHitsAfterRename']);
    assertEqual(['renamed.txt'], $dbResult['searchHitsAfterRename']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► trash + restore{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('both backends round-trip the trash entry identically', function () use ($jsonResult, $dbResult) {
    assertEqual('renamed.txt', $jsonResult['trashEntry_originalKey']);
    assertEqual('renamed.txt', $dbResult['trashEntry_originalKey']);
    // JSON stores the entry verbatim (no 'is_dir' key at all when unset -> null
    // via the ?? extraction below); DB normalizes to false. Both are falsy,
    // which is all real consumers (FileManager's !empty($entry['is_dir'])) rely
    // on — a raw-value difference here is expected, not a parity gap.
    assertEqual(false, (bool) $jsonResult['trashEntry_isDir']);
    assertEqual(false, (bool) $dbResult['trashEntry_isDir']);
});

test('both backends clear metadata for a soft-deleted key', function () use ($jsonResult, $dbResult) {
    assertEqual(null, $jsonResult['getAfterTrashDelete']);
    assertEqual(null, $dbResult['getAfterTrashDelete']);
});

test('both backends restore metadata after removeTrash()', function () use ($jsonResult, $dbResult) {
    assertEqual('Parity Report', $jsonResult['afterRestore_title']);
    assertEqual('Parity Report', $dbResult['afterRestore_title']);
    assertEqual(null, $jsonResult['trashAfterRemove']);
    assertEqual(null, $dbResult['trashAfterRemove']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► audit{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('both backends record audit actions in the same order', function () use ($jsonResult, $dbResult) {
    $expected = ['tag', 'rename', 'delete', 'restore'];
    assertEqual($expected, $jsonResult['auditActions']);
    assertEqual($expected, $dbResult['auditActions']);
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

rrmdir($tmpRoot);
rrmdir($dbTmpRoot);
@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

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

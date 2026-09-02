<?php

/**
 * Behavioral test suite for FluxFiles\Db\JsonToDbMigrator — the json -> db
 * cutover engine described in docs/DB-STORAGE-MIGRATION-DESIGN.md §9.
 *
 * Usage:
 *   php tests/unit/test-migrate-json-to-db.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\JsonToDbMigrator;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\DiskManager;
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

function assertTrue(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

$dbFile = '/tmp/ff_test_migrate_json_to_db_' . getmypid() . '.sqlite3';
$storageRoot = '/tmp/ff_test_migrate_json_to_db_storage_' . getmypid();

@unlink($dbFile);
if (is_dir($storageRoot)) {
    exec('rm -rf ' . escapeshellarg($storageRoot));
}
mkdir($storageRoot, 0775, true);

$conn = new Connection('sqlite:' . $dbFile);
(new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');

$diskManager = new DiskManager([
    'local' => ['driver' => 'local', 'root' => $storageRoot, 'url' => '/storage'],
]);
$disk = 'local';
$fs = $diskManager->disk($disk);

$source = new StorageMetadataHandler($diskManager);
$destination = new DbMetadataHandler($conn, $diskManager);
$migrator = new JsonToDbMigrator($diskManager, $source, $destination);

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║        JsonToDbMigrator Test Suite                ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► constructor guard{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('constructor rejects a destination that does not implement MigrationImportInterface', function () use ($diskManager, $source) {
    $bareDestination = new StorageMetadataHandler($diskManager);
    try {
        new JsonToDbMigrator($diskManager, $source, $bareDestination);
        throw new \RuntimeException('expected a RuntimeException, none thrown');
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'MigrationImportInterface'));
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► file metadata + directories + trash{$reset}\n";
// ═══════════════════════════════════════════════════════════════

$source->indexFile($disk, 'docs/report.pdf', ['title' => 'Report', 'uploaded_by' => 'u1', 'size' => 100, 'modified' => 1000, 'created' => 900], true);
$source->indexFile($disk, 'docs/plain.txt', ['size' => 50, 'modified' => 1000, 'created' => 900], true);
$source->indexFile($disk, 'other/outside.txt', ['title' => 'Outside', 'size' => 10, 'modified' => 1000, 'created' => 900], true);
$source->trackDir($disk, 'docs');
$source->trackDir($disk, 'other');
$source->addTrash($disk, 'trash-1', ['original_key' => 'docs/deleted.pdf', 'disk' => $disk, 'basename' => 'deleted.pdf', 'size' => 5, 'deleted_at' => 500, 'deleted_by' => 'u1']);
$source->addTrash($disk, 'trash-2', ['original_key' => 'other/deleted2.pdf', 'disk' => $disk, 'basename' => 'deleted2.pdf', 'size' => 5, 'deleted_at' => 500, 'deleted_by' => 'u1']);

test('dry-run reports counts but writes nothing to the destination', function () use ($migrator, $destination, $disk) {
    $result = $migrator->migrate($disk, '', true);
    assertEqual(3, $result['file_metadata']['insert']);
    assertEqual(2, $result['directories']['insert']);
    assertEqual(2, $result['trash']['insert']);
    assertEqual(null, $destination->get($disk, 'docs/report.pdf'));
    assertEqual([], $destination->dirsCreated($disk));
    assertEqual(null, $destination->getTrash($disk, 'trash-1'));
});

test('a real run inserts file metadata, directories, and trash into the destination', function () use ($migrator, $destination, $disk) {
    $result = $migrator->migrate($disk, '', false);
    assertEqual(3, $result['file_metadata']['insert']);
    assertEqual(0, $result['file_metadata']['update']);
    assertEqual(2, $result['directories']['insert']);
    assertEqual(2, $result['trash']['insert']);

    $meta = $destination->get($disk, 'docs/report.pdf');
    assertTrue($meta !== null);
    assertEqual('Report', $meta['title']);

    $dirs = $destination->dirsCreated($disk);
    assertTrue(array_key_exists('docs', $dirs));
    assertTrue(array_key_exists('other', $dirs));

    $trashEntry = $destination->getTrash($disk, 'trash-1');
    assertTrue($trashEntry !== null);
    assertEqual('docs/deleted.pdf', $trashEntry['original_key']);
});

test('re-running the migration is idempotent — everything buckets as skip', function () use ($migrator, $disk) {
    $result = $migrator->migrate($disk, '', false);
    assertEqual(0, $result['file_metadata']['insert']);
    assertEqual(0, $result['file_metadata']['update']);
    assertEqual(3, $result['file_metadata']['skip']);
    assertEqual(0, $result['directories']['insert']);
    assertEqual(2, $result['directories']['skip']);
    assertEqual(0, $result['trash']['insert']);
    assertEqual(2, $result['trash']['skip']);
});

test('verify() reports clean after a full real run', function () use ($migrator, $disk) {
    $result = $migrator->verify($disk);
    assertTrue(JsonToDbMigrator::isClean($result), 'expected a clean verify result: ' . json_encode($result));
});

test('a hand-edited source entry without a bumped modified is drift per verify() but invisible to migrate()', function () use ($migrator, $source, $disk) {
    $source->indexFile($disk, 'docs/report.pdf', ['title' => 'Report v2'], true);
    $result = $migrator->verify($disk);
    assertTrue(!JsonToDbMigrator::isClean($result), 'expected drift after hand-editing the source');
    assertTrue(array_key_exists('title', $result['file_metadata']['mismatched']['docs/report.pdf'] ?? []));

    $migrateResult = $migrator->migrate($disk, '', false);
    assertEqual(0, $migrateResult['file_metadata']['update'], 'a metadata edit that does not bump modified is a documented blind spot for migrate() — verify() is what catches it');
});

test('bumping modified on the next edit is picked up as an update on the next migrate() run', function () use ($migrator, $source, $destination, $disk) {
    $source->indexFile($disk, 'docs/report.pdf', ['modified' => 999999], true);
    $result = $migrator->migrate($disk, '', false);
    assertEqual(1, $result['file_metadata']['update']);
    $meta = $destination->get($disk, 'docs/report.pdf');
    assertEqual('Report v2', $meta['title']);
});

test('a source entry with no modified field at all is inserted once, then skipped forever (not perpetually re-flagged as update)', function () use ($migrator, $source, $destination, $disk) {
    $source->indexFile($disk, 'docs/no_modified.txt', ['title' => 'No Modified', 'size' => 1], true);
    assertEqual(null, $source->get($disk, 'docs/no_modified.txt')['modified'] ?? null, 'fixture must have no modified field');

    $first = $migrator->migrate($disk, '', false);
    assertEqual(1, $first['file_metadata']['insert']);

    $second = $migrator->migrate($disk, '', false);
    assertEqual(0, $second['file_metadata']['insert']);
    assertEqual(0, $second['file_metadata']['update'], 'a row with no source modified timestamp must not be re-flagged as update on every re-run');
    assertEqual(4, $second['file_metadata']['skip'], 'all 4 fixture files (incl. the new no-modified one) must skip on this run');

    $meta = $destination->get($disk, 'docs/no_modified.txt');
    assertEqual('No Modified', $meta['title']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► --prefix scoping{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('prefix scoping excludes out-of-scope file/dir/trash rows entirely (not even counted as skip)', function () use ($diskManager, $source, $destination, $disk) {
    $freshDb = '/tmp/ff_test_migrate_prefix_' . getmypid() . '.sqlite3';
    @unlink($freshDb);
    $conn2 = new Connection('sqlite:' . $freshDb);
    (new MigrationRunner($conn2))->migrate(__DIR__ . '/../../db/migrations');
    $destination2 = new DbMetadataHandler($conn2, $diskManager);
    $migrator2 = new JsonToDbMigrator($diskManager, $source, $destination2);

    $result = $migrator2->migrate($disk, 'docs', false);
    assertEqual(3, $result['file_metadata']['insert'], 'only docs/report.pdf + docs/plain.txt + docs/no_modified.txt are in-scope');
    assertEqual(1, $result['directories']['insert'], 'only the docs dir is in-scope');
    assertEqual(1, $result['trash']['insert'], 'only trash-1 (docs/deleted.pdf) is in-scope');

    assertEqual(null, $destination2->get($disk, 'other/outside.txt'), 'out-of-prefix file must not be migrated');
    assertTrue(!array_key_exists('other', $destination2->dirsCreated($disk)), 'out-of-prefix dir must not be migrated');
    assertEqual(null, $destination2->getTrash($disk, 'trash-2'), 'out-of-prefix trash entry must not be migrated');

    @unlink($freshDb);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► audit log — whole-disk, content-hash dedup{$reset}\n";
// ═══════════════════════════════════════════════════════════════

$auditRows = [
    ['ts' => 100, 'action' => 'upload', 'context' => ['user_id' => 'u1', 'file_key' => 'a.txt', 'ip' => '1.1.1.1']],
    ['ts' => 200, 'action' => 'delete', 'context' => ['user_id' => 'u1', 'file_key' => 'b.txt']],
];
$fs->write('_fluxfiles/audit.jsonl', implode("\n", array_map('json_encode', $auditRows)) . "\n" . "not-json-garbage\n");

$archiveRows = [
    ['ts' => 50, 'action' => 'upload', 'context' => ['user_id' => 'u2', 'file_key' => 'old.txt', 'detail' => ['reason' => 'seed', 'n' => 3]]],
];
$fs->createDirectory('_fluxfiles/audit/archive');
$fs->write('_fluxfiles/audit/archive/audit-0001.jsonl', implode("\n", array_map('json_encode', $archiveRows)) . "\n");

test('audit migration ignores prefix — always migrates the whole disk', function () use ($migrator, $disk) {
    $result = $migrator->migrate($disk, 'docs', true);
    assertEqual(3, $result['audit']['insert'], 'all 3 well-formed rows (live x2 + archive x1) should be counted regardless of prefix');
});

test('a real run inserts audit rows from both the live file and the archive, tolerating a malformed line', function () use ($migrator, $destination, $disk) {
    $result = $migrator->migrate($disk, '', false);
    assertEqual(3, $result['audit']['insert']);

    $entries = $destination->readAudit($disk);
    $actions = array_column($entries, 'action');
    assertTrue(in_array('upload', $actions, true));
    assertTrue(in_array('delete', $actions, true));
});

test('non-scalar detail from the archive row is stored without erroring', function () use ($destination, $disk) {
    $entries = $destination->readAudit($disk);
    $found = null;
    foreach ($entries as $e) {
        if ($e['file_key'] === 'old.txt') {
            $found = $e;
        }
    }
    assertTrue($found !== null, 'archived audit row should have migrated');
    assertTrue($found['detail'] !== null, 'non-scalar detail should have been json_encode()d, not dropped');
});

test('re-running audit migration is idempotent via content-hash dedup', function () use ($migrator, $disk) {
    $result = $migrator->migrate($disk, '', false);
    assertEqual(0, $result['audit']['insert']);
    assertEqual(3, $result['audit']['skip']);
});

test('verify() reports the audit section clean after migration', function () use ($migrator, $disk) {
    $result = $migrator->verify($disk);
    assertEqual([], $result['audit']['missing_in_db']);
});

test('a newly appended live audit row is detected as missing by verify() before the next migrate()', function () use ($fs, $migrator, $disk) {
    $fs->write(
        '_fluxfiles/audit.jsonl',
        $fs->read('_fluxfiles/audit.jsonl') . json_encode(['ts' => 300, 'action' => 'rename', 'context' => ['user_id' => 'u1', 'file_key' => 'c.txt']]) . "\n"
    );
    $result = $migrator->verify($disk);
    assertEqual(1, count($result['audit']['missing_in_db']));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► orphaned local sidecar fallback{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('a sidecar with no index.json entry is picked up by the fallback step', function () use ($fs, $migrator, $destination, $disk) {
    $fs->createDirectory('_fluxfiles/meta');
    $fs->write('_fluxfiles/meta/orphan/photo.jpg.json', json_encode([
        'title' => 'Orphan Photo', 'alt_text' => '', 'caption' => '', 'tags' => '', 'uploaded_by' => 'u9',
    ]));

    $result = $migrator->migrate($disk, '', false);
    assertEqual(1, $result['sidecar_fallback']['insert']);

    $meta = $destination->get($disk, 'orphan/photo.jpg');
    assertTrue($meta !== null);
    assertEqual('Orphan Photo', $meta['title']);
});

test('re-running the fallback step is a no-op the second time', function () use ($migrator, $disk) {
    $result = $migrator->migrate($disk, '', false);
    assertEqual(0, $result['sidecar_fallback']['insert']);
    assertEqual(1, $result['sidecar_fallback']['skip']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► source-file integrity{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('a real migration run never modifies any _fluxfiles/*.json* source file', function () use ($fs, $migrator, $disk) {
    $watched = ['_fluxfiles/index.json', '_fluxfiles/dirs.json', '_fluxfiles/trash.json', '_fluxfiles/audit.jsonl'];
    $before = [];
    foreach ($watched as $path) {
        $before[$path] = $fs->fileExists($path) ? hash('sha256', $fs->read($path)) : null;
    }

    $migrator->migrate($disk, '', false);
    $migrator->migrate($disk, '', true);
    $migrator->verify($disk);

    foreach ($watched as $path) {
        $after = $fs->fileExists($path) ? hash('sha256', $fs->read($path)) : null;
        assertEqual($before[$path], $after, "{$path} must be byte-for-byte unchanged after migration");
    }
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');
exec('rm -rf ' . escapeshellarg($storageRoot));

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

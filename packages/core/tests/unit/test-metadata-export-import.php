<?php

/**
 * Behavioral test suite for FluxFiles\Db\MetadataExporter / MetadataImporter —
 * the DB-backend backup/restore tooling described in
 * docs/DB-STORAGE-MIGRATION-DESIGN.md §7.
 *
 * Usage:
 *   php tests/unit/test-metadata-export-import.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MetadataExporter;
use FluxFiles\Db\MetadataImporter;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\DiskManager;

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

$dbFile = '/tmp/ff_test_metadata_export_import_' . getmypid() . '.sqlite3';
$storageRoot = '/tmp/ff_test_metadata_export_import_storage_' . getmypid();

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

$meta = new DbMetadataHandler($conn, $diskManager);
$meta->indexFile($disk, 'docs/report.pdf', ['title' => 'Report', 'uploaded_by' => 'u1', 'size' => 100, 'modified' => 1000, 'created' => 900], true);
$meta->indexFile($disk, 'docs/plain.txt', ['title' => 'Plain', 'uploaded_by' => 'u2', 'size' => 50, 'modified' => 1000, 'created' => 900], true);
$meta->indexFile($disk, 'other/outside.txt', ['title' => 'Outside', 'uploaded_by' => 'u1', 'size' => 10, 'modified' => 1000, 'created' => 900], true);

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║   MetadataExporter / MetadataImporter Test Suite  ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► MetadataExporter{$reset}\n";
// ═══════════════════════════════════════════════════════════════

$exporter = new MetadataExporter($conn);

test('rows() yields every row on the disk when no prefix/owner filter is given', function () use ($exporter, $disk) {
    $rows = iterator_to_array($exporter->rows($disk));
    assertEqual(3, count($rows));
    $paths = array_column($rows, 'path');
    assertTrue(in_array('docs/report.pdf', $paths, true));
    assertTrue(in_array('other/outside.txt', $paths, true));
});

test('rows() filters by prefix', function () use ($exporter, $disk) {
    $rows = iterator_to_array($exporter->rows($disk, 'docs'));
    assertEqual(2, count($rows));
    foreach ($rows as $row) {
        assertTrue(str_starts_with($row['path'], 'docs/'));
    }
});

test('rows() filters by owner', function () use ($exporter, $disk) {
    $rows = iterator_to_array($exporter->rows($disk, '', 'u2'));
    assertEqual(1, count($rows));
    assertEqual('docs/plain.txt', $rows[0]['path']);
});

test('streamTo() writes valid NDJSON with one row per line', function () use ($exporter, $disk) {
    $handle = fopen('php://memory', 'r+');
    $count = $exporter->streamTo($handle, $disk, 'ndjson', 'docs');
    assertEqual(2, $count);
    rewind($handle);
    $lines = array_filter(explode("\n", stream_get_contents($handle)));
    assertEqual(2, count($lines));
    $decoded = json_decode($lines[0], true);
    assertTrue(is_array($decoded));
    assertTrue(array_key_exists('object_uuid', $decoded), 'export row must include object_uuid');
    fclose($handle);
});

test('streamTo() writes a CSV header plus one data row per file', function () use ($exporter, $disk) {
    $handle = fopen('php://memory', 'r+');
    $count = $exporter->streamTo($handle, $disk, 'csv', 'docs');
    assertEqual(2, $count);
    rewind($handle);
    $header = fgetcsv($handle);
    assertEqual(MetadataExporter::COLUMNS, $header);
    $row1 = fgetcsv($handle);
    assertTrue($row1 !== false);
    fclose($handle);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► MetadataImporter{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('import() rejects the whole batch when any row is out of scope, writing nothing', function () use ($conn, $disk) {
    $freshDb = '/tmp/ff_test_metadata_import_scope_' . getmypid() . '.sqlite3';
    @unlink($freshDb);
    $conn2 = new Connection('sqlite:' . $freshDb);
    (new MigrationRunner($conn2))->migrate(__DIR__ . '/../../db/migrations');
    $importer = new MetadataImporter($conn2);

    $entries = [
        ['path' => 'users/42/a.txt', 'title' => 'A'],
        ['path' => 'users/99/b.txt', 'title' => 'B'],
    ];
    $isAllowed = fn(string $path) => str_starts_with($path, 'users/42/');
    $result = $importer->import($disk, $entries, $isAllowed);

    assertEqual(0, $result['imported']);
    assertEqual(1, count($result['errors']));
    assertEqual(1, $result['errors'][0]['row']);
    assertEqual('path_out_of_scope', $result['errors'][0]['error']);

    $dst = new DbMetadataHandler($conn2, new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/storage']]));
    assertEqual(null, $dst->getBulk($disk, ['users/42/a.txt'])['users/42/a.txt'], 'the in-scope row must NOT be written when a later row fails — all-or-nothing');

    @unlink($freshDb);
});

test('import() reports missing_path for an entry with no path', function () use ($conn, $disk) {
    $freshDb = '/tmp/ff_test_metadata_import_missing_' . getmypid() . '.sqlite3';
    @unlink($freshDb);
    $conn2 = new Connection('sqlite:' . $freshDb);
    (new MigrationRunner($conn2))->migrate(__DIR__ . '/../../db/migrations');
    $importer = new MetadataImporter($conn2);

    $result = $importer->import($disk, [['title' => 'No path']], fn(string $path) => true);
    assertEqual(0, $result['imported']);
    assertEqual('missing_path', $result['errors'][0]['error']);

    @unlink($freshDb);
});

test('import() writes every row inside scope and is upsert-safe on re-import', function () use ($conn, $disk) {
    $freshDb = '/tmp/ff_test_metadata_import_ok_' . getmypid() . '.sqlite3';
    @unlink($freshDb);
    $conn2 = new Connection('sqlite:' . $freshDb);
    (new MigrationRunner($conn2))->migrate(__DIR__ . '/../../db/migrations');
    $importer = new MetadataImporter($conn2);
    $diskManager2 = new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/storage']]);
    $dst = new DbMetadataHandler($conn2, $diskManager2);

    $entries = [
        ['path' => 'docs/a.txt', 'title' => 'A', 'owner' => 'u1', 'size' => 10, 'object_uuid' => 'uuid-a'],
        ['path' => 'docs/b.txt', 'title' => 'B', 'owner' => 'u2', 'size' => 20],
    ];
    $result = $importer->import($disk, $entries, fn(string $path) => true);
    assertEqual(2, $result['imported']);
    assertEqual([], $result['errors']);

    $meta = $dst->getBulk($disk, ['docs/a.txt']);
    assertEqual('A', $meta['docs/a.txt']['title']);

    // Re-import with a changed title must upsert, not duplicate or error.
    $result2 = $importer->import($disk, [['path' => 'docs/a.txt', 'title' => 'A2', 'owner' => 'u1']], fn(string $path) => true);
    assertEqual(1, $result2['imported']);
    $meta2 = $dst->getBulk($disk, ['docs/a.txt']);
    assertEqual('A2', $meta2['docs/a.txt']['title']);

    @unlink($freshDb);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► export -> import round trip{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('exporting a disk and importing the NDJSON into a fresh DB reproduces the same rows', function () use ($exporter, $conn, $disk) {
    $handle = fopen('php://memory', 'r+');
    $exporter->streamTo($handle, $disk, 'ndjson');
    rewind($handle);
    $lines = array_filter(explode("\n", stream_get_contents($handle)));
    fclose($handle);

    $entries = array_map(fn($l) => json_decode($l, true), $lines);
    assertEqual(3, count($entries));

    $freshDb = '/tmp/ff_test_metadata_roundtrip_' . getmypid() . '.sqlite3';
    @unlink($freshDb);
    $conn2 = new Connection('sqlite:' . $freshDb);
    (new MigrationRunner($conn2))->migrate(__DIR__ . '/../../db/migrations');
    $importer = new MetadataImporter($conn2);
    $result = $importer->import($disk, $entries, fn(string $path) => true);
    assertEqual(3, $result['imported']);

    $dst = new DbMetadataHandler($conn2, new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/storage']]));
    $meta = $dst->getBulk($disk, ['docs/report.pdf', 'docs/plain.txt', 'other/outside.txt']);
    assertEqual('Report', $meta['docs/report.pdf']['title']);
    assertEqual('u1', $meta['docs/report.pdf']['uploaded_by']);
    assertEqual('Outside', $meta['other/outside.txt']['title']);

    @unlink($freshDb);
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

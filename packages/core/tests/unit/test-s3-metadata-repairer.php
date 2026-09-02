<?php

/**
 * Behavioral test suite for FluxFiles\Db\S3MetadataRepairer — the S3/R2
 * breadcrumb repair flow described in docs/DB-STORAGE-MIGRATION-DESIGN.md §8.
 *
 * scanBucket() needs a live S3/R2 bucket and is intentionally NOT covered
 * here (consistent with the rest of the suite keeping live-S3 checks in the
 * separately-gated tests/e2e/test-s3-live.php). This file covers the parts
 * that don't need one: reconcile() (a pure function — the primary target),
 * and dbRows()/apply() against a real sqlite Connection.
 *
 * Usage:
 *   php tests/unit/test-s3-metadata-repairer.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\Db\S3MetadataRepairer;
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

function freshConn(): Connection
{
    $file = '/tmp/ff_test_s3_repairer_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite3';
    @unlink($file);
    $conn = new Connection('sqlite:' . $file);
    (new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
    return $conn;
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║        S3MetadataRepairer Test Suite              ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

$diskManager = new DiskManager([
    's3' => ['driver' => 's3', 'bucket' => 'test-bucket', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's'],
]);

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► reconcile() — pure diff logic{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('reconcile() reports no findings when db and bucket agree', function () use ($diskManager) {
    $conn = freshConn();
    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $dbRows = ['uuid-a' => 'docs/a.txt', 'uuid-b' => 'docs/b.txt'];
    $s3Objects = ['uuid-a' => 'docs/a.txt', 'uuid-b' => 'docs/b.txt'];
    $result = $repairer->reconcile($dbRows, $s3Objects);
    assertEqual([], $result['moved']);
    assertEqual([], $result['orphaned_objects']);
    assertEqual([], $result['orphaned_rows']);
});

test('reconcile() classifies a same-uuid different-key object as moved', function () use ($diskManager) {
    $conn = freshConn();
    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $dbRows = ['uuid-a' => 'docs/old.txt'];
    $s3Objects = ['uuid-a' => 'docs/new.txt'];
    $result = $repairer->reconcile($dbRows, $s3Objects);
    assertEqual(1, count($result['moved']));
    assertEqual('uuid-a', $result['moved'][0]['uuid']);
    assertEqual('docs/old.txt', $result['moved'][0]['old_path']);
    assertEqual('docs/new.txt', $result['moved'][0]['new_path']);
    assertEqual([], $result['orphaned_objects']);
    assertEqual([], $result['orphaned_rows']);
});

test('reconcile() classifies a breadcrumb with no matching row as an orphaned object', function () use ($diskManager) {
    $conn = freshConn();
    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $result = $repairer->reconcile([], ['uuid-x' => 'docs/stray.txt']);
    assertEqual([], $result['moved']);
    assertEqual(1, count($result['orphaned_objects']));
    assertEqual('uuid-x', $result['orphaned_objects'][0]['uuid']);
    assertEqual('docs/stray.txt', $result['orphaned_objects'][0]['key']);
    assertEqual([], $result['orphaned_rows']);
});

test('reconcile() classifies a row whose uuid matches no scanned object as an orphaned row', function () use ($diskManager) {
    $conn = freshConn();
    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $result = $repairer->reconcile(['uuid-y' => 'docs/gone.txt'], []);
    assertEqual([], $result['moved']);
    assertEqual([], $result['orphaned_objects']);
    assertEqual(1, count($result['orphaned_rows']));
    assertEqual('uuid-y', $result['orphaned_rows'][0]['uuid']);
    assertEqual('docs/gone.txt', $result['orphaned_rows'][0]['path']);
});

test('reconcile() handles a mix of moved, orphaned_objects, and orphaned_rows in one pass', function () use ($diskManager) {
    $conn = freshConn();
    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $dbRows = [
        'uuid-moved' => 'docs/before.txt',
        'uuid-same' => 'docs/same.txt',
        'uuid-deleted-object' => 'docs/deleted.txt',
    ];
    $s3Objects = [
        'uuid-moved' => 'docs/after.txt',
        'uuid-same' => 'docs/same.txt',
        'uuid-new-object' => 'docs/stray.txt',
    ];
    $result = $repairer->reconcile($dbRows, $s3Objects);
    assertEqual(1, count($result['moved']));
    assertEqual('uuid-moved', $result['moved'][0]['uuid']);
    assertEqual(1, count($result['orphaned_objects']));
    assertEqual('uuid-new-object', $result['orphaned_objects'][0]['uuid']);
    assertEqual(1, count($result['orphaned_rows']));
    assertEqual('uuid-deleted-object', $result['orphaned_rows'][0]['uuid']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► dbRows(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('dbRows() returns only rows with a recorded breadcrumb, keyed by uuid', function () use ($diskManager) {
    $conn = freshConn();
    $stmt = $conn->pdo()->prepare(
        'INSERT INTO file_metadata (disk, path, path_hash, object_uuid, created_at, modified_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(['s3', 'docs/a.txt', hash('sha256', 'docs/a.txt'), 'uuid-a', 1000, 1000]);
    $stmt->execute(['s3', 'docs/no-uuid.txt', hash('sha256', 'docs/no-uuid.txt'), null, 1000, 1000]);
    $stmt->execute(['other-disk', 'docs/b.txt', hash('sha256', 'docs/b.txt'), 'uuid-b', 1000, 1000]);

    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $rows = $repairer->dbRows('s3');
    assertEqual(['uuid-a' => 'docs/a.txt'], $rows);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► apply(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('apply() re-points path and path_hash for each moved entry', function () use ($diskManager) {
    $conn = freshConn();
    $handler = new DbMetadataHandler($conn, new DiskManager(['s3' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's']]));
    $stmt = $conn->pdo()->prepare(
        'INSERT INTO file_metadata (disk, path, path_hash, object_uuid, created_at, modified_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(['s3', 'docs/old.txt', hash('sha256', 'docs/old.txt'), 'uuid-a', 1000, 1000]);

    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $count = $repairer->apply('s3', [
        ['uuid' => 'uuid-a', 'old_path' => 'docs/old.txt', 'new_path' => 'docs/new.txt'],
    ]);
    assertEqual(1, $count);

    $row = $conn->pdo()->query("SELECT path, path_hash FROM file_metadata WHERE object_uuid = 'uuid-a'")->fetch();
    assertEqual('docs/new.txt', $row['path']);
    assertEqual(hash('sha256', 'docs/new.txt'), $row['path_hash']);
});

test('apply() returns 0 and writes nothing for an empty moved list', function () use ($diskManager) {
    $conn = freshConn();
    $repairer = new S3MetadataRepairer($conn, $diskManager);
    assertEqual(0, $repairer->apply('s3', []));
});

test('apply() deletes a conflicting row already at the destination path before re-pointing — the incoming move wins', function () use ($diskManager) {
    $conn = freshConn();
    $stmt = $conn->pdo()->prepare(
        'INSERT INTO file_metadata (disk, path, path_hash, object_uuid, created_at, modified_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    // A stale row already sits at the destination path under a different uuid
    // (e.g. a deleted-then-recreated file that never got cleaned up).
    $stmt->execute(['s3', 'docs/new.txt', hash('sha256', 'docs/new.txt'), 'uuid-stale', 1000, 1000]);
    $stmt->execute(['s3', 'docs/old.txt', hash('sha256', 'docs/old.txt'), 'uuid-a', 1000, 1000]);

    $repairer = new S3MetadataRepairer($conn, $diskManager);
    $count = $repairer->apply('s3', [
        ['uuid' => 'uuid-a', 'old_path' => 'docs/old.txt', 'new_path' => 'docs/new.txt'],
    ]);
    assertEqual(1, $count);

    $rows = $conn->pdo()->query('SELECT path, object_uuid FROM file_metadata')->fetchAll();
    assertEqual(1, count($rows), 'the stale destination row must be removed, leaving exactly one row');
    assertEqual('docs/new.txt', $rows[0]['path']);
    assertEqual('uuid-a', $rows[0]['object_uuid']);
});

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

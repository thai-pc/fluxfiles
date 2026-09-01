<?php

/**
 * Behavioral test suite for FluxFiles\Db\DbMetadataHandler over a real MySQL
 * database — self-contained duplicate of test-db-metadata-sqlite.php's body
 * against the `mysql` dialect. Skips (exit 0) when FXTEST_DB_MYSQL_DSN is not
 * set, so it's safe to pick up in the blanket unit-test loop on every job,
 * including ones with no MySQL service.
 *
 * Usage:
 *   FXTEST_DB_MYSQL_DSN="mysql:host=127.0.0.1;dbname=fluxfiles_test;charset=utf8mb4" \
 *   FXTEST_DB_MYSQL_USER=root FXTEST_DB_MYSQL_PASSWORD=root \
 *   php tests/unit/test-db-metadata-mysql.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\DiskManager;

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$dsn = getenv('FXTEST_DB_MYSQL_DSN');
if (!$dsn) {
    echo "\n{$cyan}══ DbMetadataHandler (MySQL) Test Suite ══{$reset}\n";
    echo "  {$yellow}SKIP{$reset} — FXTEST_DB_MYSQL_DSN not provided\n\n";
    exit(0);
}

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

$user = getenv('FXTEST_DB_MYSQL_USER') ?: '';
$pass = getenv('FXTEST_DB_MYSQL_PASSWORD') ?: '';
$storageRoot = sys_get_temp_dir() . '/ff_test_db_metadata_mysql_' . getmypid();

if (is_dir($storageRoot)) {
    exec('rm -rf ' . escapeshellarg($storageRoot));
}
mkdir($storageRoot, 0775, true);

$conn = new Connection($dsn, $user, $pass);
(new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');

// Reset tables in case a prior run against this same live database left rows
// behind (a fresh CI service container never hits this branch — the tables
// are created empty by the migrate() call above).
foreach (['file_metadata', 'directories', 'trash', 'audit_log', 'rate_limits'] as $table) {
    $conn->pdo()->exec("DELETE FROM {$table}");
}

$diskManager = new DiskManager([
    'local' => ['driver' => 'local', 'root' => $storageRoot, 'url' => '/storage'],
]);

$repo = new DbMetadataHandler($conn, $diskManager);
$disk = 'local';

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║       DbMetadataHandler (MySQL) Test Suite        ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► save()/get() roundtrip{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('get() returns null for an untouched key', function () use ($repo, $disk) {
    assertEqual(null, $repo->get($disk, 'nope.txt'));
});

test('save() then get() roundtrips title/alt/caption/tags/uploaded_by', function () use ($repo, $disk) {
    $repo->save($disk, 'photo.jpg', [
        'title' => 'A Photo', 'alt_text' => 'alt', 'caption' => 'cap', 'tags' => 'a,b',
        'uploaded_by' => 'user-1', 'mime' => 'image/jpeg', 'size' => 1234,
        'width' => 800, 'height' => 600, 'modified' => 1000, 'created' => 900,
    ]);
    $meta = $repo->get($disk, 'photo.jpg');
    assertEqual('A Photo', $meta['title']);
    assertEqual('alt', $meta['alt_text']);
    assertEqual('cap', $meta['caption']);
    assertEqual('a,b', $meta['tags']);
    assertEqual('user-1', $meta['uploaded_by']);
});

test('save() with only upload-time fields still yields "" defaults for text fields', function () use ($repo, $disk) {
    $repo->save($disk, 'plain.jpg', [
        'uploaded_by' => 'user-2', 'size' => 10, 'modified' => 100, 'created' => 100,
    ]);
    $meta = $repo->get($disk, 'plain.jpg');
    assertEqual('', $meta['title']);
    assertEqual('', $meta['alt_text']);
    assertEqual('', $meta['caption']);
    assertEqual('', $meta['tags']);
});

test('created_at is sticky on the first write and not overwritten by a later save()', function () use ($repo, $disk) {
    $repo->save($disk, 'sticky.jpg', ['uploaded_by' => 'u', 'created' => 500]);
    $repo->save($disk, 'sticky.jpg', ['uploaded_by' => 'u', 'created' => 999]);
    $bulk = $repo->getBulk($disk, ['sticky.jpg']);
    assertEqual(500, $bulk['sticky.jpg']['created']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► indexFile(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('indexFile(overwrite=false) is a true no-op on an existing row', function () use ($repo, $disk) {
    $repo->indexFile($disk, 'idx.jpg', ['mime' => 'image/jpeg', 'size' => 1], false);
    $second = $repo->indexFile($disk, 'idx.jpg', ['mime' => 'image/png', 'size' => 999], false);
    assertEqual(false, $second);
    $bulk = $repo->getBulk($disk, ['idx.jpg']);
    assertEqual('image/jpeg', $bulk['idx.jpg']['mime']);
    assertEqual(1, $bulk['idx.jpg']['size']);
});

test('indexFile(overwrite=true) updates the row and returns true', function () use ($repo, $disk) {
    $repo->indexFile($disk, 'idx2.jpg', ['mime' => 'image/jpeg', 'size' => 1], false);
    $result = $repo->indexFile($disk, 'idx2.jpg', ['mime' => 'image/png', 'size' => 999], true);
    assertEqual(true, $result);
    $bulk = $repo->getBulk($disk, ['idx2.jpg']);
    assertEqual('image/png', $bulk['idx2.jpg']['mime']);
    assertEqual(999, $bulk['idx2.jpg']['size']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► saveHash() before save() (real upload ordering){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('saveHash() on a brand-new key, then save(), preserves the hash', function () use ($repo, $disk) {
    $repo->saveHash($disk, 'hashed.jpg', 'deadbeef');
    $repo->save($disk, 'hashed.jpg', ['uploaded_by' => 'u3', 'size' => 5, 'modified' => 1, 'created' => 1]);
    $found = $repo->findByHash($disk, 'deadbeef');
    assertTrue($found !== null, 'findByHash should locate the row saveHash() created');
    assertEqual('hashed.jpg', $found['file_key']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► search(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('search() finds by title within a path prefix and excludes reserved/hidden paths', function () use ($repo, $disk) {
    $repo->save($disk, 'docs/report.pdf', ['title' => 'Quarterly Report', 'uploaded_by' => 'u']);
    $repo->save($disk, 'docs/.hidden.pdf', ['title' => 'Quarterly Report', 'uploaded_by' => 'u']);
    $repo->save($disk, '_fluxfiles/meta/x.json', ['title' => 'Quarterly Report', 'uploaded_by' => 'u']);
    $repo->save($disk, 'other/report.pdf', ['title' => 'Quarterly Report', 'uploaded_by' => 'u']);

    $results = $repo->search($disk, 'Quarterly', 50, 'docs');
    $keys = array_column($results, 'file_key');
    assertTrue(in_array('docs/report.pdf', $keys, true), 'finds the in-scope match');
    assertTrue(!in_array('docs/.hidden.pdf', $keys, true), 'excludes hidden path by default');
    assertTrue(!in_array('_fluxfiles/meta/x.json', $keys, true), 'excludes reserved path');
    assertTrue(!in_array('other/report.pdf', $keys, true), 'excludes out-of-prefix match');
});

test('search() highlight wraps the match in <mark>', function () use ($repo, $disk) {
    $repo->save($disk, 'mark-test.txt', ['title' => 'Highlight Me', 'uploaded_by' => 'u']);
    $results = $repo->search($disk, 'Highlight', 50, '');
    $row = null;
    foreach ($results as $r) {
        if ($r['file_key'] === 'mark-test.txt') {
            $row = $r;
        }
    }
    assertTrue($row !== null);
    assertTrue(str_contains($row['title_hl'], '<mark>'), 'title_hl should contain <mark>');
});

test('search() respects the limit', function () use ($repo, $disk) {
    for ($i = 0; $i < 5; $i++) {
        $repo->save($disk, "limit-test-{$i}.txt", ['title' => 'LimitCase', 'uploaded_by' => 'u']);
    }
    $results = $repo->search($disk, 'LimitCase', 3, '');
    assertEqual(3, count($results));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► renameChildren() — source wins on collision{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('renameChildren() moves a whole subtree', function () use ($repo, $disk) {
    $repo->save($disk, 'movefrom/a.txt', ['title' => 'A', 'uploaded_by' => 'u']);
    $repo->save($disk, 'movefrom/b.txt', ['title' => 'B', 'uploaded_by' => 'u']);
    $count = $repo->renameChildren($disk, 'movefrom', 'moveto');
    assertEqual(2, $count);
    assertEqual(null, $repo->get($disk, 'movefrom/a.txt'));
    assertTrue($repo->get($disk, 'moveto/a.txt') !== null);
});

test('renameChildren() collision: renamed/source entry wins over an existing destination row', function () use ($repo, $disk) {
    $repo->save($disk, 'coll-src/f.txt', ['title' => 'FromSource', 'uploaded_by' => 'u']);
    $repo->save($disk, 'coll-dst/f.txt', ['title' => 'AlreadyThere', 'uploaded_by' => 'u']);
    $repo->renameChildren($disk, 'coll-src', 'coll-dst');
    $meta = $repo->get($disk, 'coll-dst/f.txt');
    assertEqual('FromSource', $meta['title']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► directory index{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('trackDir()/dirsCreated() round trip', function () use ($repo, $disk) {
    $repo->trackDir($disk, 'projects/alpha');
    $dirs = $repo->dirsCreated($disk);
    assertTrue(array_key_exists('projects/alpha', $dirs));
});

test('trackParents() tracks every ancestor segment', function () use ($repo, $disk) {
    $repo->trackParents($disk, 'projects/beta/gamma/file.txt');
    $dirs = $repo->dirsCreated($disk);
    assertTrue(array_key_exists('projects', $dirs));
    assertTrue(array_key_exists('projects/beta', $dirs));
    assertTrue(array_key_exists('projects/beta/gamma', $dirs));
});

test('searchFolders() finds a tracked directory by name', function () use ($repo, $disk) {
    $repo->trackDir($disk, 'searchable-dir');
    $results = $repo->searchFolders($disk, 'searchable', 50, '');
    $keys = array_column($results, 'dir_key');
    assertTrue(in_array('searchable-dir', $keys, true));
});

test('renameDirPrefix() destination wins on collision', function () use ($repo, $disk) {
    $repo->trackDir($disk, 'dir-coll-src');
    $repo->trackDir($disk, 'dir-coll-dst');
    $repo->renameDirPrefix($disk, 'dir-coll-src', 'dir-coll-dst');
    $dirs = $repo->dirsCreated($disk);
    assertTrue(array_key_exists('dir-coll-dst', $dirs), 'destination entry survives');
    assertTrue(!array_key_exists('dir-coll-src', $dirs), 'source entry is gone either way');
});

test('deleteDirPrefix() removes a directory subtree', function () use ($repo, $disk) {
    $repo->trackDir($disk, 'del-dir');
    $repo->trackDir($disk, 'del-dir/child');
    $count = $repo->deleteDirPrefix($disk, 'del-dir');
    assertTrue($count >= 2);
    $dirs = $repo->dirsCreated($disk);
    assertTrue(!array_key_exists('del-dir', $dirs));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► findByHash() scoping{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('findByHash() respects pathPrefix and ownerUserId scoping', function () use ($repo, $disk) {
    $repo->saveHash($disk, 'scope/owned-by-a.txt', 'hash-scope-1');
    $repo->save($disk, 'scope/owned-by-a.txt', ['uploaded_by' => 'user-a']);

    $found = $repo->findByHash($disk, 'hash-scope-1', 'scope', 'user-a');
    assertTrue($found !== null);

    $notFound = $repo->findByHash($disk, 'hash-scope-1', 'other-scope', 'user-a');
    assertEqual(null, $notFound);

    $wrongOwner = $repo->findByHash($disk, 'hash-scope-1', 'scope', 'user-b');
    assertEqual(null, $wrongOwner);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► trash{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('addTrash()/getTrash()/removeTrash() round trip a file entry', function () use ($repo, $disk) {
    $repo->addTrash($disk, 'trash-id-1', [
        'original_key' => 'was/here.txt', 'disk' => $disk, 'basename' => 'here.txt',
        'size' => 42, 'deleted_at' => 111, 'deleted_by' => 'u',
        'variants' => ['thumb' => 'x'], 'meta' => ['title' => 'T'],
    ]);
    $entry = $repo->getTrash($disk, 'trash-id-1');
    assertTrue($entry !== null);
    assertEqual('was/here.txt', $entry['original_key']);
    assertEqual(false, $entry['is_dir']);
    assertEqual(['thumb' => 'x'], $entry['variants']);
    assertEqual([], $entry['files']);

    $repo->removeTrash($disk, 'trash-id-1');
    assertEqual(null, $repo->getTrash($disk, 'trash-id-1'));
});

test('addTrash() round trips a directory entry with files/dirs arrays', function () use ($repo, $disk) {
    $repo->addTrash($disk, 'trash-id-2', [
        'original_key' => 'was/a/dir', 'disk' => $disk, 'basename' => 'dir',
        'is_dir' => true, 'size' => 100, 'deleted_at' => 222, 'deleted_by' => 'u',
        'files' => ['a.txt', 'b.txt'], 'dirs' => ['sub'],
    ]);
    $entry = $repo->getTrash($disk, 'trash-id-2');
    assertEqual(true, $entry['is_dir']);
    assertEqual(['a.txt', 'b.txt'], $entry['files']);
    assertEqual(['sub'], $entry['dirs']);
    assertEqual([], $entry['variants'], 'variants defaults to [] on a directory entry');
});

test('allTrash() returns every entry for a disk keyed by id', function () use ($repo, $disk) {
    $repo->addTrash($disk, 'trash-id-3', ['original_key' => 'x', 'disk' => $disk]);
    $all = $repo->allTrash($disk);
    assertTrue(array_key_exists('trash-id-3', $all));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► audit log{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('audit()/readAudit() round trip', function () use ($repo, $disk) {
    $repo->audit($disk, 'upload', ['user_id' => 'u1', 'file_key' => 'f.txt', 'ip' => '1.2.3.4']);
    $entries = $repo->readAudit($disk);
    $found = false;
    foreach ($entries as $e) {
        if ($e['action'] === 'upload' && $e['file_key'] === 'f.txt') {
            $found = true;
        }
    }
    assertTrue($found);
});

test('readAudit(userId) filters to that user only', function () use ($repo, $disk) {
    $repo->audit($disk, 'delete', ['user_id' => 'user-filter-a', 'file_key' => 'a.txt']);
    $repo->audit($disk, 'delete', ['user_id' => 'user-filter-b', 'file_key' => 'b.txt']);
    $entries = $repo->readAudit($disk, 'user-filter-a');
    foreach ($entries as $e) {
        assertEqual('user-filter-a', $e['user_id']);
    }
});

test('readAuditArchive() is always empty — no archive concept in DB mode', function () use ($repo, $disk) {
    assertEqual([], $repo->readAuditArchive($disk));
});

test('purgeAuditBefore() removes old rows and archives_deleted is always 0', function () use ($repo, $disk) {
    $repo->audit($disk, 'old-action', ['user_id' => 'u', 'file_key' => 'old.txt']);
    $result = $repo->purgeAuditBefore($disk, time() + 3600);
    assertEqual(0, $result['archives_deleted']);
    assertTrue($result['live_lines_removed'] >= 1);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► countChildren() — live storage walk, not COUNT(*){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('countChildren() counts a real file with no DB row, and ignores a DB row with no real file', function () use ($repo, $disk, $diskManager, $storageRoot) {
    mkdir($storageRoot . '/count-test', 0775, true);
    file_put_contents($storageRoot . '/count-test/untracked.txt', 'hello');

    $repo->save($disk, 'count-test/phantom.txt', ['uploaded_by' => 'u']);

    $count = $repo->countChildren($disk, 'count-test');
    assertEqual(1, $count, 'only the real file on disk should be counted');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► delete()/deleteChildren(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('delete() removes a single row', function () use ($repo, $disk) {
    $repo->save($disk, 'to-delete.txt', ['uploaded_by' => 'u']);
    $repo->delete($disk, 'to-delete.txt');
    assertEqual(null, $repo->get($disk, 'to-delete.txt'));
});

test('deleteChildren() removes a whole subtree', function () use ($repo, $disk) {
    $repo->save($disk, 'del-tree/a.txt', ['uploaded_by' => 'u']);
    $repo->save($disk, 'del-tree/b.txt', ['uploaded_by' => 'u']);
    $count = $repo->deleteChildren($disk, 'del-tree');
    assertEqual(2, $count);
    assertEqual(null, $repo->get($disk, 'del-tree/a.txt'));
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

foreach (['file_metadata', 'directories', 'trash', 'audit_log', 'rate_limits'] as $table) {
    $conn->pdo()->exec("DELETE FROM {$table}");
}
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

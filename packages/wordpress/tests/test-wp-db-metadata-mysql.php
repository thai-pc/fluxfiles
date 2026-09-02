<?php

/**
 * Behavioral test suite for WpDbMetadataHandler over a real MySQL database,
 * driven through a wpdb-shaped shim backed by PDO (no full WordPress runtime).
 * Mirrors packages/laravel/tests/test-laravel-db-metadata.php's scenario list
 * — all three backends (core PDO, Laravel, WordPress) must stay behaviorally
 * interchangeable.
 *
 * Uses the same FXTEST_DB_MYSQL_* env-var convention as
 * packages/core/tests/unit/test-db-metadata-mysql.php, and can safely share
 * that test's `fluxfiles_test` database: its own tables are created under a
 * distinct `wptest_` prefix.
 *
 * Tables here are created via plain CREATE TABLE IF NOT EXISTS rather than a
 * stubbed dbDelta() — dbDelta() is a large WP-core function impractical to
 * fake faithfully; real dbDelta() only gets exercised by the WordPress e2e
 * Playwright suite against actual Docker WP. Column/index shapes match
 * FluxFilesDbSchema exactly (this file is the deliberate substitute).
 *
 * Usage:
 *   FXTEST_DB_MYSQL_DSN="mysql:host=127.0.0.1;dbname=fluxfiles_test;charset=utf8mb4" \
 *   FXTEST_DB_MYSQL_USER=root FXTEST_DB_MYSQL_PASSWORD=root \
 *   php packages/wordpress/tests/test-wp-db-metadata-mysql.php
 */

declare(strict_types=1);

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$dsn = getenv('FXTEST_DB_MYSQL_DSN');
if (!$dsn) {
    echo "\n{$cyan}══ WpDbMetadataHandler (MySQL) Test Suite ══{$reset}\n";
    echo "  {$yellow}SKIP{$reset} — FXTEST_DB_MYSQL_DSN not provided\n\n";
    exit(0);
}

$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = 'expected true'): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// See test-wp-smoke.php: CI's floor check overrides FLUXFILES_CORE_AUTOLOAD to
// run against core at this adapter's declared composer floor.
$coreAutoload = getenv('FLUXFILES_CORE_AUTOLOAD') ?: __DIR__ . '/../../core/vendor/autoload.php';
require_once $coreAutoload;
require_once __DIR__ . '/../includes/WpDbMetadataHandler.php';

use FluxFiles\DiskManager;

/**
 * Minimal wpdb-shaped shim backed by a real PDO MySQL connection.
 * WpDbMetadataHandler always calls prepare() itself and passes the already-
 * interpolated SQL on to query()/get_row()/get_results()/get_var(), so those
 * four just need to execute plain SQL — no placeholder handling there.
 */
class WpdbPdoShim
{
    public string $prefix;
    private \PDO $pdo;

    public function __construct(\PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function prepare(string $sql, $args)
    {
        $args = is_array($args) ? $args : array_slice(func_get_args(), 1);
        $i = 0;
        return preg_replace_callback('/%s|%d/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? null;
            if ($m[0] === '%d') {
                return (string) (int) $v;
            }
            return $this->pdo->quote((string) $v);
        }, $sql);
    }

    public function query(string $sql)
    {
        $affected = $this->pdo->exec($sql);
        return $affected === false ? false : $affected;
    }

    public function get_row(string $sql, $output = null)
    {
        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function get_results(string $sql, $output = null): array
    {
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function get_var(string $sql)
    {
        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }
}

$user = getenv('FXTEST_DB_MYSQL_USER') ?: 'root';
$pass = getenv('FXTEST_DB_MYSQL_PASSWORD') ?: '';
$pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

$prefix = 'wptest_';
$tFileMeta = $prefix . 'fluxfiles_file_metadata';
$tDirs = $prefix . 'fluxfiles_directories';
$tTrash = $prefix . 'fluxfiles_trash';
$tAudit = $prefix . 'fluxfiles_audit_log';

foreach ([$tFileMeta, $tDirs, $tTrash, $tAudit] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS {$t}");
}

$pdo->exec("CREATE TABLE {$tFileMeta} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  disk varchar(64) NOT NULL,
  owner varchar(191) NULL,
  path TEXT COLLATE utf8mb4_bin NOT NULL,
  path_hash char(64) NOT NULL,
  title text NULL,
  alt_text text NULL,
  caption text NULL,
  tags text NULL,
  mime varchar(191) NULL,
  size bigint(20) NULL,
  width int(11) NULL,
  height int(11) NULL,
  file_hash varchar(64) NULL,
  watermarked smallint(6) NULL,
  object_uuid varchar(64) NULL,
  created_at bigint(20) NULL,
  modified_at bigint(20) NULL,
  extra JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY disk_path_hash (disk,path_hash),
  KEY disk_owner (disk,owner),
  KEY disk_file_hash (disk,file_hash),
  KEY disk_path (disk,path(191))
) DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE {$tDirs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  disk varchar(64) NOT NULL,
  path TEXT COLLATE utf8mb4_bin NOT NULL,
  path_hash char(64) NOT NULL,
  created_at bigint(20) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY disk_path_hash (disk,path_hash),
  KEY disk_path (disk,path(191))
) DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE {$tTrash} (
  disk varchar(64) NOT NULL,
  id varchar(64) NOT NULL,
  owner varchar(191) NULL,
  original_key text NOT NULL,
  basename varchar(512) NULL,
  is_dir smallint(6) DEFAULT 0,
  size bigint(20) NULL,
  deleted_at bigint(20) NULL,
  variants JSON NULL,
  meta JSON NULL,
  files JSON NULL,
  dirs JSON NULL,
  PRIMARY KEY (disk,id),
  KEY disk_owner (disk,owner),
  KEY disk_deleted_at (disk,deleted_at)
) DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE {$tAudit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  disk varchar(64) NOT NULL,
  owner varchar(191) NULL,
  action varchar(191) NOT NULL,
  file_key text NULL,
  ip varchar(64) NULL,
  user_agent text NULL,
  detail text NULL,
  created_at bigint(20) NOT NULL,
  PRIMARY KEY (id),
  KEY disk_owner_created_at (disk,owner,created_at),
  KEY disk_created_at (disk,created_at),
  KEY disk_action_created_at (disk,action,created_at)
) DEFAULT CHARSET=utf8mb4");

global $wpdb;
$wpdb = new WpdbPdoShim($pdo, $prefix);

$storageRoot = sys_get_temp_dir() . '/ff_test_wp_db_metadata_storage_' . getmypid();
if (is_dir($storageRoot)) {
    exec('rm -rf ' . escapeshellarg($storageRoot));
}
mkdir($storageRoot, 0775, true);

$diskManager = new DiskManager([
    'local' => ['driver' => 'local', 'root' => $storageRoot, 'url' => '/storage'],
]);

$repo = new WpDbMetadataHandler($diskManager);
$disk = 'local';

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║   WpDbMetadataHandler (PDO-shimmed wpdb) Suite    ║{$reset}\n";
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

test('countChildren() counts a real file with no DB row, and ignores a DB row with no real file', function () use ($repo, $disk, $storageRoot) {
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

exec('rm -rf ' . escapeshellarg($storageRoot));
foreach ([$tFileMeta, $tDirs, $tTrash, $tAudit] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS {$t}");
}

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

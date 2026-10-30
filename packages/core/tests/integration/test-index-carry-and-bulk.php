<?php

/**
 * 1. A rename/move/copy carries the WHOLE index entry (file_hash, mime, size,
 *    dimensions, dates, and the null-vs-"" of the text fields) — it used to keep
 *    only the sidecar fields.
 * 2. Bulk paths (extract, folder restore, empty-trash, retention) write the
 *    disk-wide index / dirs.json / trash.json once per batch window, stay
 *    exception-safe, and renew the lock every BULK_BATCH_ENTRIES entries.
 * 3. trackParents/trackDir write dirs.json only when a folder key is new.
 * 4. owner_only on folder delete/rename/move (assertOwnsTree via getBulk).
 * 5. list(sort=date) reads dirs.json once.
 *
 * Usage:
 *   php tests/integration/test-index-carry-and-bulk.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEq($e, $a, string $m): void { if ($e !== $a) throw new \RuntimeException($m . ': expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** Counts writes and reads of the bookkeeping files. */
final class BookkeepingCounter implements FilesystemAdapter
{
    /** @var array<string,int> */
    public array $writes = [];
    /** @var array<string,int> */
    public array $reads = [];
    private const WATCH = ['_fluxfiles/index.json' => 'index', '_fluxfiles/dirs.json' => 'dirs', '_fluxfiles/trash.json' => 'trash'];
    public function __construct(private LocalFilesystemAdapter $in) {}
    public function reset(): void { $this->writes = []; $this->reads = []; }
    private function w(string $p): void { if (isset(self::WATCH[$p])) { $this->writes[self::WATCH[$p]] = ($this->writes[self::WATCH[$p]] ?? 0) + 1; } }
    private function r(string $p): void { if (isset(self::WATCH[$p])) { $this->reads[self::WATCH[$p]] = ($this->reads[self::WATCH[$p]] ?? 0) + 1; } }
    public function fileExists(string $path): bool { return $this->in->fileExists($path); }
    public function directoryExists(string $path): bool { return $this->in->directoryExists($path); }
    public function write(string $path, string $contents, Config $config): void { $this->w($path); $this->in->write($path, $contents, $config); }
    public function writeStream(string $path, $contents, Config $config): void { $this->w($path); $this->in->writeStream($path, $contents, $config); }
    public function read(string $path): string { $this->r($path); return $this->in->read($path); }
    public function readStream(string $path) { return $this->in->readStream($path); }
    public function delete(string $path): void { $this->in->delete($path); }
    public function deleteDirectory(string $path): void { $this->in->deleteDirectory($path); }
    public function createDirectory(string $path, Config $config): void { $this->in->createDirectory($path, $config); }
    public function setVisibility(string $path, string $visibility): void { $this->in->setVisibility($path, $visibility); }
    public function visibility(string $path): FileAttributes { return $this->in->visibility($path); }
    public function mimeType(string $path): FileAttributes { return $this->in->mimeType($path); }
    public function lastModified(string $path): FileAttributes { return $this->in->lastModified($path); }
    public function fileSize(string $path): FileAttributes { return $this->in->fileSize($path); }
    public function listContents(string $path, bool $deep): iterable { return $this->in->listContents($path, $deep); }
    public function move(string $source, string $destination, Config $config): void { $this->in->move($source, $destination, $config); }
    public function copy(string $source, string $destination, Config $config): void { $this->in->copy($source, $destination, $config); }
}

final class Disks extends DiskManager
{
    public BookkeepingCounter $adapter;
    private Filesystem $fs;
    public function __construct(string $root)
    {
        parent::__construct([
            'local' => ['driver' => 'local', 'root' => $root, 'url' => '/s'],
            'other' => ['driver' => 'local', 'root' => $root . '-other', 'url' => '/o'],
        ]);
        $this->adapter = new BookkeepingCounter(new LocalFilesystemAdapter($root));
        $this->fs = new Filesystem($this->adapter);
    }
    public function disk(string $name): Filesystem
    {
        return $name === 'other' ? parent::disk('other') : $this->fs;
    }
}

/** @return array{0:FileManager,1:Disks,2:StorageMetadataHandler,3:string,4:Claims} */
function env(string $user = 'u', bool $ownerOnly = false, array $extra = []): array
{
    $root = sys_get_temp_dir() . '/ff-carry-' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    mkdir($root . '-other', 0777, true);
    $dm = new Disks($root);
    $meta = new StorageMetadataHandler($dm);
    $c = new Claims($user, ['read', 'write', 'delete'], ['local', 'other'], '', 50, null, 0, false);
    $c->ownerOnly = $ownerOnly;
    foreach ($extra as $k => $v) { $c->$k = $v; }
    return [new FileManager($dm, $c, $meta), $dm, $meta, $root, $c];
}

function up(FileManager $fm, string $dir, string $name, string $bytes, string $disk = 'local'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ffc');
    file_put_contents($tmp, $bytes);
    try { return $fm->upload($disk, $dir, ['name' => $name, 'size' => strlen($bytes), 'tmp_name' => $tmp]); }
    finally { @unlink($tmp); }
}

function index(string $root): array
{
    return json_decode((string) file_get_contents("{$root}/_fluxfiles/index.json"), true) ?: [];
}

function makeZip(string $path, int $n): void
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE);
    for ($i = 0; $i < $n; $i++) { $z->addFromString("d/f{$i}.txt", "c{$i}"); }
    $z->close();
}

function rmrf(string $d): void { exec('rm -rf ' . escapeshellarg($d)); }

echo "\n{$cyan}══ Rename / move / copy carry the full index entry ══{$reset}\n\n";

test('rename keeps hash/mime/size/dims/dates and the null text fields', function () {
    [$fm, , , $root] = env();
    up($fm, '', 'a.txt', 'hello');
    $before = index($root)['a.txt'];
    assertTrue(isset($before['file_hash']) && $before['title'] === null, 'precondition: hash set, title null');
    $fm->rename('local', 'a.txt', 'b.txt');
    $idx = index($root);
    assertTrue(!isset($idx['a.txt']), 'old key gone');
    assertEq($before, $idx['b.txt'], 'index entry identical after rename');
    rmrf($root); rmrf($root . '-other');
});

test('move into another folder keeps the entry', function () {
    [$fm, , , $root] = env();
    up($fm, '', 'a.txt', 'hello');
    $fm->mkdir('local', 'sub');
    $before = index($root)['a.txt'];
    $fm->move('local', 'a.txt', 'sub/a.txt');
    assertEq($before, index($root)['sub/a.txt'], 'entry after move');
    rmrf($root); rmrf($root . '-other');
});

test('copy within a disk keeps hash/mime/size on the copy (source untouched)', function () {
    [$fm, , , $root] = env();
    up($fm, '', 'a.txt', 'hello');
    $before = index($root)['a.txt'];
    $fm->mkdir('local', 'sub');
    $fm->copy('local', 'a.txt', 'sub/a.txt');
    $idx = index($root);
    assertEq($before, $idx['a.txt'], 'source entry unchanged');
    assertEq($before, $idx['sub/a.txt'], 'copy entry equals source entry');
    rmrf($root); rmrf($root . '-other');
});

test('copy across disks keeps the entry on the destination disk', function () {
    [$fm, , , $root] = env();
    up($fm, '', 'a.txt', 'hello');
    $before = index($root)['a.txt'];
    $fm->crossCopy('local', 'a.txt', 'other', 'a.txt');
    $idx = json_decode((string) file_get_contents("{$root}-other/_fluxfiles/index.json"), true);
    assertEq($before, $idx['a.txt'] ?? null, 'cross-disk copy entry');
    rmrf($root); rmrf($root . '-other');
});

test('a user-edited title survives the move together with the index fields', function () {
    [$fm, , $meta, $root] = env();
    up($fm, '', 'a.txt', 'hello');
    $meta->save('local', 'a.txt', ['title' => 'My title', 'tags' => 'x,y']);
    $fm->rename('local', 'a.txt', 'b.txt');
    $e = index($root)['b.txt'];
    assertEq('My title', $e['title'], 'title');
    assertEq('x,y', $e['tags'], 'tags');
    assertTrue(isset($e['file_hash'], $e['mime'], $e['size']), 'index fields present');
    rmrf($root); rmrf($root . '-other');
});

echo "\n{$cyan}══ dirs.json is written only on change ══{$reset}\n\n";

test('trackParents / trackDir of known folders write nothing', function () {
    [, $dm, $meta, $root] = env();
    $meta->trackParents('local', 'a/b/c.txt');
    $meta->trackDir('local', 'x');
    $dm->adapter->reset();
    $meta->trackParents('local', 'a/b/d.txt');
    $meta->trackDir('local', 'a/b');
    $meta->trackDir('local', 'x');
    assertEq([], $dm->adapter->writes, 'no write for known folders');
    $meta->trackParents('local', 'a/b/new/e.txt');
    assertEq(['dirs' => 1], $dm->adapter->writes, 'one write for a new folder');
    assertTrue(isset(json_decode((string) file_get_contents("$root/_fluxfiles/dirs.json"), true)['a/b/new']), 'folder recorded');
    rmrf($root); rmrf($root . '-other');
});

echo "\n{$cyan}══ Bulk paths: one write per batch ══{$reset}\n\n";

test('extract of a 50-entry zip: index <= 2 writes, dirs <= 2 (was ~100 / ~50)', function () {
    [$fm, $dm, , $root] = env();
    mkdir("$root/a");
    makeZip("$root/a/x.zip", 50);
    $dm->adapter->reset();
    $r = $fm->extractZip('local', 'a/x.zip', 'out');
    assertEq(50, $r['extracted'], 'extracted');
    assertTrue(($dm->adapter->writes['index'] ?? 0) <= 2, 'index writes ' . ($dm->adapter->writes['index'] ?? 0));
    assertTrue(($dm->adapter->writes['dirs'] ?? 0) <= 2, 'dirs writes ' . ($dm->adapter->writes['dirs'] ?? 0));
    $idx = index($root);
    for ($i = 0; $i < 50; $i++) {
        assertTrue(isset($idx["out/d/f{$i}.txt"]['file_hash']), "entry f{$i} indexed with hash");
    }
    assertTrue(isset(json_decode((string) file_get_contents("$root/_fluxfiles/dirs.json"), true)['out/d']), 'dirs recorded');
    rmrf($root); rmrf($root . '-other');
});

test('extract of 1100 entries renews the batch every 500 (a few writes, not 1, not 1100)', function () {
    [$fm, $dm, , $root] = env();
    mkdir("$root/a");
    makeZip("$root/a/big.zip", 1100);
    $dm->adapter->reset();
    $fm->extractZip('local', 'a/big.zip', 'out');
    $w = $dm->adapter->writes['index'] ?? 0;
    assertTrue($w >= 3 && $w <= 8, "index writes $w");
    assertEq(1100, count(array_filter(array_keys(index($root)), fn ($k) => str_starts_with((string) $k, 'out/'))), 'all indexed');
    rmrf($root); rmrf($root . '-other');
});

test('restore of a trashed folder of 50: index/dirs/trash <= 2 writes each', function () {
    [$fm, $dm, , $root] = env();
    mkdir("$root/a");
    makeZip("$root/a/x.zip", 50);
    $fm->extractZip('local', 'a/x.zip', 'out');
    $t = $fm->trash('local', 'out');
    $dm->adapter->reset();
    $fm->restore('local', $t['trash_id']);
    foreach (['index', 'dirs', 'trash'] as $k) {
        assertTrue(($dm->adapter->writes[$k] ?? 0) <= 2, "$k writes " . ($dm->adapter->writes[$k] ?? 0));
    }
    assertTrue(isset(index($root)['out/d/f7.txt']), 'metadata restored');
    assertEq([], json_decode((string) file_get_contents("$root/_fluxfiles/trash.json"), true), 'manifest emptied');
    rmrf($root); rmrf($root . '-other');
});

test('emptyTrash of 20 items: trash.json <= 2 writes', function () {
    [$fm, $dm, , $root] = env();
    for ($i = 0; $i < 20; $i++) { up($fm, '', "t{$i}.txt", "x{$i}"); $fm->trash('local', "t{$i}.txt"); }
    $dm->adapter->reset();
    $r = $fm->emptyTrash('local');
    assertEq(20, $r['purged'], 'purged');
    assertTrue(($dm->adapter->writes['trash'] ?? 0) <= 2, 'trash writes ' . ($dm->adapter->writes['trash'] ?? 0));
    assertEq([], json_decode((string) file_get_contents("$root/_fluxfiles/trash.json"), true), 'manifest emptied');
    rmrf($root); rmrf($root . '-other');
});

test('retention run (trash) over 20 files: trash.json and index <= 2 writes each', function () {
    [$fm, $dm, , $root, $c] = env('u', false, ['retentionRules' => [['path' => '', 'days' => 1, 'action' => 'trash']]]);
    for ($i = 0; $i < 20; $i++) { up($fm, '', "r{$i}.txt", "x{$i}"); touch("$root/r{$i}.txt", time() - 5 * 86400); }
    $dm->adapter->reset();
    $r = $fm->runRetention('local', false);
    assertEq(20, $r['acted'], 'acted');
    foreach (['trash', 'index'] as $k) {
        assertTrue(($dm->adapter->writes[$k] ?? 0) <= 2, "$k writes " . ($dm->adapter->writes[$k] ?? 0));
    }
    assertEq(20, count(json_decode((string) file_get_contents("$root/_fluxfiles/trash.json"), true)), 'manifest has 20');
    rmrf($root); rmrf($root . '-other');
});

echo "\n{$cyan}══ Batches are exception-safe ══{$reset}\n\n";

function lockFree(string $root): bool
{
    $fp = fopen("$root/_fluxfiles/index.lock", 'c+');
    $ok = flock($fp, LOCK_EX | LOCK_NB);
    if ($ok) { flock($fp, LOCK_UN); }
    fclose($fp);
    return $ok;
}

test('extract aborted mid-way (scanner throws on entry 3): earlier entries stay indexed, lock released', function () {
    [$fm, , , $root] = env();
    mkdir("$root/a");
    makeZip("$root/a/x.zip", 6);
    $n = 0;
    $fm->setVirusScanner(function (string $p) use (&$n) {
        if (++$n === 3) { throw new ApiException('engine down', 502, 'virus_failed'); }
        return ['clean' => true];
    });
    try { $fm->extractZip('local', 'a/x.zip', 'out'); throw new \RuntimeException('should have thrown'); }
    catch (ApiException $e) { assertEq('virus_failed', $e->getErrorCode(), 'code'); }
    $idx = index($root);
    $kept = array_filter(array_keys($idx), fn ($k) => str_starts_with((string) $k, 'out/'));
    assertEq(2, count($kept), 'the two entries written before the failure are indexed');
    assertTrue(isset(json_decode((string) file_get_contents("$root/_fluxfiles/dirs.json"), true)['out/d']), 'dirs flushed');
    assertTrue(lockFree($root), 'index lock released');
    rmrf($root); rmrf($root . '-other');
});

test('withIndexBatch: a throw still flushes index + dirs + trash and frees the lock', function () {
    [, , $meta, $root] = env();
    try {
        $meta->withIndexBatch('local', function () use ($meta) {
            $meta->indexFile('local', 'k.txt', ['size' => 1]);
            $meta->trackDir('local', 'newdir');
            $meta->addTrash('local', 'abc123', ['original_key' => 'k.txt']);
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) {}
    assertTrue(isset(index($root)['k.txt']), 'index flushed');
    assertTrue(isset(json_decode((string) file_get_contents("$root/_fluxfiles/dirs.json"), true)['newdir']), 'dirs flushed');
    assertTrue(isset(json_decode((string) file_get_contents("$root/_fluxfiles/trash.json"), true)['abc123']), 'trash flushed');
    assertTrue(lockFree($root), 'lock released');
    rmrf($root); rmrf($root . '-other');
});

test('a failing index write does not stop dirs/trash from being written, and frees the lock', function () {
    [, $dm, $meta, $root] = env();
    // Make index.json unwritable as a path: a directory squats on the name.
    mkdir("$root/_fluxfiles/index.json", 0777, true);
    $threw = false;
    try {
        $meta->withIndexBatch('local', function () use ($meta) {
            $meta->indexFile('local', 'k.txt', ['size' => 1]);
            $meta->trackDir('local', 'keepme');
        });
    } catch (\Throwable $e) { $threw = true; }
    assertTrue($threw, 'the index write error surfaces');
    assertTrue(isset(json_decode((string) file_get_contents("$root/_fluxfiles/dirs.json"), true)['keepme']), 'dirs still flushed');
    assertTrue(lockFree($root), 'lock released');
    rmrf($root); rmrf($root . '-other');
});

test('checkpointIndexBatch writes mid-batch and keeps the lock; no-op outside a batch', function () {
    [, $dm, $meta, $root] = env();
    $meta->checkpointIndexBatch('local'); // outside: nothing
    assertEq([], $dm->adapter->writes, 'no write outside a batch');
    $meta->withIndexBatch('local', function () use ($meta, $root, $dm) {
        $meta->indexFile('local', 'k.txt', ['size' => 1]);
        $meta->checkpointIndexBatch('local');
        assertTrue(isset(index($root)['k.txt']), 'on disk after checkpoint');
        assertTrue(!lockFree($root), 'lock still held');
        $meta->checkpointIndexBatch('local'); // clean now: no second write
        assertEq(1, $dm->adapter->writes['index'] ?? 0, 'a clean checkpoint writes nothing');
    });
    rmrf($root); rmrf($root . '-other');
});

echo "\n{$cyan}══ owner_only on folder operations ══{$reset}\n\n";

function ownerSetup(): array
{
    [$fmA, $dm, $meta, $root, $cA] = env('alice', true);
    $fmA->mkdir('local', 'shared');
    up($fmA, 'shared', 'one.txt', '1');
    up($fmA, 'shared', 'two.txt', '2');
    $cB = new Claims('bob', ['read', 'write', 'delete'], ['local', 'other'], '', 50, null, 0, false);
    $cB->ownerOnly = true;
    $fmB = new FileManager($dm, $cB, $meta);
    return [$fmA, $fmB, $meta, $root];
}

foreach (['trash' => fn ($fm) => $fm->trash('local', 'shared'),
          'delete' => fn ($fm) => $fm->delete('local', 'shared'),
          'rename' => fn ($fm) => $fm->rename('local', 'shared', 'renamed'),
          'move' => function ($fm) { $fm->mkdir('local', 'dest'); return $fm->move('local', 'shared', 'dest/shared'); }] as $op => $do) {
    test("owner_only: another user cannot $op a folder of someone else's files", function () use ($do) {
        [, $fmB, , $root] = ownerSetup();
        try { $do($fmB); throw new \RuntimeException('should be refused'); }
        catch (ApiException $e) { assertEq('owner_only', $e->getErrorCode(), 'code'); }
        assertTrue(file_exists("$root/shared/one.txt"), 'files untouched');
        rmrf($root); rmrf($root . '-other');
    });
}

test('owner_only: the owner can trash their own folder', function () {
    [$fmA, , , $root] = ownerSetup();
    $r = $fmA->trash('local', 'shared');
    assertTrue(!empty($r['trashed']), 'trashed');
    rmrf($root); rmrf($root . '-other');
});

test('owner_only: a file missing from the index is still judged by its sidecar owner', function () {
    [, $fmB, , $root] = ownerSetup();
    // Drop the index entirely: getBulk must fall back to the sidecar for every key.
    unlink("$root/_fluxfiles/index.json");
    try { $fmB->trash('local', 'shared'); throw new \RuntimeException('should be refused'); }
    catch (ApiException $e) { assertEq('owner_only', $e->getErrorCode(), 'code'); }
    rmrf($root); rmrf($root . '-other');
});

test('owner_only: an index entry WITHOUT an owner defers to the sidecar owner (never laxer)', function () {
    [, $fmB, , $root] = ownerSetup();
    $idx = index($root);
    $idx['shared/one.txt']['uploaded_by'] = null; // index lost the owner, sidecar still has alice
    file_put_contents("$root/_fluxfiles/index.json", json_encode($idx));
    try { $fmB->delete('local', 'shared'); throw new \RuntimeException('should be refused'); }
    catch (ApiException $e) { assertEq('owner_only', $e->getErrorCode(), 'code'); }
    rmrf($root); rmrf($root . '-other');
});

test('owner_only: legacy files with no owner anywhere stay allowed', function () {
    [, $fmB, $meta, $root] = ownerSetup();
    file_put_contents("$root/shared/legacy.txt", 'x');
    foreach (['one.txt', 'two.txt'] as $f) { $meta->delete('local', "shared/$f"); }
    unlink("$root/shared/one.txt"); unlink("$root/shared/two.txt");
    $r = $fmB->trash('local', 'shared');
    assertTrue(!empty($r['trashed']), 'ownerless tree can be trashed');
    rmrf($root); rmrf($root . '-other');
});

echo "\n{$cyan}══ list(sort=date) reads dirs.json once ══{$reset}\n\n";

test('date-sorted list reads dirs.json once (sort + attachMetadata share it)', function () {
    [$fm, $dm, , $root] = env();
    $fm->mkdir('local', 'f1');
    up($fm, '', 'a.txt', 'a');
    $dm->adapter->reset();
    $r = $fm->list('local', '', 50, '', 'date', 'desc');
    assertEq(1, $dm->adapter->reads['dirs'] ?? 0, 'dirs.json reads');
    assertTrue(count($r['items']) >= 2, 'listed');
    $dm->adapter->reset();
    $fm->list('local', '', 50, '', 'name', 'asc');
    assertEq(1, $dm->adapter->reads['dirs'] ?? 0, 'name sort still reads it once for folder dates');
    rmrf($root); rmrf($root . '-other');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

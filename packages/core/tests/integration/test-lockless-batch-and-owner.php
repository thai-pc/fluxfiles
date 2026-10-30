<?php

/**
 * Round-1 retro fixes.
 *
 * B1  Held bulk copies (index / dirs.json / trash.json) exist only while a REAL
 *     lock backs the batch. On a lockless disk (S3/R2/SFTP, or a failed flock) a
 *     batch is read-modify-write per operation, so a concurrent writer's change
 *     is never overwritten at flush. quota.json is display-only (never read by enforcement)
 *     there.
 * N1  assertOwnsTree: the index owner may only DENY; everything else is
 *     confirmed with the per-key get() (sidecar / S3 header), as before round 1.
 *     a COPY keeps the destination's owner; a MOVE carries the source's.
 * N2  extract runs scans outside the index lock.
 * N3  A shutdown hook flushes + releases an open batch (max_execution_time).
 * N4  No second get() for keys the first lookup already answered.
 * N9  A copy gets fresh created/modified; a move keeps them.
 *
 * Usage: php tests/integration/test-lockless-batch-and-owner.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;
use League\Flysystem\Filesystem;
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
function rmrf(string $d): void { exec('rm -rf ' . escapeshellarg($d)); }

/** A local directory presented under any driver name (a non-local driver has no flock). */
final class Disks extends DiskManager
{
    private Filesystem $fs;
    public function __construct(string $root, string $driver)
    {
        parent::__construct(['x' => ['driver' => $driver, 'root' => $root, 'url' => '/s']]);
        $this->fs = new Filesystem(new LocalFilesystemAdapter($root));
    }
    public function disk(string $name): Filesystem { return $this->fs; }
}

final class CountingMeta extends StorageMetadataHandler
{
    public int $gets = 0;
    public function get(string $disk, string $key): ?array { $this->gets++; return parent::get($disk, $key); }
}

function tmpRoot(): string
{
    $r = sys_get_temp_dir() . '/ff-lockless-' . bin2hex(random_bytes(4));
    mkdir($r, 0777, true);
    return $r;
}

echo "\n{$cyan}══ B1: held copies need a real lock ══{$reset}\n\n";

foreach (['sftp', 's3-like (r2)'] as $driver) {
    test("lockless ($driver): a concurrent addTrash during a batch survives the flush", function () use ($driver) {
        $root = tmpRoot();
        $dm = new Disks($root, explode(' ', $driver)[0]);
        $a = new StorageMetadataHandler($dm); $b = new StorageMetadataHandler($dm);
        $a->addTrash('x', 'old1', ['original_key' => 'o1']);
        $a->beginIndexBatch('x');
        $a->allTrash('x');
        $b->addTrash('x', 'concurrent', ['original_key' => 'c']);
        $a->removeTrash('x', 'old1');
        $a->endIndexBatch('x');
        $ids = array_keys($b->allTrash('x'));
        assertEq(['concurrent'], $ids, 'trash ids');
        rmrf($root);
    });
}

test('lockless: a concurrent trackDir and a concurrent index write survive the batch', function () {
    $root = tmpRoot();
    $dm = new Disks($root, 'sftp');
    $a = new StorageMetadataHandler($dm); $b = new StorageMetadataHandler($dm);
    $a->beginIndexBatch('x');
    $a->trackDir('x', 'mine');
    $b->trackDir('x', 'theirs');
    $a->indexFile('x', 'mine/a.txt', ['uploaded_by' => 'a']);
    $b->indexFile('x', 'theirs/b.txt', ['uploaded_by' => 'b']);
    $a->checkpointIndexBatch('x');
    $a->trackDir('x', 'mine2');
    $a->endIndexBatch('x');
    $dirs = (string) file_get_contents($root . '/_fluxfiles/dirs.json');
    foreach (['mine', 'theirs', 'mine2'] as $d) {
        assertTrue(str_contains($dirs, '"' . $d . '"'), "dir $d lost: " . $dirs);
    }
    $idx = json_decode((string) file_get_contents($root . '/_fluxfiles/index.json'), true);
    assertTrue(isset($idx['mine/a.txt'], $idx['theirs/b.txt']), 'index entries: ' . json_encode(array_keys($idx)));
    rmrf($root);
});

test('local (real flock): the batch still holds copies and writes once', function () {
    $root = tmpRoot();
    $dm = new Disks($root, 'local');
    $a = new StorageMetadataHandler($dm);
    $a->beginIndexBatch('x');
    $a->addTrash('x', 't1', ['original_key' => 'a']);
    assertTrue(!is_file($root . '/_fluxfiles/trash.json'), 'not written inside the window');
    $a->addTrash('x', 't2', ['original_key' => 'b']);
    $a->endIndexBatch('x');
    assertEq(['t1', 't2'], array_keys(json_decode((string) file_get_contents($root . '/_fluxfiles/trash.json'), true)), 'flushed once at the end');
    rmrf($root);
});

test('quota: enforcement walks on every disk driver, even with a fresh meter cache', function () {
    foreach (['sftp', 'local'] as $driver) {
        $root = tmpRoot();
        $dm = new Disks($root, $driver);
        file_put_contents($root . '/f.txt', str_repeat('a', 100));
        $qm = new QuotaManager($dm);
        $qm->figures('x', '');                       // seeds a fresh meter cache
        $before = $qm->walks;
        for ($i = 0; $i < 3; $i++) {
            $qm->check('x', '', 10, 1, 100, 1000);   // far below every limit
        }
        assertEq(3, $qm->walks - $before, "$driver walks per check");
        rmrf($root);
    }
});

echo "\n{$cyan}══ N1 / N4: owner_only on folders ══{$reset}\n\n";

function ownerEnv(string $user, string $driver = 'local'): array
{
    $root = tmpRoot();
    $dm = new Disks($root, $driver);
    $m = new CountingMeta($dm);
    $c = new Claims($user, ['read', 'write', 'delete'], ['x'], '', 50, null, 0, false);
    $c->ownerOnly = true;
    return [new FileManager($dm, $c, $m), $m, $root, $dm];
}

test('sidecar=bob, index=alice: alice cannot delete the folder (403), same as the single file', function () {
    [$fm, $m, $root, $dm] = ownerEnv('alice');
    $dm->disk('x')->write('shared/bob.txt', 'b');
    $m->save('x', 'shared/bob.txt', ['uploaded_by' => 'bob', 'title' => 'b']);
    $m->indexFile('x', 'shared/bob.txt', ['uploaded_by' => 'alice'], true); // index-only edit
    foreach (['shared/bob.txt', 'shared'] as $path) {
        try { $fm->delete('x', $path); throw new \RuntimeException("$path deleted"); }
        catch (ApiException $e) { assertEq(403, $e->getHttpCode(), "$path"); }
    }
    assertTrue($dm->disk('x')->fileExists('shared/bob.txt'), "bob's file survived");
    rmrf($root);
});

test('index says bob, sidecar says alice: still refused early (index may deny)', function () {
    [$fm, $m, $root, $dm] = ownerEnv('alice');
    $dm->disk('x')->write('d/f.txt', 'b');
    $m->save('x', 'd/f.txt', ['uploaded_by' => 'alice']);
    $m->indexFile('x', 'd/f.txt', ['uploaded_by' => 'bob'], true);
    try { $fm->delete('x', 'd'); throw new \RuntimeException('deleted'); }
    catch (ApiException $e) { assertEq(403, $e->getHttpCode(), 'denied'); }
    rmrf($root);
});

test('own files still delete as a folder, and each key is read at most once (no double get)', function () {
    [$fm, $m, $root, $dm] = ownerEnv('alice');
    foreach (['a', 'b', 'c'] as $n) {
        $dm->disk('x')->write("mine/$n.txt", $n);
        $m->save('x', "mine/$n.txt", ['uploaded_by' => 'alice']);
    }
    $dm->disk('x')->write('mine/legacy.txt', 'l');      // unindexed, no owner
    $m->gets = 0;
    $fm->delete('x', 'mine');
    assertTrue(!$dm->disk('x')->directoryExists('mine') || !$dm->disk('x')->fileExists('mine/a.txt'), 'deleted');
    // 4 keys: assertOwnsTree reads each once. Anything else (delete bookkeeping) adds
    // at most a constant, so a double read per key (>= 8) would show.
    assertTrue($m->gets < 8, "gets=" . $m->gets);
    rmrf($root);
});

test('copyIndexEntry keeps the destination owner; a move keeps the source dates, a copy gets fresh ones', function () {
    $root = tmpRoot();
    $dm = new Disks($root, 'local');
    $m = new StorageMetadataHandler($dm);
    $fs = $dm->disk('x');
    $fs->write('s.txt', 'x'); $fs->write('d.txt', 'x');
    $m->indexFile('x', 's.txt', ['uploaded_by' => 'src', 'size' => 1, 'created' => 1000, 'modified' => 2000, 'mime' => 'text/plain'], true);
    $m->indexFile('x', 'd.txt', ['uploaded_by' => 'copier'], true);
    $m->copyIndexEntry('x', 's.txt', 'x', 'd.txt');
    $idx = json_decode((string) file_get_contents($root . '/_fluxfiles/index.json'), true);
    assertEq('copier', $idx['d.txt']['uploaded_by'], 'destination owner kept');
    assertEq(1000, $idx['d.txt']['created'], 'move/rename form keeps dates');
    $t = time();
    $m->copyIndexEntry('x', 's.txt', 'x', 'd.txt', false, true);
    $idx = json_decode((string) file_get_contents($root . '/_fluxfiles/index.json'), true);
    assertTrue($idx['d.txt']['created'] >= $t && $idx['d.txt']['modified'] >= $t, 'fresh dates');
    assertEq('text/plain', $idx['d.txt']['mime'], 'mime still carried');
    rmrf($root);
});

test('copyIndexEntry on a MOVE carries the source owner: a leftover destination entry never decides', function () {
    $root = tmpRoot();
    $dm = new Disks($root, 'local');
    $m = new StorageMetadataHandler($dm);
    $m->indexFile('x', 's.txt', ['uploaded_by' => 'alice', 'size' => 1], true);
    $m->indexFile('x', 'leftover.txt', ['uploaded_by' => 'mallory'], true);   // stale entry at the destination
    $m->copyIndexEntry('x', 's.txt', 'x', 'leftover.txt', true);
    $idx = json_decode((string) file_get_contents($root . '/_fluxfiles/index.json'), true);
    assertEq('alice', $idx['leftover.txt']['uploaded_by'], 'moved file keeps the source owner');
    rmrf($root);
});

test('FileManager::copy stamps fresh dates, rename keeps them', function () {
    [$fm, $m, $root, $dm] = ownerEnv('alice');
    $dm->disk('x')->write('o.txt', 'x');
    $m->save('x', 'o.txt', ['uploaded_by' => 'alice', 'created' => 1000, 'modified' => 2000]);
    $m->indexFile('x', 'o.txt', ['created' => 1000, 'modified' => 2000, 'size' => 1], true);
    $fm->copy('x', 'o.txt', 'c.txt');
    $fm->rename('x', 'o.txt', 'r.txt');
    $idx = json_decode((string) file_get_contents($root . '/_fluxfiles/index.json'), true);
    assertTrue(($idx['c.txt']['created'] ?? 0) > 100000, 'copy is new: ' . json_encode($idx['c.txt'] ?? null));
    assertEq(1000, $idx['r.txt']['created'] ?? null, 'rename keeps created');
    rmrf($root);
});

echo "\n{$cyan}══ N3: shutdown flush ══{$reset}\n\n";

test('an open batch is flushed and its lock released by the shutdown hook, once', function () {
    $root = tmpRoot();
    $dm = new Disks($root, 'local');
    $a = new StorageMetadataHandler($dm);
    $a->beginIndexBatch('x');
    $a->addTrash('x', 't1', ['original_key' => 'a']);
    assertTrue(!is_file($root . '/_fluxfiles/trash.json'), 'held, not yet written');
    StorageMetadataHandler::flushOpenBatchesOnShutdown();     // what a timeout fatal would trigger
    $onDisk = json_decode((string) file_get_contents($root . '/_fluxfiles/trash.json'), true);
    assertEq(['t1'], array_keys($onDisk), 'flushed');
    $fp = fopen($root . '/_fluxfiles/index.lock', 'c+');
    assertTrue(flock($fp, LOCK_EX | LOCK_NB), 'lock released');
    flock($fp, LOCK_UN); fclose($fp);
    file_put_contents($root . '/_fluxfiles/trash.json', '{}');
    StorageMetadataHandler::flushOpenBatchesOnShutdown();     // second run: nothing left to do
    assertEq('{}', file_get_contents($root . '/_fluxfiles/trash.json'), 'not written twice');
    rmrf($root);
});

echo "\n{$cyan}══ N2: extract scans run without the index lock ══{$reset}\n\n";

test('the virus scan of an extracted entry happens while the index lock is free', function () {
    $root = tmpRoot();
    $dm = new Disks($root, 'local');
    $m = new StorageMetadataHandler($dm);
    $c = new Claims('u', ['read', 'write', 'delete'], ['x'], '', 50, null, 0, false);
    $fm = new FileManager($dm, $c, $m);
    $zip = $root . '/a.zip';
    $z = new ZipArchive(); $z->open($zip, ZipArchive::CREATE);
    for ($i = 0; $i < 4; $i++) { $z->addFromString("d/f{$i}.txt", "c{$i}"); }
    $z->close();
    $free = [];
    $fm->setVirusScanner(function (string $path) use ($root, &$free): array {
        $fp = is_dir($root . '/_fluxfiles') ? fopen($root . '/_fluxfiles/index.lock', 'c+') : false;
        $free[] = $fp === false ? true : flock($fp, LOCK_EX | LOCK_NB);
        if ($fp !== false) { flock($fp, LOCK_UN); fclose($fp); }
        return ['clean' => true];
    });
    $r = $fm->extractZip('x', 'a.zip', '');
    assertEq(4, $r['extracted'], 'extracted');
    assertEq([true, true, true, true], $free, 'lock free during every scan');
    $idx = json_decode((string) file_get_contents($root . '/_fluxfiles/index.json'), true);
    assertEq(4, count(array_filter(array_keys($idx), fn ($k) => str_contains((string) $k, '/f'))), 'all indexed: ' . json_encode(array_keys($idx)));
    rmrf($root);
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

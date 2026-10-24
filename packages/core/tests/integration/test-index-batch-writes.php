<?php

/**
 * The JSON backend's disk-wide index is rewritten whole on every change, so one
 * operation must write it once: an upload's hash + metadata, a rename's save +
 * delete, a folder delete's per-child removals. Also: the index lock is
 * re-entrant (a nested release no longer drops the outer holder's lock).
 *
 * Usage:
 *   php tests/integration/test-index-batch-writes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

/** Local adapter counting writes of the metadata index. */
final class IndexWriteCounter implements FilesystemAdapter
{
    public int $indexWrites = 0;
    public function __construct(private LocalFilesystemAdapter $in) {}
    private function seen(string $path): void { if ($path === '_fluxfiles/index.json') { $this->indexWrites++; } }
    public function fileExists(string $path): bool { return $this->in->fileExists($path); }
    public function directoryExists(string $path): bool { return $this->in->directoryExists($path); }
    public function write(string $path, string $contents, Config $config): void { $this->seen($path); $this->in->write($path, $contents, $config); }
    public function writeStream(string $path, $contents, Config $config): void { $this->seen($path); $this->in->writeStream($path, $contents, $config); }
    public function read(string $path): string { return $this->in->read($path); }
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

final class CountingDisks extends DiskManager
{
    public IndexWriteCounter $adapter;
    private Filesystem $fs;
    public function __construct(string $root)
    {
        parent::__construct(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
        $this->adapter = new IndexWriteCounter(new LocalFilesystemAdapter($root));
        $this->fs = new Filesystem($this->adapter);
    }
    public function disk(string $name): Filesystem { return $this->fs; }
}

function env(): array
{
    $root = sys_get_temp_dir() . '/ff-idxbatch-' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $dm = new CountingDisks($root);
    $meta = new StorageMetadataHandler($dm);
    $c = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $c->dedupeUploads = true;
    return [new FileManager($dm, $c, $meta), $dm, $meta, $root];
}

function up(FileManager $fm, string $path, string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ffb');
    file_put_contents($tmp, $bytes);
    try { return $fm->upload('local', dirname($path) === '.' ? '' : dirname($path), ['name' => basename($path), 'size' => strlen($bytes), 'tmp_name' => $tmp]); }
    finally { @unlink($tmp); }
}

function index(string $root): array
{
    return json_decode((string) file_get_contents("{$root}/_fluxfiles/index.json"), true) ?: [];
}

echo "\n{$cyan}══ One index write per operation ══{$reset}\n\n";

test('upload: hash + metadata in one write, both recorded', function () {
    [$fm, $dm, , $root] = env();
    $dm->adapter->indexWrites = 0;
    up($fm, 'a.txt', 'hello');
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    $e = index($root)['a.txt'] ?? [];
    assertTrue(($e['file_hash'] ?? '') === hash('sha256', 'hello') && ($e['uploaded_by'] ?? '') === 'u', json_encode($e));
});

test('rename: save + delete in one write; the index follows the file', function () {
    [$fm, $dm, , $root] = env();
    up($fm, 'a.txt', 'one');
    $dm->adapter->indexWrites = 0;
    $fm->rename('local', 'a.txt', 'b.txt');
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    $idx = index($root);
    assertTrue(!isset($idx['a.txt']) && ($idx['b.txt']['uploaded_by'] ?? '') === 'u', json_encode($idx));
});

test('deleting a folder of 25 files rewrites the index once, not 25 times', function () {
    [$fm, $dm, $meta, $root] = env();
    for ($i = 0; $i < 25; $i++) {
        up($fm, "box/f{$i}.txt", "x{$i}");
    }
    up($fm, 'keep.txt', 'keep');
    $dm->adapter->indexWrites = 0;
    $n = $meta->deleteChildren('local', 'box');
    assertTrue($n === 25, "removed {$n}");
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    $idx = index($root);
    assertTrue(count($idx) === 1 && isset($idx['keep.txt']), json_encode(array_keys($idx)));
});

test('duplicate detection still sees a hash written inside the batch', function () {
    [$fm] = env();
    up($fm, 'a.txt', 'same bytes');
    $r = up($fm, 'b.txt', 'same bytes');
    assertTrue(($r['duplicate'] ?? false) === true && $r['key'] === 'a.txt', 'reported as a duplicate of a.txt: ' . json_encode($r));
});

test('a failure inside a batch still writes what was applied and frees the lock', function () {
    [, $dm, $meta, $root] = env();
    try {
        $meta->withIndexBatch('local', function () use ($meta) {
            $meta->save('local', 'x.txt', ['title' => 'X']);
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) {
    }
    assertTrue((index($root)['x.txt']['title'] ?? '') === 'X', 'applied change written');
    // Lock freed: a fresh handler can take it again without blocking.
    $other = new StorageMetadataHandler($dm);
    $other->save('local', 'y.txt', ['title' => 'Y']);
    assertTrue(isset(index($root)['y.txt']), 'second writer got the lock');
});

test('nested lock: an inner release no longer drops the outer holder\'s lock', function () {
    [, , $meta, $root] = env();
    $meta->withIndexBatch('local', function () use ($meta, $root) {
        $meta->save('local', 'a.txt', ['title' => 'A']); // takes + releases the lock inside
        $fp = fopen("{$root}/_fluxfiles/index.lock", 'c+');
        $free = flock($fp, LOCK_EX | LOCK_NB);
        fclose($fp);
        assertTrue(!$free, 'outer lock still held after the nested save');
    });
});

function png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    ob_start(); imagepng($im); return (string) ob_get_clean();
}

function listed(FileManager $fm, string $name): array
{
    foreach ($fm->list('local', '')['items'] ?? $fm->list('local', '') as $it) {
        if (($it['key'] ?? '') === $name) { return $it; }
    }
    return [];
}

test('writeScopedFile: a NEW image gets mime/size/dims/owner/hash in ONE index write', function () {
    [$fm, $dm, , $root] = env();
    $bytes = png(40, 30);
    $dm->adapter->indexWrites = 0;
    $fm->writeScopedFile('local', $fm->validateUserPath('out.png'), $bytes);
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    $it = listed($fm, 'out.png');
    assertTrue(($it['mime'] ?? '') === 'image/png' && ($it['width'] ?? 0) === 40 && ($it['height'] ?? 0) === 30, json_encode($it));
    assertTrue(($it['meta']['uploaded_by'] ?? '') === 'u' && ($it['meta']['size'] ?? 0) === strlen($bytes), json_encode($it['meta'] ?? null));
    assertTrue((index($root)['out.png']['file_hash'] ?? '') === hash('sha256', $bytes), 'hash indexed');
    $r = up($fm, 'dup.png', $bytes);
    assertTrue(($r['duplicate'] ?? false) === true && $r['key'] === 'out.png', 'duplicate detection sees it: ' . json_encode($r));
});

test('writeScopedFile: an overwrite keeps owner + user metadata, refreshes size/dims', function () {
    [$fm, , $meta, $root] = env();
    up($fm, 'p.png', png(10, 10));
    $meta->save('local', 'p.png', ['uploaded_by' => 'alice', 'title' => 'My title', 'alt_text' => 'alt', 'tags' => 'a,b']);
    $new = png(64, 48);
    $fm->writeScopedFile('local', $fm->validateUserPath('p.png'), $new);
    $m = array_merge($meta->get('local', 'p.png') ?? [], listed($fm, 'p.png')['meta'] ?? [], index($root)['p.png'] ?? []);
    assertTrue(($m['uploaded_by'] ?? '') === 'alice', 'owner kept: ' . json_encode($m));
    assertTrue(($m['title'] ?? '') === 'My title' && ($m['alt_text'] ?? '') === 'alt' && ($m['tags'] ?? '') === 'a,b', json_encode($m));
    assertTrue(($m['width'] ?? 0) === 64 && ($m['height'] ?? 0) === 48 && ($m['size'] ?? 0) === strlen($new), json_encode($m));
});

test('writeScopedStream: new image recorded like an upload; overwrite keeps owner', function () {
    [$fm, , $meta, $root] = env();
    $mk = function (string $b) { $s = fopen('php://temp', 'r+'); fwrite($s, $b); rewind($s); return $s; };
    $fm->writeScopedStream('local', $fm->validateUserPath('s.png'), $mk(png(20, 12)));
    $m = array_merge($meta->get('local', 's.png') ?? [], index($root)['s.png'] ?? []);
    assertTrue(($m['uploaded_by'] ?? '') === 'u' && ($m['width'] ?? 0) === 20 && ($m['mime'] ?? '') === 'image/png', json_encode($m));
    $meta->save('local', 's.png', ['uploaded_by' => 'bob']);
    $fm->writeScopedStream('local', $fm->validateUserPath('s.png'), $mk(png(8, 8)));
    $m = array_merge($meta->get('local', 's.png') ?? [], index($root)['s.png'] ?? []);
    assertTrue(($m['uploaded_by'] ?? '') === 'bob' && ($m['width'] ?? 0) === 8, json_encode($m));
});

test('attachDerivedFile keeps the derived file\'s own dims/size, inherits user metadata', function () {
    [$fm, , $meta, $root] = env();
    up($fm, 'src.png', png(10, 10));
    $meta->save('local', 'src.png', ['title' => 'Src']);
    $dst = png(30, 30);
    $fm->writeScopedFile('local', 'dst.png', $dst);
    $fm->attachDerivedFile('local', 'src.png', 'dst.png');
    $m = array_merge($meta->get('local', 'dst.png') ?? [], index($root)['dst.png'] ?? []);
    assertTrue(($m['title'] ?? '') === 'Src', json_encode($m));
    assertTrue(($m['width'] ?? 0) === 30 && ($m['size'] ?? 0) === strlen($dst), 'not clobbered by source: ' . json_encode($m));
});

test('withIndexBatch: N file writes + metadata copies are one index write; returns the closure value', function () {
    [$fm, $dm, , $root] = env();
    $mk = function (string $b) { $s = fopen('php://temp', 'r+'); fwrite($s, $b); rewind($s); return $s; };
    $dm->adapter->indexWrites = 0;
    $ret = $fm->withIndexBatch('local', function () use ($fm, $mk) {
        for ($i = 0; $i < 5; $i++) {
            $fm->writeScopedStream('local', $fm->validateUserPath("wb{$i}.txt"), $mk("b{$i}"));
            $fm->attachCopiedFile('local', "wb{$i}.txt", 'local', "wb{$i}-copy.txt");
        }
        return 'done';
    });
    assertTrue($ret === 'done', 'closure value returned');
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    $idx = index($root);
    assertTrue(isset($idx['wb0.txt'], $idx['wb4.txt']) && ($idx['wb4.txt']['uploaded_by'] ?? '') === 'u', json_encode(array_keys($idx)));
});

test('withIndexBatch: nested batches still write once, after the outermost ends', function () {
    [$fm, $dm, , $root] = env();
    $dm->adapter->indexWrites = 0;
    $fm->withIndexBatch('local', function () use ($fm, $dm) {
        up($fm, 'n1.txt', 'one');            // has its own inner batch
        $fm->withIndexBatch('local', function () use ($fm) { up($fm, 'n2.txt', 'two'); });
        assertTrue($dm->adapter->indexWrites === 0, 'nothing flushed before the outer batch ends');
    });
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    $idx = index($root);
    assertTrue(isset($idx['n1.txt'], $idx['n2.txt']), json_encode(array_keys($idx)));
});

test('withIndexBatch: a throw still flushes what landed and releases the lock', function () {
    [$fm, $dm, , $root] = env();
    $dm->adapter->indexWrites = 0;
    $caught = false;
    try {
        $fm->withIndexBatch('local', function () use ($fm) {
            up($fm, 't1.txt', 'one');
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) { $caught = $e->getMessage() === 'boom'; }
    assertTrue($caught, 'exception propagates unchanged');
    assertTrue(isset(index($root)['t1.txt']), 'index flushed for the file that landed');
    assertTrue($dm->adapter->indexWrites === 1, "index writes: {$dm->adapter->indexWrites}");
    // Lock released: a second handler can take the exclusive index lock without blocking.
    $fp = fopen("{$root}/_fluxfiles/index.lock", 'c+');
    assertTrue(flock($fp, LOCK_EX | LOCK_NB), 'index.lock is free after the throw');
    fclose($fp);
    // ...and the batch depth was reset: a later plain upload writes immediately (1 write).
    $dm->adapter->indexWrites = 0;
    up($fm, 't2.txt', 'two');
    assertTrue($dm->adapter->indexWrites === 1 && isset(index($root)['t2.txt']), 'no stuck batch depth');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n\n";
exit($failed > 0 ? 1 : 0);

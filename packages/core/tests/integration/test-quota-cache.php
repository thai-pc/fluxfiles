<?php

/**
 * Quota / max_files enforcement is a LIVE walk (one walk per write, never a cached
 * figure); only the /quota meter may be served from `_fluxfiles/quota.json`.
 * Covers the review's B1 (meter walk racing a write), B2 (writes through another
 * prefix) and B3 (long write invisible to later checks) scenarios: none may admit
 * an over-limit write.
 *
 * Usage:
 *   php tests/integration/test-quota-cache.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\QuotaManager;
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
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function ok($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function throwsCode(callable $fn, string $code): void
{
    try { $fn(); } catch (ApiException $e) { eq($code, $e->getErrorCode()); return; }
    throw new \RuntimeException("expected {$code}, nothing thrown");
}

/** Local adapter that counts recursive listings — the unit of "a walk". */
final class CountingAdapter implements FilesystemAdapter
{
    public int $deepLists = 0;
    public function __construct(private LocalFilesystemAdapter $in) {}
    public function fileExists(string $p): bool { return $this->in->fileExists($p); }
    public function directoryExists(string $p): bool { return $this->in->directoryExists($p); }
    public function write(string $p, string $c, Config $cfg): void { $this->in->write($p, $c, $cfg); }
    public function writeStream(string $p, $c, Config $cfg): void { $this->in->writeStream($p, $c, $cfg); }
    public function read(string $p): string { return $this->in->read($p); }
    public function readStream(string $p) { return $this->in->readStream($p); }
    public function delete(string $p): void { $this->in->delete($p); }
    public function deleteDirectory(string $p): void { $this->in->deleteDirectory($p); }
    public function createDirectory(string $p, Config $cfg): void { $this->in->createDirectory($p, $cfg); }
    public function setVisibility(string $p, string $v): void { $this->in->setVisibility($p, $v); }
    public function visibility(string $p): FileAttributes { return $this->in->visibility($p); }
    public function mimeType(string $p): FileAttributes { return $this->in->mimeType($p); }
    public function lastModified(string $p): FileAttributes { return $this->in->lastModified($p); }
    public function fileSize(string $p): FileAttributes { return $this->in->fileSize($p); }
    public function listContents(string $p, bool $deep): iterable { if ($deep) { $this->deepLists++; } return $this->in->listContents($p, $deep); }
    public function move(string $s, string $d, Config $cfg): void { $this->in->move($s, $d, $cfg); }
    public function copy(string $s, string $d, Config $cfg): void { $this->in->copy($s, $d, $cfg); }
}
final class CountingDisks extends DiskManager
{
    public CountingAdapter $adapter;
    private Filesystem $fs;
    public function __construct(string $root)
    {
        parent::__construct(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
        $this->adapter = new CountingAdapter(new LocalFilesystemAdapter($root));
        $this->fs = new Filesystem($this->adapter);
    }
    public function disk(string $name): Filesystem { return $this->fs; }
}

/** @return array{0:FileManager,1:QuotaManager,2:CountingDisks,3:string} */
function make(int $maxMb, int $maxFiles, string $prefix = '', ?string $root = null): array
{
    $root ??= sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    @mkdir($root, 0777, true);
    $dm = new CountingDisks($root);
    $qm = new QuotaManager($dm);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], $prefix, 50, null, $maxMb, false);
    $claims->maxFiles = $maxFiles;
    $claims->allowCodeEdit = true;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    $fm->setQuotaManager($qm);
    return [$fm, $qm, $dm, $root];
}
function up(FileManager $fm, string $name, int $size = 10, string $dir = ''): array
{
    $t = tempnam(sys_get_temp_dir(), 'fq');
    file_put_contents($t, str_repeat('x', $size));
    try { return $fm->upload('local', $dir, ['name' => $name, 'size' => $size, 'tmp_name' => $t]); }
    finally { @unlink($t); }
}
function truth(string $root, string $sub = ''): array
{
    $b = 0; $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . ($sub !== '' ? '/' . $sub : ''), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $b += $f->getSize();
            if (strpos($f->getPathname(), '_fluxfiles') === false && strpos($f->getPathname(), '_variants') === false) { $n++; }
        }
    }
    return ['bytes' => $b, 'files' => $n];
}

echo "\n{$cyan}══ Quota: live enforcement, cached meter ══{$reset}\n\n";

/** Walks, then lets $during run after the listing (a write landing mid-walk). */
final class RacyQM extends QuotaManager
{
    public ?\Closure $during = null;
    public function walk(string $disk, string $prefix): array
    {
        $w = parent::walk($disk, $prefix);
        if ($this->during !== null) { $f = $this->during; $this->during = null; $f(); }
        return $w;
    }
}

function put(string $root, string $rel, int $bytes): void
{
    @mkdir(dirname($root . '/' . $rel), 0777, true);
    file_put_contents($root . '/' . $rel, str_repeat('x', $bytes));
}

test('upload with max_storage + max_files does ONE walk (was two)', function () {
    [$fm, $qm] = make(100, 1000);
    up($fm, 'a.txt');
    $qm->walks = 0;
    up($fm, 'b.txt');
    eq(1, $qm->walks, 'walks for one upload');
});

test('every upload walks live, however far from the limits (no cached admit)', function () {
    [$fm, $qm, $dm] = make(100, 1000);
    up($fm, 'a.txt');
    $dm->adapter->deepLists = 0;
    for ($i = 0; $i < 5; $i++) { up($fm, "b$i.txt"); }
    eq(5, $dm->adapter->deepLists, 'recursive listings for 5 uploads');
});

test('no limit configured: nothing is walked', function () {
    [$fm, , $dm] = make(0, 0);
    $dm->adapter->deepLists = 0;
    up($fm, 'a.txt');
    eq(0, $dm->adapter->deepLists);
});

test('/quota (getQuotaInfo) is served from the cache: first call walks, then none', function () {
    [$fm, $qm, $dm] = make(100, 0);
    up($fm, 'a.txt', 1000);
    $dm->adapter->deepLists = 0;
    $a = $qm->getQuotaInfo('local', '', 100);
    $b = $qm->getQuotaInfo('local', '', 100);
    $c = $qm->getQuotaInfo('local', '', 100);
    eq(1, $dm->adapter->deepLists, 'one walk fills the meter cache');
    eq(false, $a['cached']);
    eq(true, $b['cached']);
    eq($a['used_bytes'], $c['used_bytes']);
    ok($a['used_bytes'] >= 1000, 'used_bytes');
});

test('writes never touch or consult the meter cache', function () {
    [$fm, $qm, $dm, $root] = make(0, 5);
    $qm->getQuotaInfo('local', '', 0);                         // cache: 0 files
    $before = file_get_contents($root . '/_fluxfiles/quota.json');
    for ($i = 0; $i < 5; $i++) { up($fm, "f$i.txt"); }          // fills the limit
    eq($before, file_get_contents($root . '/_fluxfiles/quota.json'), 'quota.json untouched by writes');
    throwsCode(fn () => up($fm, 'over.txt'), 'too_many_files'); // enforcement saw the 5 real files
});

test('a stale-LOW meter cache admits nothing (cache says 0, disk is full)', function () {
    [$fm, $qm, , $root] = make(1, 0);                           // 1 MB
    $qm->getQuotaInfo('local', '', 1);                          // cache: ~0 B
    put($root, 'big.bin', 1024 * 1024 - 100);
    throwsCode(fn () => up($fm, 'x.bin', 1000), 'quota_exceeded');
});

test('B1: a meter walk overtaken by a write cannot loosen enforcement', function () {
    // limit 1 MB, 0.1 MB used; while the meter walks, 0.8 MB lands.
    $root = sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    put($root, 't/a.bin', 100 * 1024);
    $dm = new CountingDisks($root);
    $meter = new RacyQM($dm);
    $meter->during = fn () => put($root, 't/big.bin', 800 * 1024);
    $meter->getQuotaInfo('local', 't', 1);                      // meter now says ~0.1 MB (stale-low)
    $enf = new QuotaManager($dm);
    throwsCode(fn () => $enf->check('local', 't', 700 * 1024, 1, 1, 0), 'quota_exceeded');
    $enf->check('local', 't', 50 * 1024, 1, 1, 0);              // what really fits still does
});

test('B2: writes through a child / parent prefix are seen by the other prefix', function () {
    $root = sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    put($root, 'org/a.bin', 100);
    $dm = new CountingDisks($root);
    $parent = new QuotaManager($dm);
    $parent->getQuotaInfo('local', 'org', 1);                   // parent's meter: 100 B
    put($root, 'org/inbox/x.bin', 1024 * 1024);                 // a child token / portal writes 1 MB
    throwsCode(fn () => $parent->check('local', 'org', 800 * 1024, 1, 1, 0), 'quota_exceeded');
    // and the reverse: a parent write into the child's tree
    $child = new QuotaManager($dm);
    $child->getQuotaInfo('local', 'org/inbox', 2);
    put($root, 'org/inbox/y.bin', 1024 * 1024);
    throwsCode(fn () => $child->check('local', 'org/inbox', 1024 * 1024 + 1, 1, 2, 0), 'quota_exceeded');
});

test('B3: bytes of a long write (extract) are counted by later checks as they land', function () {
    $root = sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    @mkdir($root, 0777, true);
    $dm = new CountingDisks($root);
    $qm = new QuotaManager($dm);
    $qm->check('local', '', 800 * 1024, 0, 1, 0);               // "extract" of 0.8 MB admitted...
    put($root, 'ex/part1.bin', 500 * 1024);                     // ...and inflating progressively
    throwsCode(fn () => $qm->check('local', '', 600 * 1024, 1, 1, 0), 'quota_exceeded');
    $qm->check('local', '', 400 * 1024, 1, 1, 0);
});

test('30 uploads at 80/100 files: every check sees the real count, exactly 20 get in', function () {
    $root = sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    for ($i = 0; $i < 80; $i++) { put($root, "t/f$i", 1); }
    $qm = new QuotaManager(new CountingDisks($root));
    $qm->getQuotaInfo('local', 't', 0);                         // meter says 80
    $admitted = 0;
    for ($i = 0; $i < 30; $i++) {
        try { $qm->check('local', 't', 1, 1, 0, 100); } catch (ApiException) { continue; }
        $admitted++;
        put($root, "t/n$i", 1);                                // sequential model: each lands before the next check
    }
    eq(20, $admitted, 'admitted until the cap');
    eq(100, truth($root, 't')['files'], 'files on disk');
});

test('an expired meter cache is rebuilt with one walk', function () {
    $root = sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    put($root, 'x.bin', 500);
    $dm = new CountingDisks($root);
    $qm = new QuotaManager($dm);
    eq(500, $qm->getQuotaInfo('local', '', 100)['used_bytes']);
    $c = json_decode(file_get_contents($root . '/_fluxfiles/quota.json'), true);
    $c['computed_at'] = time() - 3600;
    file_put_contents($root . '/_fluxfiles/quota.json', json_encode($c));
    put($root, 'y.bin', 500);
    $dm->adapter->deepLists = 0;
    $info = $qm->getQuotaInfo('local', '', 100);
    ok($info['used_bytes'] >= 1000, 'rebuilt from a walk');
    eq(1, $dm->adapter->deepLists, 'one walk');
});

test('meter TTL 0 = the meter walks too and writes no cache file', function () {
    $root = sys_get_temp_dir() . '/fxqc-' . bin2hex(random_bytes(4));
    put($root, 'x.bin', 10);
    $dm = new CountingDisks($root);
    $qm = new QuotaManager($dm, 0);
    $qm->getQuotaInfo('local', '', 0);
    $qm->getQuotaInfo('local', '', 0);
    eq(2, $dm->adapter->deepLists);
    ok(!is_file($root . '/_fluxfiles/quota.json'), 'no cache file');
});

test('the usage dashboard walk refreshes the meter cache', function () {
    [$fm, $qm, $dm] = make(100, 0);
    up($fm, 'a.txt', 1000);
    $b = $qm->getUsageBreakdown('local', '', 10, 1);
    $dm->adapter->deepLists = 0;
    $m = $qm->getQuotaInfo('local', '', 100);
    eq(0, $dm->adapter->deepLists, 'meter served from the dashboard walk');
    eq($b['raw_total'], $m['used_bytes']);
});

test('exact at limit-1 even with out-of-band files the meter never saw', function () {
    [$fm, , , $root] = make(0, 10);
    for ($i = 0; $i < 8; $i++) { up($fm, "t$i.txt"); }
    file_put_contents($root . '/oob.txt', 'x');
    $up = fn () => up($fm, 'n' . bin2hex(random_bytes(2)) . '.txt');
    $up();
    throwsCode($up, 'too_many_files');
    eq(10, truth($root)['files']);
});

test('tenant prefixes keep separate meter caches', function () {
    [$fmA, $qm, , $root] = make(0, 3, 'ta');
    [$fmB] = make(0, 3, 'tb', $root);
    for ($i = 0; $i < 3; $i++) { up($fmA, "a$i.txt"); }
    throwsCode(fn () => up($fmA, 'a3.txt'), 'too_many_files');
    up($fmB, 'b0.txt');
    $qm->getQuotaInfo('local', 'ta', 0);
    $qm->getQuotaInfo('local', 'tb', 0);
    ok(is_file($root . '/ta/_fluxfiles/quota.json') && is_file($root . '/tb/_fluxfiles/quota.json'), 'one cache file per prefix');
});

test('assertQuota / assertFileCount keep working standalone', function () {
    [, $qm, , $root] = make(0, 0);
    file_put_contents($root . '/a.bin', str_repeat('x', 1024 * 1024));
    throwsCode(fn () => $qm->assertQuota('local', '', 10, 1), 'quota_exceeded');
    $qm->assertQuota('local', '', -10, 2);
    throwsCode(fn () => $qm->assertFileCount('local', '', 1, 1), 'too_many_files');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

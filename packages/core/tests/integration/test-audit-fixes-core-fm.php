<?php

/**
 * Regression tests for the 2026-10-23 free-core audit (FileManager /
 * OptimizeModule / UrlImporter): items B1–B5, B8–B12, B14 and the module-facing
 * N8/N12/N14. Each block names the finding it pins; every one of them fails on
 * the code before the fix.
 *
 * Usage: php packages/core/tests/integration/test-audit-fixes-core-fm.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\AiTagger;
use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\ImageOptimizer;
use FluxFiles\OptimizeModule;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void {
    if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a));
}
function expectCode(string $code, callable $fn): void {
    try {
        $fn();
    } catch (ApiException $e) {
        assertEqual($code, $e->getErrorCode(), 'error code');
        return;
    }
    throw new \RuntimeException("expected {$code}, nothing thrown");
}

/**
 * Fresh FileManager on two temp local disks. Returns
 * [fm, meta, root, claims, dm, root2]. $setup tweaks the claims before the
 * FileManager is built (it reads `variants` in its constructor).
 */
function env(string $prefix = '', array $setup = [], bool $quota = false): array {
    $root = sys_get_temp_dir() . '/ff-afcf-' . uniqid();
    @mkdir($root, 0777, true);
    @mkdir($root . '-2', 0777, true);
    $dm = new DiskManager([
        'local'  => ['driver' => 'local', 'root' => $root, 'url' => '/storage'],
        'local2' => ['driver' => 'local', 'root' => $root . '-2', 'url' => '/storage2'],
    ]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local', 'local2'], $prefix, 50, null, 0, false);
    foreach ($setup as $k => $v) {
        $claims->{$k} = $v;
    }
    $fm = new FileManager($dm, $claims, $meta);
    if ($quota) {
        $fm->setQuotaManager(new QuotaManager($dm));
    }
    return [$fm, $meta, $root, $claims, $dm, $root . '-2'];
}

function up(FileManager $fm, string $dir, string $name, string $bytes, bool $force = false, string $disk = 'local'): array {
    $tmp = tempnam(sys_get_temp_dir(), 'afcf');
    file_put_contents($tmp, $bytes);
    try {
        return $fm->upload($disk, $dir, ['name' => $name, 'size' => strlen($bytes), 'tmp_name' => $tmp, 'type' => 'application/octet-stream', 'error' => 0], $force);
    } finally {
        @unlink($tmp);
    }
}

function hold(StorageMetadataHandler $meta, string $path, bool $isDir = false): void {
    $meta->addHold('local', bin2hex(random_bytes(4)), [
        'path' => trim($path, '/'), 'is_dir' => $isDir, 'disk' => 'local',
        'reason' => 'test hold', 'placed_by' => 'admin', 'placed_at' => time(),
        'released_at' => null, 'released_by' => null, 'release_reason' => null,
    ]);
}

function png(int $w = 64, int $h = 48): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 40, 40));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return (string) ob_get_clean();
}

/** A noisy JPEG that WebP compresses smaller (same as test-optimize.php). */
function noisyJpeg(string $path, int $w = 400, int $h = 300): void {
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < $w * $h / 40; $i++) {
        imagefilledellipse($im, random_int(0, $w), random_int(0, $h), random_int(5, 60), random_int(5, 60),
            imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    imagejpeg($im, $path, 92);
    imagedestroy($im);
}

echo "\n{$cyan}══ 2026-10-23 free-core audit: FileManager / Optimize / UrlImporter ══{$reset}\n\n";

// ── B1: name checks run on the FINAL key ────────────────────────────────────
test('B1: upload name=".." + path="shell.php" is refused, nothing written', function () {
    [$fm, , $root] = env();
    foreach (['..', '.', ''] as $name) {
        expectCode('name_invalid', fn () => up($fm, 'shell.php', $name, '<?php system($_GET[1]);'));
    }
    expectCode('name_invalid', fn () => up($fm, 'sub/.htaccess', '..', 'SetHandler x'));
    assertTrue(!file_exists($root . '/shell.php') && !file_exists($root . '/sub/.htaccess'), 'no file landed');
});

test('B1: same collapse under a tenant prefix', function () {
    [$fm, , $root] = env('tenant');
    expectCode('name_invalid', fn () => up($fm, 'x.phtml', '..', 'x'));
    assertTrue(!file_exists($root . '/tenant/x.phtml'), 'no file landed');
});

test('B1: validateUploadName (presign/chunk/tus) + rename refuse a dot-only name', function () {
    [$fm] = env();
    expectCode('name_invalid', fn () => $fm->validateUploadName('..', 1));
    expectCode('name_invalid', fn () => $fm->validateUploadName('.', 1));
    up($fm, '', 'a.txt', 'a');
    expectCode('name_invalid', fn () => $fm->rename('local', 'a.txt', '..'));
});

test('B1: writeScopedFile refuses the tenant root itself as a file target', function () {
    [$fm] = env('tenant');
    expectCode('name_invalid', fn () => $fm->writeScopedFile('local', 'tenant', 'x'));
});

test('B1: ordinary uploads still work', function () {
    [$fm, , $root] = env('tenant');
    $r = up($fm, 'docs', 'report.pdf', '%PDF');
    assertEqual('docs/report.pdf', $r['key']);
    assertTrue(is_file($root . '/tenant/docs/report.pdf'), 'written');
});

// ── B2: restore honours legal holds ─────────────────────────────────────────
test('B2: a held trashed file cannot be restored (original key or a new path)', function () {
    [$fm, $meta, $root] = env();
    up($fm, 'docs', 'a.txt', 'evidence');
    $id = $fm->trash('local', 'docs/a.txt')['trash_id'];
    hold($meta, 'docs/a.txt');
    expectCode('legal_hold_active', fn () => $fm->restore('local', $id));
    expectCode('legal_hold_active', fn () => $fm->restore('local', $id, 'elsewhere/a.txt'));
    assertTrue(!file_exists($root . '/elsewhere/a.txt'), 'not relocated out of the hold');
});

test('B2: a trashed folder containing a held file cannot be restored', function () {
    [$fm, $meta] = env();
    up($fm, 'case', 'b.txt', 'evidence');
    $id = $fm->trash('local', 'case')['trash_id'];
    hold($meta, 'case/b.txt');
    expectCode('legal_hold_active', fn () => $fm->restore('local', $id, 'moved'));
});

// ── B3: no clean URL for a preview-only token ───────────────────────────────
test('B3: allow_download=false → no url/variants from upload/rename/crossCopy/crossMove/crop', function () {
    [$fm] = env('', ['allowDownload' => false]);
    $r = up($fm, '', 'a.txt', 'a');
    assertEqual(null, $r['url'], 'upload url');
    $r = $fm->rename('local', 'a.txt', 'b.txt');
    assertEqual(null, $r['url'], 'rename url');
    $r = $fm->crossCopy('local', 'b.txt', 'local2', 'c.txt');
    assertEqual(null, $r['url'], 'crossCopy url');
    $r = $fm->crossMove('local', 'b.txt', 'local2', 'd.txt');
    assertEqual(null, $r['url'], 'crossMove url');
    up($fm, '', 'pic.png', png());
    $r = $fm->cropImage('local', 'pic.png', 0, 0, 10, 10, 'pic-crop.png');
    assertEqual(null, $r['url'], 'crop url');
    assertEqual(null, $r['variants'], 'crop variants');
});

test('B3: allow_download=true still returns the url', function () {
    [$fm] = env();
    up($fm, '', 'a.txt', 'a');
    $r = $fm->rename('local', 'a.txt', 'b.txt');
    assertEqual('/storage/b.txt', $r['url'], 'rename url');
});

// ── B4: restore enforces quota + max_files ──────────────────────────────────
test('B4: restore past max_storage → quota_exceeded (trash sits outside the prefix)', function () {
    [$fm] = env('tenant', ['maxStorageMb' => 1], true);
    up($fm, '', 'a.bin', random_bytes(700 * 1024));
    $id = $fm->trash('local', 'a.bin')['trash_id'];
    up($fm, '', 'b.bin', random_bytes(700 * 1024));
    expectCode('quota_exceeded', fn () => $fm->restore('local', $id));
});

test('B4: restore past max_files → too_many_files', function () {
    [$fm] = env('tenant', ['maxFiles' => 1], true);
    up($fm, '', 'a.txt', 'a');
    $id = $fm->trash('local', 'a.txt')['trash_id'];
    up($fm, '', 'b.txt', 'b');
    expectCode('too_many_files', fn () => $fm->restore('local', $id));
});

test('B4: folder restore counts its payload too', function () {
    [$fm] = env('tenant', ['maxFiles' => 2], true);
    up($fm, 'dir', 'a.txt', 'a');
    up($fm, 'dir', 'b.txt', 'b');
    $id = $fm->trash('local', 'dir')['trash_id'];
    up($fm, '', 'c.txt', 'c');
    expectCode('too_many_files', fn () => $fm->restore('local', $id));
});

test('B4: no prefix → trashed bytes are already counted, restore is not double-charged', function () {
    [$fm] = env('', ['maxStorageMb' => 1], true);
    up($fm, '', 'a.bin', random_bytes(700 * 1024));
    $id = $fm->trash('local', 'a.bin')['trash_id'];
    assertEqual(true, $fm->restore('local', $id)['restored'], 'restored');
});

// ── B5: retention acts on the listed key ────────────────────────────────────
test('B5: retention deletes the OLD file, not the newer one its outKey collides with', function () {
    [$fm, , $root] = env('t', ['retentionRules' => [['path' => '', 'days' => 1, 'action' => 'delete']]]);
    @mkdir($root . '/t/t', 0777, true);
    file_put_contents($root . '/t/t/a.txt', 'old');
    touch($root . '/t/t/a.txt', time() - 10 * 86400);
    file_put_contents($root . '/t/a.txt', 'new');
    $report = $fm->runRetention('local', false);
    assertEqual(1, $report['acted'], 'acted');
    assertTrue(!file_exists($root . '/t/t/a.txt'), 'old file removed');
    assertEqual('new', @file_get_contents($root . '/t/a.txt'), 'newer file untouched');
});

test('B5: retention trash path also uses the real key', function () {
    [$fm, , $root] = env('t', ['retentionRules' => [['path' => '', 'days' => 1, 'action' => 'trash']]]);
    @mkdir($root . '/t/t', 0777, true);
    file_put_contents($root . '/t/t/a.txt', 'old');
    touch($root . '/t/t/a.txt', time() - 10 * 86400);
    file_put_contents($root . '/t/a.txt', 'new');
    $fm->runRetention('local', false);
    assertTrue(!file_exists($root . '/t/t/a.txt'), 'old file trashed');
    assertEqual('new', @file_get_contents($root . '/t/a.txt'), 'newer file untouched');
});

// ── B8 / N8: show_hidden=false enforced beyond the listing filter ───────────
function hiddenEnv(bool $showHidden = false): array {
    $e = env('', ['showHidden' => $showHidden]);
    file_put_contents($e[2] . '/.env', 'SECRET=1');
    @mkdir($e[2] . '/.ssh', 0777, true);
    file_put_contents($e[2] . '/.ssh/id_rsa', 'KEY');
    file_put_contents($e[2] . '/a.txt', 'a');
    return $e;
}

test('B8: list(.ssh) is refused', function () {
    [$fm] = hiddenEnv();
    expectCode('path_denied', fn () => $fm->list('local', '.ssh'));
});

test('B8: copy/rename/move/crossCopy/crossMove can\'t expose a dotfile', function () {
    [$fm, , $root] = hiddenEnv();
    // `leak.env` keeps the `.env` extension, so only the hidden rule can refuse it.
    expectCode('path_denied', fn () => $fm->copy('local', '.env', 'leak.env'));
    expectCode('path_denied', fn () => $fm->rename('local', '.env', 'leak.env'));
    expectCode('path_denied', fn () => $fm->move('local', '.ssh/id_rsa', 'id_rsa'));
    expectCode('path_denied', fn () => $fm->crossCopy('local', '.env', 'local2', 'leak.env'));
    expectCode('path_denied', fn () => $fm->crossMove('local', '.env', 'local2', 'leak.env'));
    assertTrue(!file_exists($root . '/leak.env') && !file_exists($root . '-2/leak.env') && !file_exists($root . '/id_rsa'), 'nothing exposed');
});

test('B8: a visible file can\'t be renamed/moved/copied INTO a hidden path', function () {
    [$fm] = hiddenEnv();
    expectCode('path_denied', fn () => $fm->rename('local', 'a.txt', '.a.txt'));
    expectCode('path_denied', fn () => $fm->move('local', 'a.txt', '.ssh/a.txt'));
    expectCode('path_denied', fn () => $fm->copy('local', 'a.txt', '.ssh/a.txt'));
});

test('B8: upload into a dot-directory is refused (no .ssh/authorized_keys plant)', function () {
    [$fm, , $root] = hiddenEnv();
    expectCode('path_denied', fn () => up($fm, '.ssh', 'authorized_keys', 'ssh-ed25519 AAAA'));
    assertTrue(!file_exists($root . '/.ssh/authorized_keys'), 'not written');
});

test('B8: a trashed dotfile can\'t be restored under a visible name', function () {
    // Trashed by a show_hidden token, restore attempted by a default one.
    [$fm, $meta, $root, , $dm] = hiddenEnv(true);
    $id = $fm->trash('local', '.env')['trash_id'];
    $fm2 = new FileManager($dm, new Claims('u1', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false), $meta);
    expectCode('path_denied', fn () => $fm2->restore('local', $id, 'leak.env'));
    assertTrue(!file_exists($root . '/leak.env'), 'not restored');
});

test('B8: extract skips dot-entries when show_hidden=false', function () {
    if (!class_exists(\ZipArchive::class)) {
        return;
    }
    [$fm, , $root] = env();
    $zipPath = tempnam(sys_get_temp_dir(), 'afz') . '.zip';
    $za = new \ZipArchive();
    $za->open($zipPath, \ZipArchive::CREATE);
    $za->addFromString('.ssh/authorized_keys', 'ssh-ed25519 AAAA');
    $za->addFromString('ok.txt', 'ok');
    $za->close();
    up($fm, '', 'bundle.zip', (string) file_get_contents($zipPath));
    @unlink($zipPath);
    $r = $fm->extractZip('local', 'bundle.zip', 'out');
    assertEqual(1, $r['extracted'], 'only the visible entry');
    assertTrue(is_file($root . '/out/ok.txt') && !file_exists($root . '/out/.ssh'), 'dot-entry skipped');
});

test('N8: validateUserPath/assertCanModifyScopedPath reject hidden paths (module callers)', function () {
    [$fm] = hiddenEnv();
    expectCode('path_denied', fn () => $fm->validateUserPath('.env'));
    expectCode('path_denied', fn () => $fm->validateUserPath('docs/.ssh/id_rsa'));
    expectCode('path_denied', fn () => $fm->assertCanModifyScopedPath('local', '.env'));
});

test('B8: show_hidden=true keeps full access', function () {
    [$fm] = hiddenEnv(true);
    $names = array_column($fm->list('local', '.ssh'), 'name');
    assertEqual(['id_rsa'], $names, 'listed');
    $fm->copy('local', '.env', 'copy.env');
    assertEqual('.env', $fm->validateUserPath('.env'), 'validateUserPath');
});

// ── B9: force_upload / import overwrite honour upload_collision ─────────────
test('B9: import-url overwrite:true keeps both under the default rename policy', function () {
    [$fm, , $root, $claims] = env('', ['allowUrlImport' => true]);
    up($fm, '', 'x.png', png(10, 10));
    $tmp = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($tmp, png(20, 20));
    $r = (new UrlImporter($claims, $fm))->finishImport($tmp, 'local', 'https://example.com/x.png', '', ['overwrite' => true]);
    @unlink($tmp);
    assertEqual('x (1).png', $r['name'], 'renamed');
    assertEqual(10, getimagesize($root . '/x.png')[0], 'original untouched');
});

test('B9: force_upload overwrites only under upload_collision=overwrite', function () {
    [$fm, , $root] = env('', ['uploadCollision' => 'overwrite']);
    up($fm, '', 'k.txt', 'one');
    up($fm, '', 'k.txt', 'two', true);
    assertEqual('two', file_get_contents($root . '/k.txt'), 'overwritten under the overwrite policy');
});

// ── B10: aiTag respects legal hold ──────────────────────────────────────────
final class StubTagger extends AiTagger
{
    public int $calls = 0;
    public function __construct() {}
    public function analyze(string $imageData, string $mimeType): array
    {
        $this->calls++;
        return ['title' => 'T', 'tags' => ['x']];
    }
}

test('B10: aiTag on a held file → legal_hold_active, metadata untouched', function () {
    [$fm, $meta] = env();
    $tagger = new StubTagger();
    $fm->setAiTagger($tagger);
    up($fm, '', 'pic.png', png());
    hold($meta, 'pic.png');
    expectCode('legal_hold_active', fn () => $fm->aiTag('local', 'pic.png'));
    assertEqual(0, $tagger->calls, 'provider never called');
});

// ── B11 / N12: module + save-as writes are bounded and versioned ───────────
test('B11: writeScopedFile of a NEW file past max_files → too_many_files', function () {
    [$fm, , $root] = env('', ['maxFiles' => 1], true);
    up($fm, '', 'a.txt', 'a');
    expectCode('too_many_files', fn () => $fm->writeScopedFile('local', $fm->validateUserPath('b.txt'), 'b'));
    assertTrue(!file_exists($root . '/b.txt'), 'not written');
});

test('B11: writeScopedFile past max_storage → quota_exceeded; same-size overwrite is a zero delta', function () {
    [$fm, , $root] = env('', ['maxStorageMb' => 1], true);
    up($fm, '', 'a.bin', random_bytes(900 * 1024));
    expectCode('quota_exceeded', fn () => $fm->writeScopedFile('local', 'b.bin', random_bytes(300 * 1024)));
    $fm->writeScopedFile('local', 'a.bin', random_bytes(900 * 1024)); // in place, delta 0 → fine
    assertTrue(!file_exists($root . '/b.bin'), 'over-quota write refused');
});

test('N12: writeScopedFile snapshots an existing target before overwriting it', function () {
    [$fm] = env();
    $kept = [];
    $fm->setVersionKeeper(function (string $disk, string $key) use (&$kept): void { $kept[] = $key; });
    up($fm, '', 'doc.txt', 'v1');
    $fm->writeScopedFile('local', 'doc.txt', 'v2');
    $fm->writeScopedFile('local', 'fresh.txt', 'new'); // new file → nothing to snapshot
    assertEqual(['doc.txt'], $kept, 'one snapshot, of the overwritten file');
});

test('N12: writeScopedStream of a NEW file past max_files → too_many_files', function () {
    [$fm] = env('', ['maxFiles' => 1], true);
    up($fm, '', 'a.txt', 'a');
    $s = fopen('php://memory', 'w+b');
    fwrite($s, 'b');
    rewind($s);
    expectCode('too_many_files', fn () => $fm->writeScopedStream('local', 'b.txt', $s));
});

test('B11: crop "save as" past max_files → too_many_files', function () {
    [$fm, , $root] = env('', ['maxFiles' => 1], true);
    up($fm, '', 'pic.png', png());
    expectCode('too_many_files', fn () => $fm->cropImage('local', 'pic.png', 0, 0, 10, 10, 'pic-crop.png'));
    assertTrue(!file_exists($root . '/pic-crop.png'), 'not written');
});

// ── B12: crossMove purges the source's version history ──────────────────────
test('B12: crossMove purges version history on the source disk', function () {
    [$fm] = env();
    $purged = [];
    $fm->setVersionPurger(function (string $disk, string $key) use (&$purged): void { $purged[] = "{$disk}:{$key}"; });
    up($fm, 'd', 'a.txt', 'a');
    $fm->crossMove('local', 'd/a.txt', 'local2', 'd/a.txt');
    assertEqual(['local:d/a.txt'], $purged, 'source history purged');
});

// ── B14: OptimizeModule ─────────────────────────────────────────────────────
/** Local adapter that records every read of file contents. */
final class CountingLocalAdapter extends LocalFilesystemAdapter
{
    /** @var string[] */
    public array $reads = [];
    public function read(string $path): string { $this->reads[] = $path; return parent::read($path); }
    public function readStream(string $path) { $this->reads[] = $path; return parent::readStream($path); }
}

final class CountingDiskManager extends DiskManager
{
    public CountingLocalAdapter $adapter;
    private Filesystem $fs;
    public function __construct(string $root)
    {
        parent::__construct(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
        $this->adapter = new CountingLocalAdapter($root);
        $this->fs = new Filesystem($this->adapter);
    }
    public function disk(string $name): Filesystem { return $this->fs; }
}

function optEnv(array $setup = []): array {
    $root = sys_get_temp_dir() . '/ff-afcf-opt-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $claims->allowOptimize = true;
    foreach ($setup as $k => $v) {
        $claims->{$k} = $v;
    }
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [new OptimizeModule(), $fm, $dm, new ImageOptimizer(), $root, $claims];
}

test('B14: optimize_max_mb is judged BEFORE the file is read', function () {
    $root = sys_get_temp_dir() . '/ff-afcf-optc-' . uniqid();
    @mkdir($root, 0777, true);
    file_put_contents($root . '/big.jpg', random_bytes(2 * 1024 * 1024));
    $dm = new CountingDiskManager($root);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $claims->allowOptimize = true;
    $claims->optimizeMaxMb = 1;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    $r = (new OptimizeModule())->run($fm, $dm, new ImageOptimizer(), $claims, ['disk' => 'local', 'path' => 'big.jpg']);
    assertEqual('too_large', $r['reason'] ?? null, 'skipped');
    assertTrue(!in_array('big.jpg', $dm->adapter->reads, true), 'source was never read');
});

test('B14: default <name>.webp never clobbers a different existing file', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = optEnv();
    noisyJpeg($root . '/pic.jpg');
    file_put_contents($root . '/pic.webp', 'SOMEONE ELSE');
    expectCode('name_exists', fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'pic.jpg']));
    assertEqual('SOMEONE ELSE', file_get_contents($root . '/pic.webp'), 'existing file untouched');
    assertTrue(is_file($root . '/pic.jpg'), 'source kept');
});

test('B14: upload_collision=overwrite allows replacing the existing .webp', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = optEnv(['uploadCollision' => 'overwrite']);
    noisyJpeg($root . '/pic.jpg');
    file_put_contents($root . '/pic.webp', 'OLD');
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'pic.jpg']);
    assertEqual(false, $r['skipped'], 'optimized');
    assertTrue(file_get_contents($root . '/pic.webp') !== 'OLD', 'replaced');
});

test('B14: an explicit dest must keep the .webp extension', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = optEnv();
    noisyJpeg($root . '/pic.jpg');
    expectCode('ext_changed', fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'pic.jpg', 'dest' => 'pic.png']));
    assertTrue(!file_exists($root . '/pic.png'), 'nothing written');
});

// ── N14: read-type module check ─────────────────────────────────────────────
test('N14: assertCanUseScopedPath allows a held SOURCE, keeps the other guards', function () {
    [$fm, $meta] = env('tenant', ['ownerOnly' => true]);
    up($fm, '', 'held.txt', 'x');
    hold($meta, 'tenant/held.txt');
    assertEqual('tenant/held.txt', $fm->assertCanUseScopedPath('local', 'tenant/held.txt'), 'held source usable');
    expectCode('legal_hold_active', fn () => $fm->assertCanModifyScopedPath('local', 'tenant/held.txt'));
    expectCode('path_denied', fn () => $fm->assertCanUseScopedPath('local', 'tenant/.env'));
    expectCode('system_path', fn () => $fm->assertCanUseScopedPath('local', 'tenant/_fluxfiles/trash.json'));
    expectCode('path_denied', fn () => $fm->assertCanUseScopedPath('local', 'other/x.txt'));
    $meta->save('local', 'tenant/theirs.txt', ['uploaded_by' => 'someone-else']);
    expectCode('owner_only', fn () => $fm->assertCanUseScopedPath('local', 'tenant/theirs.txt'));
});

test('writeScopedFile/Stream enforce disk, write permission and tenant scope themselves', function () {
    [$fm] = env('tenant1', ['permissions' => ['read']]);
    expectCode('permission_denied', fn () => $fm->writeScopedFile('local', 'tenant1/a.txt', 'x'));
    [$fm2] = env('tenant1');
    expectCode('path_denied', fn () => $fm2->writeScopedFile('local', 'tenant2/a.txt', 'x'));
    $s = fopen('php://memory', 'r+'); fwrite($s, 'x'); rewind($s);
    expectCode('path_denied', fn () => $fm2->writeScopedStream('local', 'tenant2/b.txt', $s));
    expectCode('disk_denied', fn () => $fm2->writeScopedFile('nope', 'tenant1/c.txt', 'x'));
    $fm2->writeScopedFile('local', 'tenant1/ok.txt', 'fine');   // control: in scope + write → ok
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

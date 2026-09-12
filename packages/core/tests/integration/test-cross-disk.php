<?php

/**
 * Cross-disk copy/move (FileManager::crossCopy / crossMove). Uses two LOCAL disks
 * with different roots — since src disk !== dst disk, the real cross-disk
 * (stream + metadata + variant transfer) path runs, no S3 needed.
 *
 * Usage: php tests/integration/test-cross-disk.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\ApiException;
use FluxFiles\QuotaManager;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectApi(callable $f, string $code): void {
    try { $f(); throw new \RuntimeException("expected {$code}"); }
    catch (ApiException $e) { assertEqual($code, $e->getErrorCode()); }
}

/** Two local disks 'a' and 'b' with separate roots → exercises the cross-disk path. */
function makeCD(array $opts = []): array {
    $ra = sys_get_temp_dir() . '/ff-cda-' . uniqid();
    $rb = sys_get_temp_dir() . '/ff-cdb-' . uniqid();
    @mkdir($ra, 0777, true); @mkdir($rb, 0777, true);
    $dm = new DiskManager([
        'a' => ['driver' => 'local', 'root' => $ra, 'url' => '/a'],
        'b' => ['driver' => 'local', 'root' => $rb, 'url' => '/b'],
    ]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims($opts['user'] ?? 'u', ['read', 'write', 'delete'], ['a', 'b'], '', 50, null, $opts['maxStorageMb'] ?? 0);
    if ($opts['ownerOnly'] ?? false) { $claims->ownerOnly = true; }
    $fm = new FileManager($dm, $claims, $meta);
    if ($opts['withQuota'] ?? false) { $fm->setQuotaManager(new QuotaManager($dm)); }
    if (isset($opts['versionKeeper'])) { $fm->setVersionKeeper($opts['versionKeeper']); }
    return [$fm, $ra, $rb, $meta];
}

echo "\n{$cyan}══ Cross-disk copy / move ══{$reset}\n\n";

test('crossCopy: streams file to the other disk + metadata; source kept', function () {
    [$fm, $ra, $rb, $meta] = makeCD();
    file_put_contents($ra . '/doc.txt', 'HELLO');
    $meta->save('a', 'doc.txt', ['uploaded_by' => 'u', 'caption' => 'note']);

    $r = $fm->crossCopy('a', 'doc.txt', 'b', 'doc.txt');
    assertEqual('a', $r['src_disk']); assertEqual('b', $r['dst_disk']);
    assertEqual('HELLO', file_get_contents($rb . '/doc.txt'), 'copied to disk b');
    assertTrue(is_file($ra . '/doc.txt'), 'source kept');
    assertEqual('note', $meta->get('b', 'doc.txt')['caption'] ?? null, 'metadata transferred');
});

test('crossMove: moves file (dst created, src deleted) + metadata', function () {
    [$fm, $ra, $rb, $meta] = makeCD();
    file_put_contents($ra . '/m.txt', 'MOVE');
    $meta->save('a', 'm.txt', ['uploaded_by' => 'u']);

    $fm->crossMove('a', 'm.txt', 'b', 'm.txt');
    assertEqual('MOVE', file_get_contents($rb . '/m.txt'), 'on disk b');
    assertTrue(!is_file($ra . '/m.txt'), 'source removed');
    assertTrue($meta->get('a', 'm.txt') === null, 'source metadata removed');
});

test('crossCopy: image variants transferred to the destination disk', function () {
    [$fm, $ra, $rb, $meta] = makeCD();
    $im = imagecreatetruecolor(300, 200); imagefilledrectangle($im, 0, 0, 299, 199, imagecolorallocate($im, 9, 130, 210));
    imagepng($im, $ra . '/pic.png'); imagedestroy($im);
    // a variant beside the source (as upload would have produced)
    @mkdir($ra . '/_variants', 0777, true);
    file_put_contents($ra . '/_variants/pic.png_thumb.webp', 'WEBPDATA');

    $fm->crossCopy('a', 'pic.png', 'b', 'pic.png');
    assertEqual('WEBPDATA', file_get_contents($rb . '/_variants/pic.png_thumb.webp'), 'variant transferred');
});

test('crossCopy: existing destination → 409 name_exists (no silent overwrite)', function () {
    [$fm, $ra, $rb] = makeCD();
    file_put_contents($ra . '/x.txt', 'SRC');
    file_put_contents($rb . '/x.txt', 'DST-KEEP');
    expectApi(fn () => $fm->crossCopy('a', 'x.txt', 'b', 'x.txt'), 'name_exists');
    assertEqual('DST-KEEP', file_get_contents($rb . '/x.txt'), 'destination untouched');
});

test('crossMove: existing destination → 409 name_exists; source kept', function () {
    [$fm, $ra, $rb] = makeCD();
    file_put_contents($ra . '/y.txt', 'SRC');
    file_put_contents($rb . '/y.txt', 'DST-KEEP');
    expectApi(fn () => $fm->crossMove('a', 'y.txt', 'b', 'y.txt'), 'name_exists');
    assertTrue(is_file($ra . '/y.txt'), 'source kept (move aborted before delete)');
});

test('crossCopy: missing source → clean 404 not_found', function () {
    [$fm] = makeCD();
    expectApi(fn () => $fm->crossCopy('a', 'nope.txt', 'b', 'nope.txt'), 'not_found');
});

test('crossCopy: owner_only blocks copying another user\'s file → 403', function () {
    [$fm, $ra, $rb, $meta] = makeCD(['user' => 'bob', 'ownerOnly' => true]);
    file_put_contents($ra . '/owned.txt', 'ALICE');
    $meta->save('a', 'owned.txt', ['uploaded_by' => 'alice']);
    expectApi(fn () => $fm->crossCopy('a', 'owned.txt', 'b', 'owned.txt'), 'owner_only');
    assertTrue(!is_file($rb . '/owned.txt'), 'nothing copied');
});

test('extension immutability across disks: dst ext must match src', function () {
    [$fm, $ra] = makeCD();
    file_put_contents($ra . '/photo.png', 'x');
    expectApi(fn () => $fm->crossCopy('a', 'photo.png', 'b', 'photo.jpg'), 'ext_changed');
});

// ── writeScopedStream() — the module write seam Backup's sync goes through ────
// Three LOW-severity gaps found in a security audit: quota was never checked,
// an overwrite never snapshotted via Versioning, and Backup's own ad-hoc
// system-path filter drifted from the canonical assertNotSystem() rules.

test('writeScopedStream: destination quota is enforced — over-limit write throws and nothing lands', function () {
    [$fm, , $rb] = makeCD(['maxStorageMb' => 1, 'withQuota' => true]); // 1MB cap on disk b
    $scoped = $fm->validateUserPath('big.bin');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, str_repeat('x', 2 * 1024 * 1024)); // 2MB > 1MB cap
    rewind($stream);
    expectApi(fn () => $fm->writeScopedStream('b', $scoped, $stream), 'quota_exceeded');
    assertTrue(!is_file($rb . '/big.bin'), 'over-quota write must NOT land on disk');
});

test('writeScopedStream: under-quota write succeeds', function () {
    [$fm, , $rb] = makeCD(['maxStorageMb' => 1, 'withQuota' => true]);
    $scoped = $fm->validateUserPath('small.bin');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, str_repeat('x', 100 * 1024)); // 100KB < 1MB cap
    rewind($stream);
    $fm->writeScopedStream('b', $scoped, $stream);
    assertTrue(is_file($rb . '/small.bin'), 'under-quota write lands on disk');
});

test('writeScopedStream: no QuotaManager wired → unbounded (free core pays nothing)', function () {
    [$fm, , $rb] = makeCD(['maxStorageMb' => 1]); // withQuota omitted → no QuotaManager
    $scoped = $fm->validateUserPath('big.bin');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, str_repeat('x', 2 * 1024 * 1024));
    rewind($stream);
    $fm->writeScopedStream('b', $scoped, $stream);
    assertTrue(is_file($rb . '/big.bin'), 'no QuotaManager → write proceeds regardless of maxStorageMb');
});

test('writeScopedStream: overwriting an existing file invokes the version keeper', function () {
    $calls = [];
    $keeper = function (string $d, string $key, $fs) use (&$calls): void { $calls[] = [$d, $key]; };
    [$fm, , $rb] = makeCD(['versionKeeper' => $keeper]);
    file_put_contents($rb . '/app.env', 'v1');
    $scoped = $fm->validateUserPath('app.env');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'v2');
    rewind($stream);
    $fm->writeScopedStream('b', $scoped, $stream);
    assertEqual('v2', file_get_contents($rb . '/app.env'), 'overwrite lands');
    assertEqual(1, count($calls), 'version keeper called exactly once');
    assertEqual('b', $calls[0][0], 'keeper receives the destination disk');
    assertEqual('app.env', $calls[0][1], 'keeper receives the scoped key');
});

test('writeScopedStream: NEW file (no prior existence) does NOT invoke the version keeper', function () {
    $calls = [];
    $keeper = function (string $d, string $key, $fs) use (&$calls): void { $calls[] = [$d, $key]; };
    [$fm] = makeCD(['versionKeeper' => $keeper]);
    $scoped = $fm->validateUserPath('fresh.txt');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'first-write');
    rewind($stream);
    $fm->writeScopedStream('b', $scoped, $stream);
    assertEqual(0, count($calls), 'no snapshot for a brand-new file');
});

// ── isReservedSystemPath() — non-throwing counterpart to assertNotSystem() ────
test('isReservedSystemPath: true for _fluxfiles/ and _variants/ subtree paths', function () {
    [$fm] = makeCD();
    assertTrue($fm->isReservedSystemPath('_fluxfiles/index.json'), '_fluxfiles/ prefix');
    assertTrue($fm->isReservedSystemPath('_variants/pic.png_thumb.webp'), '_variants/ prefix');
    assertTrue($fm->isReservedSystemPath('user/_fluxfiles/meta/x.json'), 'nested _fluxfiles/');
    assertTrue($fm->isReservedSystemPath('user/_variants/x.webp'), 'nested _variants/');
});

test('isReservedSystemPath: true for exact _fluxfiles/_variants basename', function () {
    [$fm] = makeCD();
    assertTrue($fm->isReservedSystemPath('_fluxfiles'), 'exact _fluxfiles basename');
    assertTrue($fm->isReservedSystemPath('_variants'), 'exact _variants basename');
});

test('isReservedSystemPath: true for the legacy "{name}.meta.json" sidecar shape', function () {
    [$fm] = makeCD();
    assertTrue($fm->isReservedSystemPath('doc.meta.json'), 'legacy sidecar');
    assertTrue($fm->isReservedSystemPath('folder/doc.meta.json'), 'nested legacy sidecar');
    assertTrue($fm->isReservedSystemPath('folder/DOC.META.JSON'), 'case-insensitive suffix match');
});

test('isReservedSystemPath: false for a normal user file path', function () {
    [$fm] = makeCD();
    assertTrue(!$fm->isReservedSystemPath('photo.jpg'), 'plain file');
    assertTrue(!$fm->isReservedSystemPath('folder/report.pdf'), 'nested plain file');
    assertTrue(!$fm->isReservedSystemPath('folder/meta.json'), 'a plain "meta.json" (not "{name}.meta.json") is fine');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

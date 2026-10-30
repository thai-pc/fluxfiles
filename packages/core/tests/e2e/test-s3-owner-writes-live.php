<?php

/**
 * Live S3 test — every S3 write keeps the owner header, so owner_only holds.
 *
 * On S3 assertOwner() reads ONLY the `x-amz-meta-fm-uploaded-by` header, and a
 * PutObject replaces all x-amz-meta-* — so an in-place rewrite that doesn't hand the
 * header back (editor save, module/watermark write, restore-original, multipart
 * upload) used to leave the file ownerless: any owner_only user could then delete,
 * rename or overwrite it. Each write path below must leave `fm-uploaded-by` correct
 * AND still make a second owner_only user get 403 owner_only.
 *
 * Also: the >5 GiB self-copy limit surfaces `metadata_object_too_large` instead of
 * leaving stale headers (simulated by faking HeadObject's ContentLength), and a same-disk
 * MOVE carries the source's owner even when a stale destination entry/row exists
 * (JSON index and DB row).
 *
 * Same env contract as test-s3-live.php (skips cleanly without a bucket):
 *   FXTEST_S3_BUCKET / FXTEST_S3_KEY / FXTEST_S3_SECRET / FXTEST_S3_ENDPOINT
 *   FXTEST_S3_CREATE_BUCKET=1 (LocalStack).
 *
 * Usage (LocalStack from docker-compose.yml):
 *   FXTEST_S3_BUCKET=fxtest-bucket FXTEST_S3_KEY=test FXTEST_S3_SECRET=test \
 *   FXTEST_S3_ENDPOINT=http://localhost:4566 FXTEST_S3_CREATE_BUCKET=1 \
 *   php tests/e2e/test-s3-owner-writes-live.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\Middleware;
use Aws\Result;
use Aws\S3\S3Client;
use FluxFiles\ApiException;
use FluxFiles\ChunkUploader;
use FluxFiles\Claims;
use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$bucket   = getenv('FXTEST_S3_BUCKET') ?: '';
$key      = getenv('FXTEST_S3_KEY') ?: '';
$secret   = getenv('FXTEST_S3_SECRET') ?: '';
$region   = getenv('FXTEST_S3_REGION') ?: 'us-east-1';
$endpoint = getenv('FXTEST_S3_ENDPOINT') ?: '';

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  Live S3 — owner header on every write path\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

if ($bucket === '' || $key === '' || $secret === '') {
    echo "  {$yellow}SKIP{$reset} — bucket/key/secret not provided\n\n";
    exit(0);
}
if (!preg_match('/(test|dev|scratch|sandbox|staging|livetest)/i', $bucket)
    && getenv('FXTEST_S3_I_KNOW_THIS_BUCKET_HAS_REAL_DATA') !== '1') {
    echo "  {$red}REFUSED{$reset} — bucket '{$bucket}' does not look like a test bucket.\n\n";
    exit(1);
}

$RUN = 'fluxfiles-livetest/own-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));

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

$s3cfg = [
    'driver' => 's3', 'region' => $region, 'bucket' => $bucket,
    'key' => $key, 'secret' => $secret, 'visibility' => 'private',
];
if ($endpoint !== '') { $s3cfg['endpoint'] = $endpoint; }

if (getenv('FXTEST_S3_CREATE_BUCKET') === '1') {
    $p = ['credentials' => ['key' => $key, 'secret' => $secret], 'region' => $region, 'version' => 'latest'];
    if ($endpoint !== '') { $p['endpoint'] = $endpoint; $p['use_path_style_endpoint'] = true; }
    try { $c = new S3Client($p); if (!$c->doesBucketExist($bucket)) { $c->createBucket(['Bucket' => $bucket]); } }
    catch (\Throwable $e) { echo "  {$yellow}note{$reset} createBucket: {$e->getMessage()}\n"; }
}

$dm = new DiskManager(['s3a' => $s3cfg]);
$raw = $dm->s3Client('s3a');

/** HeadObject's ContentLength is faked for keys in this list (the >5 GiB scenario). */
$fakeBig = [];
$curCmd = null;
$raw->getHandlerList()->appendSign(Middleware::tap(function ($cmd) use (&$curCmd) { $curCmd = [$cmd->getName(), (string) ($cmd['Key'] ?? '')]; }), 'cur');
$raw->getHandlerList()->appendSign(Middleware::mapResult(function ($r) use (&$fakeBig, &$curCmd) {
    if ($r instanceof Result && $curCmd !== null && $curCmd[0] === 'HeadObject' && in_array($curCmd[1], $fakeBig, true)) {
        return new Result(['ContentLength' => 6 * 1024 * 1024 * 1024] + $r->toArray());
    }
    return $r;
}), 'fake');

function headers(string $k): array
{
    global $raw, $bucket;
    return $raw->headObject(['Bucket' => $bucket, 'Key' => $k])['Metadata'] ?? [];
}
function owner(string $k): ?string
{
    $o = headers($k)['fm-uploaded-by'] ?? '';
    return $o === '' ? null : $o;
}
function tmp(string $content): string
{
    $p = tempnam(sys_get_temp_dir(), 'fxo');
    file_put_contents($p, $content);
    return $p;
}
function up(FileManager $fm, string $name, string $content = 'hello world'): void
{
    $t = tmp($content);
    try { $fm->upload('s3a', '', ['name' => $name, 'size' => strlen($content), 'tmp_name' => $t]); }
    finally { @unlink($t); }
}
function pngBytes(): string
{
    $im = imagecreatetruecolor(60, 40);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
    ob_start(); imagepng($im); return (string) ob_get_clean();
}

function claimsFor(string $user, string $prefix, bool $overwrite = false): Claims
{
    $c = new Claims($user, ['read', 'write', 'delete'], ['s3a'], $prefix, 50, null, 0, true);
    $c->allowCodeEdit = true;
    $c->uploadCollision = $overwrite ? 'overwrite' : 'rename';
    return $c;
}

$K = fn (string $n) => $RUN . '/' . $n;
$meta = new StorageMetadataHandler($dm);
$alice = new FileManager($dm, claimsFor('alice', $RUN), $meta);
$bob = new FileManager($dm, claimsFor('bob', $RUN), $meta);

/** After a write: header owner is alice, and bob (owner_only) is refused. */
function assertStillAlices(string $name, FileManager $bob): void
{
    global $K;
    eq('alice', owner($K($name)), 'fm-uploaded-by header');
    throwsCode(fn () => $bob->delete('s3a', $name), 'owner_only');
    throwsCode(fn () => $bob->rename('s3a', $name, 'stolen-' . $name), 'owner_only');
}

// ═════════════════════════════════════════════════════════════════
echo "{$yellow}► In-place writes keep the owner (JSON backend){$reset}\n";
// ═════════════════════════════════════════════════════════════════

test('upload (new): header = the uploader; another owner_only user is refused', function () use ($alice, $bob) {
    up($alice, 'u.txt');
    assertStillAlices('u.txt', $bob);
});

test('putContent (editor save) keeps the owner header', function () use ($alice, $bob) {
    up($alice, 'edit.txt');
    $alice->putContent('s3a', 'edit.txt', 'edited body');
    assertStillAlices('edit.txt', $bob);
});

test('writeScopedFile over an existing file (module write) keeps the owner header', function () use ($alice, $bob) {
    up($alice, 'mod.txt');
    $alice->writeScopedFile('s3a', $GLOBALS['K']('mod.txt'), 'module output');
    assertStillAlices('mod.txt', $bob);
});

test('writeScopedStream over an existing file keeps the owner header', function () use ($alice, $bob) {
    up($alice, 'mods.txt');
    $s = fopen('php://temp', 'w+'); fwrite($s, 'streamed output'); rewind($s);
    $alice->writeScopedStream('s3a', $GLOBALS['K']('mods.txt'), $s);
    assertStillAlices('mods.txt', $bob);
});

test('writeScopedFile creating a NEW file: born owned by the writer', function () use ($alice, $bob) {
    $alice->writeScopedFile('s3a', $GLOBALS['K']('mod-new.txt'), 'fresh');
    assertStillAlices('mod-new.txt', $bob);
});

test('burn-in watermark in place + remove-watermark restore keep the owner header', function () use ($alice, $bob) {
    if (!function_exists('imagecreatetruecolor')) { echo "      (GD unavailable — skipped)\n"; return; }
    up($alice, 'wm.png', pngBytes());
    $alice->applyWatermark('s3a', 'wm.png', ['type' => 'text', 'text' => 'X', 'x' => 50, 'y' => 50, 'font_size' => 12, 'opacity' => 80, 'color' => '#ffffff']);
    assertStillAlices('wm.png', $bob);
    $alice->removeWatermark('s3a', 'wm.png');
    assertStillAlices('wm.png', $bob);
});

test('extract: new and overwritten entries end up owned by the extractor', function () use ($alice, $bob, $dm, $RUN) {
    if (!class_exists(\ZipArchive::class)) { echo "      (zip unavailable — skipped)\n"; return; }
    $zp = tempnam(sys_get_temp_dir(), 'fxz');
    $z = new \ZipArchive(); $z->open($zp, \ZipArchive::OVERWRITE); $z->addFromString('inner.txt', 'zipped'); $z->close();
    up($alice, 'pack.zip', (string) file_get_contents($zp));
    @unlink($zp);
    $alice->extractZip('s3a', 'pack.zip', 'ex');
    eq('alice', owner($GLOBALS['K']('ex/inner.txt')), 'new entry');
    $aliceOw = new FileManager($dm, claimsFor('alice', $RUN, true), new StorageMetadataHandler($dm));
    $aliceOw->extractZip('s3a', 'pack.zip', 'ex');
    eq('alice', owner($GLOBALS['K']('ex/inner.txt')), 'overwritten entry');
    throwsCode(fn () => $bob->delete('s3a', 'ex/inner.txt'), 'owner_only');
});

test('multipart upload: the owner header is set at initiate (so even a huge object has it)', function () use ($alice, $bob, $dm, $raw, $bucket, $K, $meta) {
    $chunker = new ChunkUploader($dm);
    $k = $K('big.bin');
    $scoped = $alice->validateChunkUpload('s3a', $k, 12, true);
    $init = $chunker->initiate('s3a', $scoped, method_exists($alice, 'chunkInitMetadata') ? $alice->chunkInitMetadata('s3a', $scoped) : []);
    $part = $raw->uploadPart(['Bucket' => $bucket, 'Key' => $scoped, 'UploadId' => $init['upload_id'], 'PartNumber' => 1, 'Body' => 'twelve bytes']);
    $chunker->complete('s3a', $scoped, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $part['ETag']]], null, true);
    // Exactly what the completion handler does next: it must find the header already right.
    $copies = 0;
    $raw->getHandlerList()->appendSign(Middleware::tap(function ($cmd) use (&$copies) { if ($cmd->getName() === 'CopyObject') { $copies++; } }), 'cnt');
    $meta->save('s3a', $scoped, ['uploaded_by' => 'alice', 'size' => 12, 'modified' => time()]);
    eq('alice', owner($scoped), 'fm-uploaded-by header');
    eq(0, $copies, 'no self-copy needed at completion');
    throwsCode(fn () => $bob->delete('s3a', 'big.bin'), 'owner_only');
});

// ═════════════════════════════════════════════════════════════════
echo "\n{$yellow}► Objects over 5 GiB: headers can't be rewritten -> explicit error{$reset}\n";
// ═════════════════════════════════════════════════════════════════

test('changing a header-backed field of a >5 GiB object is refused with metadata_object_too_large', function () use ($alice, $meta, &$fakeBig, $K) {
    up($alice, 'huge.bin');
    $fakeBig[] = $K('huge.bin');
    throwsCode(fn () => $meta->save('s3a', $K('huge.bin'), ['title' => 'new title']), 'metadata_object_too_large');
    eq('alice', owner($K('huge.bin')), 'owner header untouched');
    eq('', headers($K('huge.bin'))['fm-title'] ?? '', 'title header untouched');
});

test('...but a save that changes no header (size/modified/mime) still works on a >5 GiB object', function () use ($meta, $K) {
    $meta->save('s3a', $K('huge.bin'), ['size' => 7, 'modified' => time()]);
});

// ═════════════════════════════════════════════════════════════════
echo "\n{$yellow}► Same-disk MOVE: the source's owner wins over a stale destination{$reset}\n";
// ═════════════════════════════════════════════════════════════════

test('JSON: stale index entry (mallory) at the destination does not decide the moved file\'s owner', function () use ($alice, $meta, $K) {
    up($alice, 'mv-src.txt');
    $meta->indexFile('s3a', $K('mv-dst.txt'), ['uploaded_by' => 'mallory', 'size' => 1], true);   // leftover from an out-of-band delete
    $alice->move('s3a', 'mv-src.txt', 'mv-dst.txt');
    eq('alice', owner($K('mv-dst.txt')), 'header');
    eq('alice', $meta->get('s3a', $K('mv-dst.txt'))['uploaded_by'] ?? null, 'get()');
    eq('alice', $meta->indexOwners('s3a', [$K('mv-dst.txt')])[$K('mv-dst.txt')] ?? null, 'index owner');
});

$dbFile = sys_get_temp_dir() . '/fxs3own-' . bin2hex(random_bytes(4)) . '.sqlite3';
$conn = new Connection('sqlite:' . $dbFile);
(new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
$dbMeta = new DbMetadataHandler($conn, $dm);
$aliceDb = new FileManager($dm, claimsFor('alice', $RUN), $dbMeta);

test('DB: stale row (mallory) at the destination does not decide the moved file\'s owner', function () use ($aliceDb, $dbMeta, $conn, $K) {
    up($aliceDb, 'dbmv-src.txt');
    $dbMeta->save('s3a', $K('dbmv-dst.txt'), ['uploaded_by' => 'mallory']);                    // leftover row, no object
    $aliceDb->move('s3a', 'dbmv-src.txt', 'dbmv-dst.txt');
    $r = $conn->pdo()->prepare('SELECT owner FROM file_metadata WHERE disk = ? AND path = ?');
    $r->execute(['s3a', $K('dbmv-dst.txt')]);
    eq('alice', $r->fetchColumn(), 'row owner after the move');
    eq('alice', $dbMeta->get('s3a', $K('dbmv-dst.txt'))['uploaded_by'] ?? null, 'get()');
});

// ── cleanup (only ever this run's own prefix) ───────────────────────
if ($RUN === '' || strpos($RUN, 'fluxfiles-livetest/own-') !== 0) {
    throw new \RuntimeException('refusing to clean up outside the run prefix');
}
try { $raw->deleteMatchingObjects($bucket, $RUN . '/'); } catch (\Throwable $e) { echo "  {$yellow}note{$reset} cleanup: {$e->getMessage()}\n"; }
@unlink($dbFile);

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";
exit($failed > 0 ? 1 : 0);

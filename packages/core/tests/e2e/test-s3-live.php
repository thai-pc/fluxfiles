<?php

/**
 * Live S3/R2 test — runs the real upload/list/presign/visibility/delete flow
 * against any S3-compatible backend. Parametrised by env so the same script
 * covers MinIO (local Docker), AWS S3, and Cloudflare R2.
 *
 * Required env (skips cleanly if bucket/key missing):
 *   FXTEST_S3_LABEL        display name (e.g. "MinIO", "AWS S3", "R2")
 *   FXTEST_S3_BUCKET       bucket name
 *   FXTEST_S3_KEY          access key
 *   FXTEST_S3_SECRET       secret key
 *   FXTEST_S3_REGION       region (default us-east-1 / auto)
 *   FXTEST_S3_ENDPOINT     custom endpoint (empty for AWS; set for MinIO/R2)
 *   FXTEST_S3_VISIBILITY   private (default) | public
 *   FXTEST_S3_PUBLIC_URL   optional CDN/custom-domain base
 *   FXTEST_S3_CREATE_BUCKET 1 → create bucket if missing (MinIO)
 *
 * THIS SCRIPT DELETES OBJECTS. Everything it writes lives under a run-unique prefix
 * `fluxfiles-livetest/<utc>-<rand>/` and every delete is asserted to fall inside it,
 * but the guards are a seatbelt — point this at a dedicated scratch bucket.
 *   FXTEST_S3_ALLOW_DESTRUCTIVE=1        run the directory scenarios (prefix-wide
 *                                        deletes); skipped loudly without it
 *   FXTEST_S3_I_KNOW_THIS_BUCKET_HAS_REAL_DATA=1
 *                                        override the "bucket name must look
 *                                        disposable" refusal
 *
 * Usage:
 *   FXTEST_S3_LABEL=MinIO FXTEST_S3_BUCKET=... php tests/test-s3-live.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;
use Aws\S3\S3Client;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$label   = getenv('FXTEST_S3_LABEL') ?: 'S3';
$bucket  = getenv('FXTEST_S3_BUCKET') ?: '';
$key     = getenv('FXTEST_S3_KEY') ?: '';
$secret  = getenv('FXTEST_S3_SECRET') ?: '';
$region  = getenv('FXTEST_S3_REGION') ?: 'us-east-1';
$endpoint = getenv('FXTEST_S3_ENDPOINT') ?: '';
$visibility = getenv('FXTEST_S3_VISIBILITY') ?: 'private';
$publicUrl = getenv('FXTEST_S3_PUBLIC_URL') ?: '';
$createBucket = getenv('FXTEST_S3_CREATE_BUCKET') === '1';

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  Live S3 test — {$label} (visibility={$visibility})\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

if ($bucket === '' || $key === '' || $secret === '') {
    echo "  {$yellow}SKIP{$reset} — bucket/key/secret not provided for {$label}\n\n";
    exit(0);
}

// ══ Destructive-run guards ═══════════════════════════════════════════════════
//
// This script WRITES AND DELETES. It is normally pointed at a throwaway MinIO, but
// the same env names work against real AWS/R2, and a developer with working
// credentials in their shell is one command away from running it at production. The
// guards below are ordered cheapest-first and each closes a different way that goes
// wrong. None of them replaces the real advice: use a dedicated scratch bucket.

// 1. Refuse a bucket that isn't obviously disposable. Overridable, but only by an
//    env var too long to type by accident or paste from a README.
if (!preg_match('/(test|dev|scratch|sandbox|staging|livetest)/i', $bucket)
    && getenv('FXTEST_S3_I_KNOW_THIS_BUCKET_HAS_REAL_DATA') !== '1') {
    echo "  {$red}REFUSED{$reset} — bucket '{$bucket}' does not look like a test bucket.\n";
    echo "  This script deletes objects. Point it at a scratch bucket, or set\n";
    echo "  FXTEST_S3_I_KNOW_THIS_BUCKET_HAS_REAL_DATA=1 if you are certain.\n\n";
    exit(1);
}

// 2. A RUN-UNIQUE prefix. The old fixed constant meant two runs collided, and a
//    constant is one typo away from addressing the whole bucket.
$RUN_ID = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
$prefix = 'fluxfiles-livetest/' . $RUN_ID;

/**
 * Refuse to delete anything outside this run's own prefix.
 *
 * Re-checks the prefix itself every time rather than trusting it was built correctly:
 * an empty or run-id-less prefix would make every key below "inside" it, which is
 * precisely the wide delete this exists to prevent.
 */
function assertInRun(string $key): void
{
    global $prefix, $RUN_ID;
    if ($prefix === '' || strpos($prefix, $RUN_ID) === false) {
        throw new \RuntimeException('run prefix is empty or lost its run id — refusing to delete anything');
    }
    if ($key !== $prefix && strpos($key, $prefix . '/') !== 0) {
        throw new \RuntimeException("refusing to delete outside the run prefix: {$key}");
    }
}
function safeDelete(FileManager $fm, string $key): void { assertInRun($key); $fm->delete('s3test', $key); }
function safeDeleteDirectory($fs, string $key): void { assertInRun($key); $fs->deleteDirectory($key); }

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

/** Plain HTTP status of a URL (follows nothing; HEAD-ish via GET). */
function httpStatus(string $url): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$diskCfg = [
    'driver' => 's3', 'region' => $region, 'bucket' => $bucket,
    'key' => $key, 'secret' => $secret,
    'visibility' => $visibility, 'public_url' => $publicUrl,
];
if ($endpoint !== '') { $diskCfg['endpoint'] = $endpoint; }

// Optionally create the bucket (MinIO).
if ($createBucket) {
    $s3p = ['credentials' => ['key' => $key, 'secret' => $secret], 'region' => $region, 'version' => 'latest'];
    if ($endpoint !== '') { $s3p['endpoint'] = $endpoint; $s3p['use_path_style_endpoint'] = true; }
    $client = new S3Client($s3p);
    try { if (!$client->doesBucketExist($bucket)) { $client->createBucket(['Bucket' => $bucket]); } }
    catch (\Throwable $e) { echo "  {$yellow}note{$reset} createBucket: {$e->getMessage()}\n"; }
}

$dm = new DiskManager(['s3test' => $diskCfg]);
$claims = new Claims('liveuser', ['read', 'write', 'delete'], ['s3test'], '', 50, null, 0, false);
$meta = new StorageMetadataHandler($dm);
$fm = new FileManager($dm, $claims, $meta);
$indexer = new FluxFiles\ExistingFileIndexer($dm, $meta);

// 3. Preflight: the run prefix must be empty before we touch it. With a random run id
//    this should be impossible — which is the point: if it ever fires, the prefix is
//    not what this script thinks it is, and it must not start deleting.
foreach ($dm->disk('s3test')->listContents($prefix, true) as $_stray) {
    echo "  {$red}REFUSED{$reset} — run prefix '{$prefix}' already contains objects.\n\n";
    exit(1);
}
echo "  run prefix: {$prefix}\n\n";

$uploadedKey = null;
$uploadedUrl = null;

// Build a real PNG.
$im = imagecreatetruecolor(1000, 600);
imagefilledrectangle($im, 0, 0, 1000, 600, imagecolorallocate($im, 30, 120, 200));
$tmp = sys_get_temp_dir() . '/fxlive-' . uniqid() . '.png';
imagepng($im, $tmp); imagedestroy($im);
$fileArr = ['name' => 'live.png', 'size' => filesize($tmp), 'tmp_name' => $tmp];

echo "{$yellow}► Upload + variants{$reset}\n";
test('upload image → 200 + variants', function () use ($fm, $prefix, $fileArr, &$uploadedKey, &$uploadedUrl) {
    $r = $fm->upload('s3test', $prefix, $fileArr);
    $uploadedKey = $r['key'];
    $uploadedUrl = $r['url'];
    assertTrue(strpos($uploadedKey, $prefix) === 0, 'key under test prefix');
    assertTrue(is_array($r['variants']) && count($r['variants']) > 0, 'variants generated');
});

echo "\n{$yellow}► List{$reset}\n";
test('list test prefix → contains uploaded file', function () use ($fm, $prefix) {
    $items = $fm->list('s3test', $prefix);
    $names = array_map(fn($i) => $i['name'] ?? '', is_array($items['items'] ?? null) ? $items['items'] : $items);
    assertTrue(in_array('live.png', $names, true), 'live.png listed');
});

echo "\n{$yellow}► URL by visibility{$reset}\n";
test('fileMeta returns size/mime/modified', function () use ($fm, &$uploadedKey) {
    assertTrue(is_string($uploadedKey), 'no uploaded key (upload failed above)');
    $meta = $fm->fileMeta('s3test', $uploadedKey);
    assertTrue(($meta['size'] ?? 0) > 0, 'size present');
});

test('upload url matches visibility (presigned for private, direct for public)', function () use (&$uploadedUrl, $visibility, $publicUrl) {
    assertTrue($uploadedUrl !== '' && $uploadedUrl !== null, 'url present');
    if ($visibility === 'private') {
        assertTrue(strpos($uploadedUrl, 'X-Amz-Signature') !== false, 'private → presigned url');
    } else {
        assertTrue(strpos($uploadedUrl, 'X-Amz-Signature') === false, 'public → direct url');
        if ($publicUrl !== '') assertTrue(strpos($uploadedUrl, rtrim($publicUrl, '/')) === 0, 'public_url honored');
    }
});

test('object URL loads over HTTP → 200', function () use (&$uploadedUrl) {
    assertTrue(is_string($uploadedUrl), 'no upload url (upload failed above)');
    $code = httpStatus($uploadedUrl);
    assertTrue($code === 200, "expected 200, got {$code}");
});

if ($visibility === 'private') {
    test('raw (unsigned) URL on private bucket → 403/401/404 (not public)', function () use ($bucket, $endpoint, $region, &$uploadedKey) {
        $raw = $endpoint !== ''
            ? rtrim($endpoint, '/') . '/' . $bucket . '/' . $uploadedKey
            : "https://{$bucket}.s3.{$region}.amazonaws.com/{$uploadedKey}";
        $code = httpStatus($raw);
        assertTrue(in_array($code, [400, 401, 403, 404], true), "private raw URL should be denied, got {$code}");
    });
}

echo "\n{$yellow}► Presign PUT → upload → readback{$reset}\n";
test('presign PUT, upload bytes, then presign GET reads them', function () use ($fm, $prefix) {
    $putKey = $prefix . '/put.txt';
    $body = 'hello-presign-' . uniqid();
    $put = $fm->presign('s3test', $putKey, 'PUT', 600, strlen($body));
    $ch = curl_init($put['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    curl_exec($ch); $putCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    assertTrue($putCode === 200, "PUT failed: {$putCode}");
    $get = $fm->presign('s3test', $putKey, 'GET', 600);
    $read = file_get_contents($get['url']);
    assertTrue($read === $body, 'read-back mismatch');
});

// ── Pre-existing object: PUT directly via the S3 client (no FluxFiles upload, so
//    no sidecar / index / hash / variants) — mirrors TEST-PLAN section 2bis on S3/R2.
echo "\n{$yellow}► Pre-existing object (PUT outside FluxFiles){$reset}\n";
$preKey = $prefix . '/pre-existing/orig.png';
$preImg = imagecreatetruecolor(900, 600);
imagefilledrectangle($preImg, 0, 0, 900, 600, imagecolorallocate($preImg, 200, 90, 40));
ob_start(); imagepng($preImg); $preBytes = ob_get_clean(); imagedestroy($preImg);

test('PUT object directly, then it appears in FileManager list', function () use ($dm, $fm, $bucket, $prefix, $preKey, $preBytes) {
    $dm->s3Client('s3test')->putObject(['Bucket' => $bucket, 'Key' => $preKey, 'Body' => $preBytes, 'ContentType' => 'image/png']);
    $items = $fm->list('s3test', $prefix . '/pre-existing');
    $names = array_map(fn($i) => $i['name'] ?? '', is_array($items['items'] ?? null) ? $items['items'] : $items);
    assertTrue(in_array('orig.png', $names, true), 'pre-existing object listed');
});

test('fileMeta on pre-existing object works (size present)', function () use ($fm, $preKey) {
    $m = $fm->fileMeta('s3test', $preKey);
    assertTrue(($m['size'] ?? 0) > 0, 'size present');
});

test('metadata GET on pre-existing → no FluxFiles metadata (graceful)', function () use ($meta, $preKey) {
    // On S3/R2 a raw HeadObject may return an empty metadata array (not null);
    // either way there should be no FluxFiles title before indexing.
    $got = $meta->get('s3test', $preKey);
    assertTrue($got === null || empty($got['title'] ?? ''), 'no title metadata before indexing');
});

test('presign GET on pre-existing object → HTTP 200', function () use ($fm, $preKey) {
    $g = $fm->presign('s3test', $preKey, 'GET', 600);
    assertEqual(200, httpStatus($g['url']), 'pre-existing object readable via presign');
});

test('dedup does NOT fire for un-indexed pre-existing content', function () use ($fm, $prefix, $preBytes) {
    $tmp = sys_get_temp_dir() . '/fxpre-' . uniqid() . '.png';
    file_put_contents($tmp, $preBytes);          // identical bytes to the pre-existing object
    $r = $fm->upload('s3test', $prefix . '/dedup', ['name' => 'copy.png', 'size' => strlen($preBytes), 'tmp_name' => $tmp]);
    @unlink($tmp);
    assertTrue(empty($r['duplicate']), 'pre-existing (no hash) must not be seen as duplicate');
    // remember the uploaded copy for cleanup
    $GLOBALS['_dedupCopyKey'] = $r['key'] ?? null;
});

test('index pre-existing subtree (hash+variants) → variant object + metadata created', function () use ($indexer, $meta, $dm, $prefix, $preKey) {
    // overwrite=true: on S3 a raw-PUT object already returns a (empty) HeadObject
    // metadata array, which the default indexer would treat as "already indexed".
    $stats = $indexer->index([
        'disk' => 's3test', 'path' => $prefix . '/pre-existing',
        'hash' => true, 'variants' => true, 'persist_metadata' => true, 'overwrite' => true,
    ]);
    assertTrue($stats['files_indexed'] >= 1, 'indexed the pre-existing object');
    assertTrue($stats['variants'] >= 1, 'variants generated');
    $got = $meta->get('s3test', $preKey);
    assertTrue(is_array($got) && ($got['title'] ?? '') === 'orig', 'metadata persisted (title=orig)');
    assertTrue($dm->disk('s3test')->fileExists($prefix . '/pre-existing/_variants/orig.png_thumb.webp'), 'variant object on bucket');
});

// ── Chunk / multipart upload (init → presign part → PUT → complete / abort) ──
echo "\n{$yellow}► Chunk upload (S3 multipart){$reset}\n";

test('multipart: initiate → presign part → PUT → complete → readable', function () use ($dm, $fm, $prefix) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $key = $prefix . '/chunk/big.bin';
    $body = str_repeat('FLUXCHUNK', 1200);   // single part — no 5MB minimum applies
    $init = $chunker->initiate('s3test', $key);
    assertTrue(!empty($init['upload_id']), 'got upload_id');

    $ps = $chunker->presignPart('s3test', $key, $init['upload_id'], 1, 600);
    $ch = curl_init($ps['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    assertEqual(200, $code, "part PUT failed: {$code}");

    preg_match('/ETag:\s*("?[^"\r\n]+"?)/i', substr($resp, 0, $hsize), $m);
    $etag = trim($m[1] ?? '', '"');
    assertTrue($etag !== '', 'got ETag from part PUT');

    $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $etag]]);

    $get = $fm->presign('s3test', $key, 'GET', 600);
    assertEqual($body, file_get_contents($get['url']), 'completed object content matches');
    try { safeDelete($fm, $key); } catch (\Throwable $e) {}
});

test('multipart: initiate → abort (then complete fails)', function () use ($dm, $prefix) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $key = $prefix . '/chunk/aborted.bin';
    $init = $chunker->initiate('s3test', $key);
    $r = $chunker->abort('s3test', $key, $init['upload_id']);
    assertEqual(true, $r['aborted'] ?? false, 'aborted');
    $threw = false;
    try { $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => 'x']]); }
    catch (\Throwable $e) { $threw = true; }
    assertTrue($threw, 'cannot complete an aborted upload');
});

// ── B2: chunk-complete had ZERO ownership check — reproduces the exact guard
// index.php's handleChunkComplete now runs (fileExists → assertCanModifyScopedPath)
// before calling ChunkUploader::complete(), so a different tenant's multipart
// completion against an existing key is refused instead of silently overwriting it.
test('B2: owner_only — a different tenant cannot complete a multipart upload over an existing key', function () use ($dm, $prefix) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $key = $prefix . '/chunk/owned.bin';

    // user-A owns the file (a normal, non-chunked upload records uploaded_by).
    $claimsA = new Claims('user-A', ['read', 'write', 'delete'], ['s3test'], '', 50, null, 0, true);
    $metaA = new StorageMetadataHandler($dm);
    $fmA = new FileManager($dm, $claimsA, $metaA);
    $t = sys_get_temp_dir() . '/fxlive-' . uniqid() . '.bin';
    file_put_contents($t, 'user-A original');
    $fmA->upload('s3test', dirname($key) === '.' ? '' : dirname($key), ['name' => basename($key), 'size' => filesize($t), 'tmp_name' => $t], true);
    @unlink($t);

    // user-B (different owner, same owner_only tenant) attempts a multipart
    // completion against the SAME key — this is exactly what handleChunkComplete
    // guards against.
    $claimsB = new Claims('user-B', ['read', 'write', 'delete'], ['s3test'], '', 50, null, 0, true);
    $metaB = new StorageMetadataHandler($dm);
    $fmB = new FileManager($dm, $claimsB, $metaB);

    $init = $chunker->initiate('s3test', $key);
    $body = str_repeat('ATTACK', 1000);
    $ps = $chunker->presignPart('s3test', $key, $init['upload_id'], 1, 600);
    $ch = curl_init($ps['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/ETag:\s*("?[^"\r\n]+"?)/i', substr($resp, 0, $hsize), $m);
    $etag = trim($m[1] ?? '', '"');

    // Mirrors index.php's handleChunkComplete: gate the completion the same way.
    $threw = false;
    try {
        if ($dm->disk('s3test')->fileExists($key)) {
            $fmB->assertCanModifyScopedPath('s3test', $key);
        }
        $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $etag]]);
    } catch (\FluxFiles\ApiException $e) {
        $threw = true;
        assertEqual('owner_only', $e->getErrorCode());
        assertEqual(403, $e->getHttpCode());
    }
    assertTrue($threw, 'expected 403 owner_only, completion must be refused');

    // Original bytes must be untouched — the object was never overwritten.
    $get = $fmA->presign('s3test', $key, 'GET', 600);
    assertEqual('user-A original', file_get_contents($get['url']), 'original object untouched');

    try { $chunker->abort('s3test', $key, $init['upload_id']); } catch (\Throwable $e) {}
    try { safeDelete($fmA, $key); } catch (\Throwable $e) {}
});

// ── HIGH: chunk-complete never re-validated the REAL assembled size against
// max_upload_mb/quota — /chunk/init only checks a CLIENT-DECLARED size before
// any bytes move, and parts are PUT straight to S3 on presigned URLs with no
// size condition, so a client could declare 1 byte and upload gigabytes.
// complete() now reports the REAL size (via HeadObject); the tests below
// mirror handleChunkComplete's post-hoc re-check (the function itself lives in
// index.php, which this suite doesn't boot — same reasoning as the B2 test
// above: mirror the exact guard rather than invoke the router).
echo "\n{$yellow}► Chunk upload — real-size re-validation{$reset}\n";

test('chunk complete: reports the REAL assembled size via HeadObject, not the client-declared one', function () use ($dm, $prefix) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $key = $prefix . '/chunk/realsize.bin';
    $body = str_repeat('R', 300000); // ~293KB — the caller could have declared "size":1 at init
    $init = $chunker->initiate('s3test', $key);
    $ps = $chunker->presignPart('s3test', $key, $init['upload_id'], 1, 600);
    $ch = curl_init($ps['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/ETag:\s*("?[^"\r\n]+"?)/i', substr($resp, 0, $hsize), $m);
    $etag = trim($m[1] ?? '', '"');

    $result = $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $etag]]);
    assertEqual(strlen($body), $result['size'] ?? 0, 'complete() must report the real HeadObject size');

    try { $chunker->deleteObject('s3test', $key); } catch (\Throwable $e) {}
});

test('B3: chunk complete rejects a real upload over max_upload_mb and deletes the completed object', function () use ($dm, $prefix, $meta) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $key = $prefix . '/chunk/oversized.bin';
    $body = str_repeat('X', 2 * 1024 * 1024); // 2MB real bytes
    // Tenant's own token declared max_upload_mb=1 at /chunk/init — the client
    // could have (and in the exploit, would have) lied about "size" there.
    $claimsSize = new Claims('sizeuser', ['read', 'write', 'delete'], ['s3test'], $prefix, 1, null, 0, false);
    $fmSize = new FileManager($dm, $claimsSize, $meta);

    $init = $chunker->initiate('s3test', $key);
    $ps = $chunker->presignPart('s3test', $key, $init['upload_id'], 1, 600);
    $ch = curl_init($ps['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $resp = curl_exec($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/ETag:\s*("?[^"\r\n]+"?)/i', substr($resp, 0, $hsize), $m);
    $etag = trim($m[1] ?? '', '"');

    $result = $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $etag]]);
    assertEqual(2 * 1024 * 1024, $result['size'] ?? 0, 'object really landed at 2MB');

    // Mirror handleChunkComplete's post-hoc guard.
    $threw = false;
    try {
        $fmSize->validateUploadName(basename($key), (int) ($result['size'] ?? 0));
    } catch (\FluxFiles\ApiException $e) {
        $threw = true;
        assertEqual('upload_too_large', $e->getErrorCode());
        assertEqual(413, $e->getHttpCode());
        $chunker->deleteObject('s3test', $key);
    }
    assertTrue($threw, 'expected 413 upload_too_large from the real-size re-check');
    assertTrue(!$dm->disk('s3test')->fileExists($key), 'oversized object must not linger in storage');
    assertTrue($meta->get('s3test', $key) === null, 'no metadata saved for the rejected object');
});

test('B3: chunk complete rejects a real upload over the storage quota and deletes the completed object', function () use ($dm, $prefix, $meta) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $quotaManager = new QuotaManager($dm);
    $quotaPrefix = $prefix . '/quota-test'; // isolated subtree so other tests' bytes don't affect the sum
    $key = $quotaPrefix . '/big.bin';
    $body = str_repeat('Y', 2 * 1024 * 1024); // 2MB real bytes
    // max_storage_mb=1: the object alone already blows the quota once assembled.
    $claimsQ = new Claims('quotauser', ['read', 'write', 'delete'], ['s3test'], $quotaPrefix, 100, null, 1, false);

    $init = $chunker->initiate('s3test', $key);
    $ps = $chunker->presignPart('s3test', $key, $init['upload_id'], 1, 600);
    $ch = curl_init($ps['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $resp = curl_exec($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/ETag:\s*("?[^"\r\n]+"?)/i', substr($resp, 0, $hsize), $m);
    $etag = trim($m[1] ?? '', '"');

    $result = $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $etag]]);

    // Mirror handleChunkComplete's post-hoc guard. Usage already reflects the
    // completed object (it now exists on disk), so the delta passed is 0.
    $threw = false;
    try {
        $quotaManager->assertQuota('s3test', $claimsQ->pathPrefix, 0, $claimsQ->maxStorageMb);
    } catch (\FluxFiles\ApiException $e) {
        $threw = true;
        assertEqual('quota_exceeded', $e->getErrorCode());
        assertEqual(413, $e->getHttpCode());
        $chunker->deleteObject('s3test', $key);
    }
    assertTrue($threw, 'expected 413 quota_exceeded from the real-size re-check');
    assertTrue(!$dm->disk('s3test')->fileExists($key), 'over-quota object must not linger in storage');
    assertTrue($meta->get('s3test', $key) === null, 'no metadata saved for the rejected object');
});

test('regression: a chunked upload whose real size is within limits still completes normally', function () use ($dm, $fm, $prefix, $meta) {
    $chunker = new FluxFiles\ChunkUploader($dm);
    $quotaManager = new QuotaManager($dm);
    $key = $prefix . '/chunk/happy.bin';
    $body = str_repeat('Z', 500000); // ~488KB, well within limits
    $claimsHappy = new Claims('happyuser', ['read', 'write', 'delete'], ['s3test'], $prefix, 50, null, 50, false);

    $init = $chunker->initiate('s3test', $key);
    $ps = $chunker->presignPart('s3test', $key, $init['upload_id'], 1, 600);
    $ch = curl_init($ps['url']);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/ETag:\s*("?[^"\r\n]+"?)/i', substr($resp, 0, $hsize), $m);
    $etag = trim($m[1] ?? '', '"');

    $result = $chunker->complete('s3test', $key, $init['upload_id'], [['PartNumber' => 1, 'ETag' => $etag]]);

    $threw = false;
    try {
        $fm->validateUploadName(basename($key), (int) ($result['size'] ?? 0));
        if ($claimsHappy->maxStorageMb > 0) {
            $quotaManager->assertQuota('s3test', $claimsHappy->pathPrefix, 0, $claimsHappy->maxStorageMb);
        }
        $meta->save('s3test', $key, ['uploaded_by' => $claimsHappy->userId]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    assertTrue(!$threw, 'a within-limits chunked upload must not be rejected');
    assertTrue($dm->disk('s3test')->fileExists($key), 'object present after a successful completion');
    $got = $meta->get('s3test', $key);
    assertTrue(is_array($got) && ($got['uploaded_by'] ?? '') === 'happyuser', 'metadata saved for the accepted upload');

    try { safeDelete($fm, $key); } catch (\Throwable $e) {}
});

echo "\n{$yellow}► Bucket Doctor{$reset}\n";
test('doctor diagnoses the live bucket (write/read/presign/delete/multipart ok)', function () use ($dm) {
    $report = (new \FluxFiles\BucketDoctor($dm))->diagnose('s3test', 'https://app.example');
    assertTrue($report['summary'] !== 'fail', "summary should not be fail (got {$report['summary']})");
    $byId = [];
    foreach ($report['checks'] as $c) { $byId[$c['id']] = $c['status']; }
    foreach (['reachability', 'write', 'read', 'presign', 'delete', 'multipart'] as $id) {
        assertTrue(($byId[$id] ?? '') === 'ok', "doctor check '{$id}' should be ok (got " . ($byId[$id] ?? 'missing') . ")");
    }
    assertTrue(isset($report['remediation']['cors']) && isset($report['remediation']['iam_policy']), 'remediation snippets present');
});

// ── Folder rename/move on a real object store. There are no directories in S3,
// so this is the branch of FileManager::moveDirectoryTree that walks the tree
// and recreates directory markers. Empty folders exist only as markers, which is
// exactly what the old per-file rename loop deleted without recreating.
// 4. The directory scenarios have the widest blast radius in the file — they are the
//    only ones that exercise prefix-wide deletes — so they need a deliberate opt-in
//    rather than running because someone happened to have credentials. It is a loud
//    skip, never a silent one.
echo "\n{$yellow}► Folder rename/move{$reset}\n";
if (getenv('FXTEST_S3_ALLOW_DESTRUCTIVE') !== '1') {
    echo "  {$yellow}SKIP{$reset} — set FXTEST_S3_ALLOW_DESTRUCTIVE=1 to run the directory scenarios\n";
    echo "  (they delete whole prefixes; the parity matrix in tests/integration/test-dir-parity.php\n";
    echo "   covers the same ground and is what CI relies on)\n";
} else {
$dirRoot = $prefix . '/dirs';
test('rename an empty folder keeps it alive under the new name', function () use ($fm, $dm, $dirRoot) {
    $fs = $dm->disk('s3test');
    $fm->mkdir('s3test', $dirRoot . '/empty_dir');
    $fm->rename('s3test', $dirRoot . '/empty_dir', 'empty_renamed');
    assertTrue($fs->directoryExists($dirRoot . '/empty_renamed'), 'destination exists');
    assertTrue(!$fs->directoryExists($dirRoot . '/empty_dir'), 'source gone');
});

test('rename a folder whose only content is a subfolder → child survives', function () use ($fm, $dm, $dirRoot) {
    $fs = $dm->disk('s3test');
    $fm->mkdir('s3test', $dirRoot . '/parent/child');
    $fm->rename('s3test', $dirRoot . '/parent', 'parent2');
    assertTrue($fs->directoryExists($dirRoot . '/parent2/child'), 'child survived');
    assertTrue(!$fs->directoryExists($dirRoot . '/parent'), 'source gone');
});

test('rename a mixed tree relocates files and empty dirs', function () use ($fm, $dm, $dirRoot) {
    $fs = $dm->disk('s3test');
    $t = sys_get_temp_dir() . '/fxlive-' . uniqid() . '.txt';
    file_put_contents($t, 'x');
    $fm->upload('s3test', $dirRoot . '/tree/docs', ['name' => 'a.txt', 'size' => filesize($t), 'tmp_name' => $t], true);
    $fm->mkdir('s3test', $dirRoot . '/tree/empty/nested_empty');

    $fm->rename('s3test', $dirRoot . '/tree', 'tree2');
    assertTrue($fs->fileExists($dirRoot . '/tree2/docs/a.txt'), 'file relocated');
    assertTrue($fs->directoryExists($dirRoot . '/tree2/empty/nested_empty'), 'empty dir relocated');
    assertTrue(!$fs->directoryExists($dirRoot . '/tree'), 'source gone');
});

test('move a folder (prefix copy) relocates the whole subtree', function () use ($fm, $dm, $dirRoot) {
    $fs = $dm->disk('s3test');
    $fm->mkdir('s3test', $dirRoot . '/box');
    $fm->move('s3test', $dirRoot . '/tree2', $dirRoot . '/box/tree2');
    assertTrue($fs->fileExists($dirRoot . '/box/tree2/docs/a.txt'), 'file relocated');
    assertTrue($fs->directoryExists($dirRoot . '/box/tree2/empty/nested_empty'), 'empty dir relocated');
    assertTrue(!$fs->directoryExists($dirRoot . '/tree2'), 'source gone');
});

// ── Trash/restore of a folder on a real object store. Same walk as rename, but
// the payload lands under `_fluxfiles/trash/<id>/payload/`. An empty
// subdirectory exists only as a marker object, so the manifest's `dirs[]` is
// what makes the soft-delete lossless.
echo "\n{$yellow}► Folder trash/restore{$reset}\n";
test('trash+restore a folder keeps files and empty subdirectories', function () use ($fm, $dm, $dirRoot) {
    $fs = $dm->disk('s3test');
    $t = sys_get_temp_dir() . '/fxlive-' . uniqid() . '.txt';
    file_put_contents($t, 'trash-me');
    $fm->upload('s3test', $dirRoot . '/bin', ['name' => 'doc.txt', 'size' => filesize($t), 'tmp_name' => $t], true);
    $fm->mkdir('s3test', $dirRoot . '/bin/empty_sub');
    @unlink($t);

    $id = $fm->trash('s3test', $dirRoot . '/bin')['trash_id'];
    assertTrue(!$fs->fileExists($dirRoot . '/bin/doc.txt'), 'payload moved out of the way');

    $fm->restore('s3test', $id);
    assertTrue($fs->fileExists($dirRoot . '/bin/doc.txt'), 'file restored');
    assertTrue($fs->directoryExists($dirRoot . '/bin/empty_sub'), 'empty subdirectory restored');
    assertTrue(!$fs->directoryExists('_fluxfiles/trash/' . $id), 'trash payload cleaned up');
});

test('trash+restore a folder whose only content is a subfolder', function () use ($fm, $dm, $dirRoot) {
    $fs = $dm->disk('s3test');
    $fm->mkdir('s3test', $dirRoot . '/onlydirs/child');
    $id = $fm->trash('s3test', $dirRoot . '/onlydirs')['trash_id'];
    assertTrue(!$fs->directoryExists($dirRoot . '/onlydirs'), 'source gone');

    $fm->restore('s3test', $id);
    assertTrue($fs->directoryExists($dirRoot . '/onlydirs/child'), 'child directory restored');
});
} // end FXTEST_S3_ALLOW_DESTRUCTIVE

echo "\n{$yellow}► Cleanup{$reset}\n";
test('delete uploaded file + variants', function () use ($fm, &$uploadedKey) {
    assertTrue(is_string($uploadedKey), 'no uploaded key (upload failed above)');
    safeDelete($fm, $uploadedKey);
});
// best-effort cleanup of all test artifacts
foreach ([$prefix . '/put.txt', $preKey, $GLOBALS['_dedupCopyKey'] ?? null] as $k) {
    if ($k) { try { safeDelete($fm, $k); } catch (\Throwable $e) {} }
}
// Sweep the whole run prefix, not just the pieces we remember: anything this run
// created lives under it, and the guard proves we cannot reach past it.
try { safeDeleteDirectory($dm->disk('s3test'), $prefix); } catch (\Throwable $e) {}
@unlink($tmp);

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  {$label}: Total " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

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
 * Usage:
 *   FXTEST_S3_LABEL=MinIO FXTEST_S3_BUCKET=... php tests/test-s3-live.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
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

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

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
$fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));

$prefix = 'fluxfiles-livetest';
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

echo "\n{$yellow}► Cleanup{$reset}\n";
test('delete uploaded file + variants', function () use ($fm, &$uploadedKey) {
    assertTrue(is_string($uploadedKey), 'no uploaded key (upload failed above)');
    $fm->delete('s3test', $uploadedKey);
});
// best-effort delete the put.txt
try { $fm->delete('s3test', $prefix . '/put.txt'); } catch (\Throwable $e) {}
@unlink($tmp);

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  {$label}: Total " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

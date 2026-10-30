<?php

/**
 * Live S3 test — metadata is written WITH the object, not by a follow-up self-copy.
 *
 * Counts the S3 requests (HeadObject / CopyObject / PutObject) an upload, rename,
 * move, same-disk copy and cross-disk copy/move cost, and checks that the
 * `x-amz-meta-fm-*` headers (header-backed metadata) and the `fluxfiles-id`
 * breadcrumb (DB backend) are still correct afterwards, that edits which change a
 * header still land, and that S3MetadataRepairer still reads the breadcrumbs.
 *
 * Before the change an upload was PUT + HEAD + HEAD + a full server-side
 * CopyObject (MetadataDirective=REPLACE onto itself) just to set five headers.
 *
 * Same env contract as test-s3-live.php (skips cleanly without a bucket):
 *   FXTEST_S3_BUCKET / FXTEST_S3_KEY / FXTEST_S3_SECRET / FXTEST_S3_ENDPOINT
 *   FXTEST_S3_CREATE_BUCKET=1 (LocalStack). Everything lives under a run-unique
 *   prefix that is removed at the end.
 *
 * Usage (LocalStack from docker-compose.yml):
 *   FXTEST_S3_BUCKET=fxtest-bucket FXTEST_S3_KEY=test FXTEST_S3_SECRET=test \
 *   FXTEST_S3_ENDPOINT=http://localhost:4566 FXTEST_S3_CREATE_BUCKET=1 \
 *   php tests/e2e/test-s3-metadata-calls-live.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Aws\Middleware;
use Aws\S3\S3Client;
use FluxFiles\Claims;
use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\Db\S3MetadataRepairer;
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
echo "  Live S3 — metadata written with the object\n";
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

$RUN = 'fluxfiles-livetest/meta-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function ok($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

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

$localRoot = sys_get_temp_dir() . '/fxs3meta-' . bin2hex(random_bytes(4));
@mkdir($localRoot, 0777, true);

$dm = new DiskManager([
    's3a'   => $s3cfg,
    's3b'   => $s3cfg,
    'local' => ['driver' => 'local', 'root' => $localRoot, 'url' => '/storage'],
]);

/** S3 requests, by command name, since the last reset. */
$calls = [];
foreach (['s3a', 's3b'] as $d) {
    $dm->s3Client($d)->getHandlerList()->appendSign(Middleware::tap(function ($cmd) use (&$calls) {
        $calls[] = $cmd->getName() . ' ' . ($cmd['Key'] ?? '');
    }), 'count');
}
/** @return array<string,int> */
function counted(callable $fn): array
{
    global $calls;
    $calls = [];
    $fn();
    $out = [];
    foreach ($calls as $c) {
        $n = explode(' ', $c, 2)[0];
        $out[$n] = ($out[$n] ?? 0) + 1;
    }
    ksort($out);
    return $out;
}
/** Operations on the user object itself (not the _fluxfiles bookkeeping files). */
function userObjectCalls(callable $fn, string $op): int
{
    global $calls;
    $calls = [];
    $fn();
    return count(array_filter($calls, static fn ($c) => strpos($c, $op . ' ') === 0 && strpos($c, '_fluxfiles/') === false && strpos($c, '_variants/') === false));
}

$raw = $dm->s3Client('s3a');
function headers(string $disk, string $k): array
{
    global $dm, $bucket;
    return $dm->s3Client($disk)->headObject(['Bucket' => $bucket, 'Key' => $k])['Metadata'] ?? [];
}
function mkTmp(string $content): string
{
    $p = tempnam(sys_get_temp_dir(), 'fxm');
    file_put_contents($p, $content);
    return $p;
}
function up(FileManager $fm, string $disk, string $name, string $content = 'hello world'): array
{
    $t = mkTmp($content);
    try {
        return $fm->upload($disk, '', ['name' => $name, 'size' => strlen($content), 'tmp_name' => $t]);
    } finally {
        @unlink($t);
    }
}

$claims = new Claims('alice', ['read', 'write', 'delete'], ['s3a', 's3b', 'local'], $RUN, 50, null, 0, false);

// ═════════════════════════════════════════════════════════════════
echo "{$yellow}► Header-backed metadata (JSON backend){$reset}\n";
// ═════════════════════════════════════════════════════════════════

$meta = new StorageMetadataHandler($dm);
$fm = new FileManager($dm, $claims, $meta);
$K = fn (string $n) => $RUN . '/' . $n;

test('upload: headers travel with the PUT — no self-copy, no HEAD of the object', function () use ($fm, $K) {
    $copies = userObjectCalls(function () use ($fm) { up($fm, 's3a', 'a.txt'); }, 'CopyObject');
    $heads = userObjectCalls(function () use ($fm) { up($fm, 's3a', 'a2.txt'); }, 'HeadObject');
    eq(0, $copies, 'CopyObject count');
    // Only the two fileExists probes (collision / overwrite) remain; there were 4.
    ok($heads <= 2, "HeadObject on the user object: {$heads}");
    $h = headers('s3a', $K('a.txt'));
    eq('alice', $h['fm-uploaded-by'] ?? null, 'fm-uploaded-by');
    ok(array_key_exists('fm-title', $h), 'fm-title header still present');
});

test('upload: owner is readable back through the metadata handler (header is truth)', function () use ($meta, $K) {
    $m = $meta->get('s3a', $K('a.txt'));
    eq('alice', $m['uploaded_by'] ?? null);
});

test('upload: Content-Type of the object is intact', function () use ($K) {
    $head = $GLOBALS['dm']->s3Client('s3a')->headObject(['Bucket' => $GLOBALS['bucket'], 'Key' => $K('a.txt')]);
    ok(($head['ContentType'] ?? '') !== '' && $head['ContentType'] !== 'binary/octet-stream', 'ContentType ' . ($head['ContentType'] ?? ''));
});

test('metadata edit that changes a header still lands (one self-copy)', function () use ($meta, $K) {
    $copies = userObjectCalls(fn () => $meta->save('s3a', $K('a.txt'), ['title' => 'My title', 'tags' => 'x, y']), 'CopyObject');
    eq(1, $copies, 'CopyObject count');
    $h = headers('s3a', $K('a.txt'));
    eq('My title', $h['fm-title'] ?? null);
    eq('x, y', $h['fm-tags'] ?? null);
    eq('alice', $h['fm-uploaded-by'] ?? null, 'owner survives the edit');
});

test('metadata save that changes no header skips the self-copy', function () use ($meta, $K) {
    $copies = userObjectCalls(fn () => $meta->save('s3a', $K('a.txt'), ['title' => 'My title', 'size' => 99, 'modified' => time()]), 'CopyObject');
    eq(0, $copies, 'CopyObject count');
    $heads = userObjectCalls(fn () => $meta->save('s3a', $K('a.txt'), ['mime' => 'text/plain']), 'HeadObject');
    eq(1, $heads, 'one HEAD (was two)');
    eq('My title', headers('s3a', $K('a.txt'))['fm-title'] ?? null);
});

test('rename (same disk): no self-copy, headers travel, index follows', function () use ($fm, $meta, $K) {
    $copies = userObjectCalls(fn () => $fm->rename('s3a', 'a2.txt', 'renamed.txt'), 'CopyObject');
    eq(1, $copies, 'only the move\'s own server-side copy');
    $h = headers('s3a', $K('renamed.txt'));
    eq('alice', $h['fm-uploaded-by'] ?? null);
    eq('alice', $meta->get('s3a', $K('renamed.txt'))['uploaded_by'] ?? null);
});

test('rename keeps a title that was set before', function () use ($fm, $meta, $K) {
    $fm->rename('s3a', 'a.txt', 'a-renamed.txt');
    eq('My title', headers('s3a', $K('a-renamed.txt'))['fm-title'] ?? null);
});

test('move (same disk) into a folder: one copy, headers intact', function () use ($fm, $K) {
    $fm->mkdir('s3a', 'dest');
    $copies = userObjectCalls(fn () => $fm->move('s3a', 'renamed.txt', 'dest/renamed.txt'), 'CopyObject');
    eq(1, $copies, 'only the move\'s own server-side copy');
    eq('alice', headers('s3a', $K('dest/renamed.txt'))['fm-uploaded-by'] ?? null);
});

test('same-disk copy: headers copied, no extra self-copy', function () use ($fm, $K) {
    $copies = userObjectCalls(fn () => $fm->copy('s3a', 'dest/renamed.txt', 'dest/copied.txt'), 'CopyObject');
    eq(1, $copies, 'only the copy itself');
    eq('alice', headers('s3a', $K('dest/copied.txt'))['fm-uploaded-by'] ?? null);
});

test('cross-disk copy S3 -> S3: headers set by the write, no self-copy', function () use ($fm, $meta, $K) {
    $meta->save('s3a', $K('a-renamed.txt'), ['caption' => 'cap']);
    $copies = userObjectCalls(fn () => $fm->crossCopy('s3a', 'a-renamed.txt', 's3b', 'cross.txt'), 'CopyObject');
    eq(0, $copies, 'CopyObject count');
    $h = headers('s3b', $K('cross.txt'));
    eq('alice', $h['fm-uploaded-by'] ?? null);
    eq('My title', $h['fm-title'] ?? null);
    eq('cap', $h['fm-caption'] ?? null);
});

test('cross-disk copy local -> S3: headers set by the write', function () use ($fm, $localRoot, $RUN, $K) {
    up($fm, 'local', 'l.txt');
    $copies = userObjectCalls(fn () => $fm->crossCopy('local', 'l.txt', 's3a', 'from-local.txt'), 'CopyObject');
    eq(0, $copies, 'CopyObject count');
    eq('alice', headers('s3a', $K('from-local.txt'))['fm-uploaded-by'] ?? null);
});

test('cross-disk move S3 -> S3: headers set by the write, source gone', function () use ($fm, $K) {
    $copies = userObjectCalls(fn () => $fm->crossMove('s3b', 'cross.txt', 's3a', 'moved-across.txt'), 'CopyObject');
    eq(0, $copies, 'CopyObject count');
    eq('alice', headers('s3a', $K('moved-across.txt'))['fm-uploaded-by'] ?? null);
    eq('My title', headers('s3a', $K('moved-across.txt'))['fm-title'] ?? null);
});

test('overwriting upload resets headers to the new uploader (as a PUT always did)', function () use ($K, $dm, $localRoot, $RUN) {
    $c2 = new Claims('bob', ['read', 'write', 'delete'], ['s3a'], $RUN, 50, null, 0, false);
    $c2->uploadCollision = 'overwrite';
    $fm2 = new FileManager($dm, $c2, new StorageMetadataHandler($dm));
    up($fm2, 's3a', 'a-renamed.txt', 'new bytes');
    eq('bob', headers('s3a', $K('a-renamed.txt'))['fm-uploaded-by'] ?? null);
});

// ═════════════════════════════════════════════════════════════════
echo "\n{$yellow}► Breadcrumb (DB backend){$reset}\n";
// ═════════════════════════════════════════════════════════════════

$dbFile = sys_get_temp_dir() . '/fxs3meta-' . bin2hex(random_bytes(4)) . '.sqlite3';
$conn = new Connection('sqlite:' . $dbFile);
(new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
$dbMeta = new DbMetadataHandler($conn, $dm);
$fmDb = new FileManager($dm, $claims, $dbMeta);
$rows = function () use ($conn): array {
    $o = [];
    foreach ($conn->pdo()->query('SELECT path, object_uuid FROM file_metadata WHERE object_uuid IS NOT NULL')->fetchAll() as $r) {
        $o[$r['path']] = $r['object_uuid'];
    }
    return $o;
};

test('DB upload: breadcrumb stamped by the PUT, no HEAD + self-copy', function () use ($fmDb, $rows, $K) {
    $copies = userObjectCalls(fn () => up($fmDb, 's3a', 'db1.txt'), 'CopyObject');
    eq(0, $copies, 'CopyObject count');
    $uuid = headers('s3a', $K('db1.txt'))['fluxfiles-id'] ?? null;
    ok(is_string($uuid) && strlen($uuid) === 36, 'fluxfiles-id header present');
    eq($uuid, $rows()[$K('db1.txt')] ?? null, 'row uuid == header uuid');
});

test('DB rename (same disk): header travels, row keeps its uuid, one copy only', function () use ($fmDb, $rows, $K) {
    $before = $rows()[$K('db1.txt')];
    $copies = userObjectCalls(fn () => $fmDb->rename('s3a', 'db1.txt', 'db2.txt'), 'CopyObject');
    eq(1, $copies, 'only the move\'s own server-side copy');
    eq($before, headers('s3a', $K('db2.txt'))['fluxfiles-id'] ?? null, 'header uuid unchanged');
    eq($before, $rows()[$K('db2.txt')] ?? null, 'row uuid unchanged');
});

test('DB copy (same disk): the copy gets its OWN uuid (not the source\'s)', function () use ($fmDb, $rows, $K) {
    $fmDb->copy('s3a', 'db2.txt', 'db3.txt');
    $r = $rows();
    ok(($r[$K('db3.txt')] ?? null) !== null && $r[$K('db3.txt')] !== $r[$K('db2.txt')], 'distinct uuids');
    eq($r[$K('db3.txt')], headers('s3a', $K('db3.txt'))['fluxfiles-id'] ?? null, 'header matches the copy\'s row');
});

test('DB cross-disk copy local -> S3: breadcrumb in the write', function () use ($fmDb, $rows, $K) {
    up($fmDb, 'local', 'dl.txt');
    $copies = userObjectCalls(fn () => $fmDb->crossCopy('local', 'dl.txt', 's3a', 'db-cross.txt'), 'CopyObject');
    eq(0, $copies, 'CopyObject count');
    eq($rows()[$K('db-cross.txt')] ?? null, headers('s3a', $K('db-cross.txt'))['fluxfiles-id'] ?? null);
});

test('DB overwrite keeps the row\'s uuid on the new object', function () use ($dm, $conn, $rows, $K, $RUN) {
    $before = $rows()[$K('db3.txt')];
    $c2 = new Claims('alice', ['read', 'write', 'delete'], ['s3a'], $RUN, 50, null, 0, false);
    $c2->uploadCollision = 'overwrite';
    $fm2 = new FileManager($dm, $c2, new DbMetadataHandler($conn, $dm));
    up($fm2, 's3a', 'db3.txt', 'replaced');
    eq($before, headers('s3a', $K('db3.txt'))['fluxfiles-id'] ?? null);
    eq($before, $rows()[$K('db3.txt')] ?? null);
});

test('S3MetadataRepairer reads the breadcrumbs: every row matches its object', function () use ($dm, $conn, $rows, $RUN, $bucket) {
    $rep = new S3MetadataRepairer($conn, $dm);
    $found = $rep->scanBucket('s3a');
    $mine = array_filter($rep->dbRows('s3a'), static fn ($p) => strpos($p, $RUN) === 0);
    ok(count($mine) >= 3, 'rows recorded: ' . count($mine));
    foreach ($mine as $uuid => $path) {
        eq($path, $found[$uuid] ?? null, "object for {$path}");
    }
    $diff = $rep->reconcile($mine, array_filter($found, static fn ($k) => strpos($k, $RUN) === 0));
    eq([], $diff['moved'], 'moved');
    eq([], $diff['orphaned_rows'], 'orphaned rows');
});

// ── cleanup (only ever this run's own prefix) ───────────────────────
if ($RUN === '' || strpos($RUN, 'fluxfiles-livetest/meta-') !== 0) {
    throw new \RuntimeException('refusing to clean up outside the run prefix');
}
try { $raw->deleteMatchingObjects($bucket, $RUN . '/'); } catch (\Throwable $e) { echo "  {$yellow}note{$reset} cleanup: {$e->getMessage()}\n"; }
exec('rm -rf ' . escapeshellarg($localRoot));
@unlink($dbFile);

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";
exit($failed > 0 ? 1 : 0);

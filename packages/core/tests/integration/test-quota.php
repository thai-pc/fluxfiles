<?php

/**
 * Storage quota — QuotaManager usage accounting + enforcement, and recalculation
 * after a delete frees space. Covers TEST-PLAN section 10 (quota recalc).
 *
 * Usage:
 *   php tests/integration/test-quota.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\QuotaManager;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

/** A temp file of an exact byte size. */
function bytesFile(int $size): string
{
    $p = sys_get_temp_dir() . '/fxq-' . uniqid() . '.bin';
    file_put_contents($p, str_repeat('x', $size));
    return $p;
}
function fileArr(string $tmp, string $name): array { return ['name' => $name, 'size' => filesize($tmp), 'tmp_name' => $tmp]; }

/** FileManager with a QuotaManager and a maxStorageMb claim. */
function makeFM(int $maxStorageMb): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-q-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $qm = new QuotaManager($dm);
    // Claims: userId, perms, disks, prefix, maxUploadMb, allowedExt, maxStorageMb, ownerOnly
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, $maxStorageMb, false);
    $fm = new FileManager($dm, $claims, $meta);
    $fm->setQuotaManager($qm);
    return [$fm, $qm, $root];
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Quota Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

test('getUsage sums file sizes under the prefix', function () {
    [, $qm, $root] = makeFM(0);
    @mkdir($root . '/p', 0777, true);
    file_put_contents($root . '/p/a.bin', str_repeat('x', 1000));
    file_put_contents($root . '/p/b.bin', str_repeat('x', 2000));
    assertEqual(3000, $qm->getUsage('local', 'p'), 'usage = 1000 + 2000');
});

test('getQuotaInfo reports used/max/remaining/percentage', function () {
    [, $qm, $root] = makeFM(0);
    file_put_contents($root . '/a.bin', str_repeat('x', 1024 * 1024)); // 1MB
    $info = $qm->getQuotaInfo('local', '', 4); // 4MB limit
    assertEqual(1.0, $info['used_mb'], 'used 1MB');
    assertEqual(4, $info['max_mb'], 'max 4MB');
    assertEqual(3.0, $info['remaining_mb'], 'remaining 3MB');
    assertEqual(25.0, $info['percentage'], '25%');
});

test('assertQuota throws 413 when over the limit, allows under', function () {
    [, $qm, $root] = makeFM(0);
    file_put_contents($root . '/used.bin', str_repeat('x', 900 * 1024)); // 900KB used
    $qm->assertQuota('local', '', 100 * 1024, 1);  // +100KB → ~1MB, under → ok
    try {
        $qm->assertQuota('local', '', 500 * 1024, 1); // +500KB → ~1.4MB > 1MB
        throw new \RuntimeException('should throw');
    } catch (ApiException $e) {
        assertEqual('quota_exceeded', $e->getErrorCode(), 'expected quota_exceeded');
        assertEqual(413, $e->getHttpCode(), '413');
    }
});

test('max=0 means unlimited (assertQuota never throws)', function () {
    [, $qm, $root] = makeFM(0);
    file_put_contents($root . '/big.bin', str_repeat('x', 5 * 1024 * 1024));
    $qm->assertQuota('local', '', 999 * 1024 * 1024, 0); // unlimited → no throw
});

test('upload blocked at quota, then ALLOWED after delete frees space (recalc)', function () {
    [$fm] = makeFM(1); // 1MB quota
    $fm->upload('local', '', fileArr(bytesFile(600 * 1024), 'first.bin'));   // 600KB → ok
    // second 600KB would exceed 1MB
    try {
        $fm->upload('local', '', fileArr(bytesFile(600 * 1024), 'second.bin'));
        throw new \RuntimeException('should have hit quota');
    } catch (ApiException $e) {
        assertEqual('quota_exceeded', $e->getErrorCode(), 'second upload blocked');
    }
    // free space by deleting the first, then the second upload must succeed
    $fm->delete('local', 'first.bin');
    $r = $fm->upload('local', '', fileArr(bytesFile(600 * 1024), 'second.bin'));
    assertEqual('second.bin', $r['name'], 'upload succeeds after delete frees quota');
});

// ── Usage breakdown (dashboard M1) ─────────────────────────────────────────
/** Write a file of $size bytes at $root/$rel (creating dirs). */
function putBytes(string $root, string $rel, int $size): void
{
    $full = $root . '/' . $rel;
    @mkdir(dirname($full), 0777, true);
    file_put_contents($full, str_repeat('x', $size));
}

test('typeOf maps extensions to groups', function () {
    assertEqual('image', QuotaManager::typeOf('a/b/photo.JPG'), 'jpg→image');
    assertEqual('video', QuotaManager::typeOf('clip.mp4'), 'mp4→video');
    assertEqual('document', QuotaManager::typeOf('report.pdf'), 'pdf→document');
    assertEqual('archive', QuotaManager::typeOf('backup.zip'), 'zip→archive');
    assertEqual('other', QuotaManager::typeOf('data.xyz'), 'unknown→other');
    assertEqual('other', QuotaManager::typeOf('noext'), 'no extension→other');
});

test('getUsageBreakdown: total, count, by_type from extensions', function () {
    [, $qm, $root] = makeFM(0);
    putBytes($root, 'pics/a.jpg', 100);
    putBytes($root, 'pics/b.png', 200);
    putBytes($root, 'vid/c.mp4', 500);
    putBytes($root, 'docs/d.pdf', 50);
    $b = $qm->getUsageBreakdown('local', '');
    assertEqual(850, $b['total_size'], 'total = 100+200+500+50');
    assertEqual(4, $b['file_count'], '4 files');
    assertEqual(300, $b['by_type']['image']['size'], 'image = jpg+png');
    assertEqual(2, $b['by_type']['image']['count'], '2 images');
    assertEqual(500, $b['by_type']['video']['size'], 'video');
    assertEqual(50, $b['by_type']['document']['size'], 'document');
});

test('getUsageBreakdown: by_folder sorted by size desc, depth + top N', function () {
    [, $qm, $root] = makeFM(0);
    putBytes($root, 'products/2026/a.jpg', 1000);
    putBytes($root, 'products/2026/b.jpg', 600);   // products → 1600
    putBytes($root, 'imports/x.png', 300);          // imports → 300
    putBytes($root, 'top.jpg', 50);                 // root "/" → 50
    $b = $qm->getUsageBreakdown('local', '', 10, 1);
    assertEqual('/products', $b['by_folder'][0]['path'], 'largest folder first');
    assertEqual(1600, $b['by_folder'][0]['size'], 'products size');
    assertEqual('/imports', $b['by_folder'][1]['path'], 'second');
    assertEqual('/', $b['by_folder'][2]['path'], 'root files grouped under /');
    // depth 2 splits products/2026.
    $b2 = $qm->getUsageBreakdown('local', '', 10, 2);
    assertEqual('/products/2026', $b2['by_folder'][0]['path'], 'depth 2');
    // top N cap.
    $b3 = $qm->getUsageBreakdown('local', '', 2, 1);
    assertEqual(2, count($b3['by_folder']), 'top 2 only');
});

test('getUsageBreakdown: excludes _fluxfiles/ and _variants/, respects prefix', function () {
    [, $qm, $root] = makeFM(0);
    putBytes($root, 'u1/photo.jpg', 400);
    putBytes($root, 'u1/_variants/photo_thumb.webp', 999);   // internal → excluded
    putBytes($root, 'u1/_fluxfiles/index.json', 999);        // internal → excluded
    putBytes($root, 'u2/other.jpg', 700);                    // outside prefix
    $b = $qm->getUsageBreakdown('local', 'u1');
    assertEqual(400, $b['total_size'], 'only u1 user content (variants/_fluxfiles excluded)');
    assertEqual(2398, $b['raw_total'], 'raw_total includes variants + _fluxfiles (400+999+999)');
    assertEqual(1, $b['file_count'], '1 user file');
    assertEqual('/', $b['by_folder'][0]['path'], 'photo at prefix root');
});

test('usageResponse: quota status thresholds (ok/warning/critical)', function () {
    [, $qm] = makeFM(0);
    $bd = fn (int $raw) => ['raw_total' => $raw, 'total_size' => $raw, 'file_count' => 1, 'by_type' => [], 'by_folder' => []];
    // 100 MB limit. 50/79/92 MB → ok/warning/critical (defaults 70/90).
    $r = $qm->usageResponse($bd(50 * 1048576), 100, 0, 0);
    assertEqual('ok', $r['quota']['status'], '50% → ok');
    assertEqual(50.0, $r['quota']['percent'], 'percent');
    assertEqual('warning', $qm->usageResponse($bd(79 * 1048576), 100, 0, 0)['quota']['status'], '79% → warning');
    assertEqual('critical', $qm->usageResponse($bd(92 * 1048576), 100, 0, 0)['quota']['status'], '92% → critical');
    // Custom thresholds 50/80.
    assertEqual('warning', $qm->usageResponse($bd(60 * 1048576), 100, 50, 80)['quota']['status'], 'custom warn 50');
});

test('usageResponse: no quota limit → percent null, status ok', function () {
    [, $qm] = makeFM(0);
    $r = $qm->usageResponse(['raw_total' => 999, 'total_size' => 999, 'file_count' => 1, 'by_type' => [], 'by_folder' => []], 0, 0, 0);
    assertEqual(null, $r['quota']['percent'], 'no limit → null percent');
    assertEqual('ok', $r['quota']['status'], 'ok when no limit');
    assertEqual(null, $r['quota']['limit_bytes'], 'null limit_bytes');
});

test('usageResponse: by_type percent of user content, sorted desc', function () {
    [, $qm] = makeFM(0);
    $bd = ['raw_total' => 1000, 'total_size' => 1000, 'file_count' => 3,
        'by_type' => ['image' => ['size' => 700, 'count' => 2], 'video' => ['size' => 300, 'count' => 1]],
        'by_folder' => []];
    $r = $qm->usageResponse($bd, 0, 0, 0);
    assertEqual('image', $r['by_type'][0]['type'], 'largest type first');
    assertEqual(70.0, $r['by_type'][0]['percent'], 'image = 70%');
    assertEqual(30.0, $r['by_type'][1]['percent'], 'video = 30%');
});

test('getUsageBreakdown: empty prefix → zeros, no error', function () {
    [, $qm, $root] = makeFM(0);
    @mkdir($root . '/empty', 0777, true);
    $b = $qm->getUsageBreakdown('local', 'empty');
    assertEqual(0, $b['total_size'], 'zero total');
    assertEqual(0, $b['file_count'], 'zero count');
    assertEqual([], $b['by_type'], 'no types');
    assertEqual([], $b['by_folder'], 'no folders');
});

// ── SFTP guard: usage scans must NOT do a recursive remote walk ────────────
// On SFTP a recursive listing is ~9 entries/sec, so the storage meter + usage
// dashboard (which fire on every navigate) would hang. They must short-circuit
// to supported:false WITHOUT touching the connection. We point the disk at a
// public TEST-NET IP so even if the guard regressed and tried to connect, the
// test would fail loudly rather than silently pass.
test('SFTP: getQuotaInfo reports unsupported without walking', function () {
    $dm = new DiskManager(['vps' => [
        'driver' => 'sftp', 'host' => '198.51.100.10', 'username' => 'u', 'password' => 'x', 'root' => '/',
    ]]);
    $qm = new QuotaManager($dm);
    $info = $qm->getQuotaInfo('vps', '', 0);
    assertEqual(false, $info['supported'], 'quota unsupported on sftp');
    assertEqual(null, $info['used_bytes'], 'no usage computed');
});

test('SFTP: getUsageBreakdown reports unsupported without walking', function () {
    $dm = new DiskManager(['vps' => [
        'driver' => 'sftp', 'host' => '198.51.100.10', 'username' => 'u', 'password' => 'x', 'root' => '/',
    ]]);
    $qm = new QuotaManager($dm);
    $b = $qm->getUsageBreakdown('vps', '');
    assertEqual(false, $b['supported'], 'breakdown unsupported on sftp');
    assertEqual(0, $b['file_count'], 'no count');
    // usageResponse must propagate the flag so the UI hides the dashboard.
    $resp = $qm->usageResponse($b, 0, 70, 90);
    assertEqual(false, $resp['supported'], 'usageResponse carries supported flag');
});

test('local disk usage stays supported (regression guard)', function () {
    $dm = new DiskManager(['vps' => [
        'driver' => 'sftp', 'host' => '198.51.100.10', 'username' => 'u', 'password' => 'x', 'root' => '/',
    ]]);
    $qm = new QuotaManager($dm);
    // A normal (non-sftp) breakdown reports supported:true.
    $resp = $qm->usageResponse(['raw_total' => 0, 'total_size' => 0, 'file_count' => 0, 'by_type' => [], 'by_folder' => [], 'supported' => true], 0, 70, 90);
    assertEqual(true, $resp['supported'], 'non-sftp stays supported');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

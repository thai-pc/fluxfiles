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

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

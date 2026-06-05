<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\BucketDoctor;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

echo "\n{$cyan}══ FluxFiles Bucket Doctor (local) ══{$reset}\n\n";

test('local disk → writable probe, summary ok, no driver-specific remediation', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-doctor-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);

    $report = (new BucketDoctor($dm))->diagnose('local');

    assertEqual('local', $report['driver'], 'driver is local');
    assertEqual('ok', $report['summary'], 'summary ok');
    $ids = array_column($report['checks'], 'id');
    assertTrue(in_array('write', $ids, true), 'has a write check');
    $write = array_values(array_filter($report['checks'], fn($c) => $c['id'] === 'write'))[0];
    assertEqual('ok', $write['status'], 'write check passed');
    assertEqual([], $report['remediation'], 'no S3 remediation for local');
});

test('report shape is stable (disk, driver, summary, checks[], remediation)', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-doctor-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);

    $report = (new BucketDoctor($dm))->diagnose('local');
    foreach (['disk', 'driver', 'summary', 'checks', 'remediation'] as $k) {
        assertTrue(array_key_exists($k, $report), "report has {$k}");
    }
    foreach ($report['checks'] as $c) {
        foreach (['id', 'status', 'message'] as $k) { assertTrue(array_key_exists($k, $c), "check has {$k}"); }
        assertTrue(in_array($c['status'], ['ok', 'warn', 'fail', 'info'], true), 'valid status');
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

<?php

/**
 * FixityChecker via FileManager::runFixityCheck().
 *
 * Usage: php tests/unit/test-fixity.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function fxEnv(): array {
    $base = sys_get_temp_dir() . '/fluxfiles-fx-' . uniqid();
    @mkdir("{$base}/t/docs", 0777, true);
    foreach (['a.txt' => 'alpha', 'b.txt' => 'bravo', 'docs/c.txt' => 'charlie', 'd.txt' => 'delta', 'e.txt' => 'echo'] as $k => $v) {
        file_put_contents("{$base}/t/{$k}", $v);
    }
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $base, 'url' => '/s']]);
    $c = new Claims('auditor', ['read', 'audit'], ['local'], 't', 50, null, 0, false);
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $base];
}

echo "\n{$cyan}══ Fixity check ══{$reset}\n\n";

test('first pass baselines every file; a second pass with no change is clean', function () {
    [$fm] = fxEnv();
    $r = $fm->runFixityCheck('local', []);
    assertTrue($r['baselined'] === 5 && $r['complete'] && $r['ok'], json_encode($r));
    $r = $fm->runFixityCheck('local', []);
    assertTrue($r['unchanged'] === 5 && $r['ok'], json_encode($r));
});

test('changes/deletions are split into explained (audited) and unexplained', function () {
    [$fm, $base] = fxEnv();
    $fm->runFixityCheck('local', []);
    sleep(1);
    file_put_contents("{$base}/t/a.txt", 'tampered');      // behind FluxFiles' back
    file_put_contents("{$base}/t/b.txt", 'edited in app');  // matching audit entry below
    unlink("{$base}/t/d.txt");                              // behind FluxFiles' back
    unlink("{$base}/t/e.txt");                              // trashed in the app
    $audit = [
        ['action' => 'content_update', 'file_key' => 't/b.txt', 'created_at' => time()],
        ['action' => 'trash', 'file_key' => 't/e.txt', 'created_at' => time()],
        ['action' => 'access_denied', 'file_key' => 't/a.txt', 'created_at' => time()], // not a write
    ];
    $r = $fm->runFixityCheck('local', $audit);
    assertTrue($r['changed_unexplained'] === ['a.txt'], 'tampered: ' . json_encode($r));
    assertTrue($r['changed_explained'] === ['b.txt'], 'app edit');
    assertTrue($r['missing_unexplained'] === ['d.txt'] && $r['missing_explained'] === ['e.txt'], 'deletions');
    assertTrue($r['ok'] === false, 'not ok');
    // The alert fires once: the baseline now holds the new bytes.
    $r = $fm->runFixityCheck('local', $audit);
    assertTrue($r['ok'] && $r['unchanged'] === 3, 'second run clean: ' . json_encode($r));
});

test('a byte budget spreads one round over several calls via the cursor', function () {
    [$fm] = fxEnv();
    $r1 = $fm->runFixityCheck('local', [], 6);   // ~one 5-byte file per call
    assertTrue($r1['complete'] === false && $r1['checked'] >= 1, json_encode($r1));
    $total = $r1['checked'];
    for ($i = 0; $i < 10 && empty($r['complete']); $i++) {
        $r = $fm->runFixityCheck('local', [], 6);
        $total += $r['checked'];
    }
    assertTrue(!empty($r['complete']) && $total === 5, "all 5 covered exactly once ({$total})");
});

test('the manifest lives in the tenant\'s own _fluxfiles and is never itself checked', function () {
    [$fm, $base] = fxEnv();
    $fm->runFixityCheck('local', []);
    assertTrue(is_file("{$base}/t/_fluxfiles/fixity.json"), 'manifest stored under the prefix');
    $r = $fm->runFixityCheck('local', []);
    assertTrue($r['unchanged'] === 5 && $r['baselined'] === 0, 'manifest not counted');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

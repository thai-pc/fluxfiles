<?php

/**
 * Retention rules — Claims::sanitizeRetentionRules() + FileManager::retentionRules().
 * The sweep itself is the legal-hold module's (packages/legal-hold/tests/test-retention.php).
 *
 * Usage: php tests/unit/test-retention.php
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

/** Files under prefix `t`; ages in days via mtime. @return array{0:FileManager,1:StorageMetadataHandler,2:string} */
function rtEnv(array $rules, array $perms = ['read', 'write', 'delete']): array {
    $base = sys_get_temp_dir() . '/fluxfiles-rt-' . uniqid();
    $files = ['t/invoices/2019.pdf' => 400, 't/invoices/2026.pdf' => 10, 't/invoices/.hidden' => 400,
        't/tmp/a.log' => 40, 't/tmp/b.log' => 5, 't/keep/old.txt' => 900];
    foreach ($files as $rel => $days) {
        @mkdir(dirname("{$base}/{$rel}"), 0777, true);
        file_put_contents("{$base}/{$rel}", $rel);
        touch("{$base}/{$rel}", time() - $days * 86400);
    }
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $base, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    $c = new Claims('u', $perms, ['local'], 't', 50, null, 0, false);
    $c->retentionRules = Claims::sanitizeRetentionRules($rules);
    return [new FileManager($dm, $c, $meta), $meta, $base];
}
const RULES = [['path' => 'invoices', 'days' => 365, 'action' => 'trash'], ['path' => 'tmp', 'days' => 30, 'action' => 'delete']];

echo "\n{$cyan}══ Retention rules ══{$reset}\n\n";

test('sanitize: drops bad rules, defaults action, strips traversal, caps at 20', function () {
    $r = Claims::sanitizeRetentionRules([['path' => '../x', 'days' => 3], ['days' => 0], ['path' => 'y', 'days' => 9, 'action' => 'nuke'], 'junk']);
    assertTrue($r === [['path' => 'x', 'days' => 3, 'action' => 'trash'], ['path' => 'y', 'days' => 9, 'action' => 'trash']], json_encode($r));
    assertTrue(count(Claims::sanitizeRetentionRules(array_fill(0, 30, ['days' => 1]))) === 20, 'capped');
    assertTrue(Claims::fromJwtPayload((object) ['sub' => 'u'])->retentionRules === [], 'default none');
});

test('retentionRules() returns the token\'s sanitised rules', function () {
    [$fm] = rtEnv(RULES);
    assertTrue($fm->retentionRules() === Claims::sanitizeRetentionRules(RULES), json_encode($fm->retentionRules()));
    [$none] = rtEnv([]);
    assertTrue($none->retentionRules() === [], 'empty');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

<?php

/**
 * Retention rules — FileManager::runRetention() + Claims::sanitizeRetentionRules().
 *
 * Usage: php tests/unit/test-retention.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
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

test('dry run reports exactly the expired files and changes nothing', function () {
    [$fm, , $base] = rtEnv(RULES);
    $r = $fm->runRetention('local', true);
    $got = array_map(fn ($i) => $i['path'] . '=' . $i['result'], $r['items']);
    assertTrue($got === ['invoices/2019.pdf=would_trash', 'tmp/a.log=would_delete'], json_encode($got));
    assertTrue(is_file("{$base}/t/invoices/2019.pdf") && is_file("{$base}/t/tmp/a.log"), 'untouched');
});

test('apply: trash rule → restorable trash, delete rule → gone; fresh files and other paths kept', function () {
    [$fm, , $base] = rtEnv(RULES);
    $r = $fm->runRetention('local', false);
    assertTrue($r['acted'] === 2 && $r['errors'] === 0, json_encode($r));
    assertTrue(!is_file("{$base}/t/invoices/2019.pdf") && !is_file("{$base}/t/tmp/a.log"), 'acted');
    assertTrue(is_file("{$base}/t/invoices/2026.pdf") && is_file("{$base}/t/tmp/b.log") && is_file("{$base}/t/keep/old.txt"), 'kept');
    assertTrue(count($fm->listTrash('local')) === 1, 'the trashed invoice is restorable');
});

test('a legal hold always wins over retention', function () {
    [$fm, $meta, $base] = rtEnv(RULES);
    $meta->addHold('local', 'h1', ['path' => 't/invoices', 'placed_at' => time(), 'released_at' => null]);
    $r = $fm->runRetention('local', false);
    assertTrue($r['held'] === 1 && is_file("{$base}/t/invoices/2019.pdf"), json_encode($r));
});

test('needs the delete permission; a per-call cap truncates', function () {
    [$fm] = rtEnv(RULES, ['read', 'write']);
    try { $fm->runRetention('local', true); throw new \RuntimeException('expected 403'); }
    catch (ApiException $e) { assertTrue($e->getErrorCode() === 'permission_denied', (string) $e->getErrorCode()); }
    [$fm] = rtEnv(RULES);
    $r = $fm->runRetention('local', true, 1);
    assertTrue($r['truncated'] && count($r['items']) === 1 && $r['matched'] === 2, json_encode($r));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

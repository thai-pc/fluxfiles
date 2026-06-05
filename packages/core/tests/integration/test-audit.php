<?php

/**
 * Audit log — `AuditLogStorage` writes JSONL to `_fluxfiles/audit.jsonl` in the
 * user's own storage and reads it back, with per-user filtering, ordering, and
 * pagination. Covers TEST-PLAN section 10 (audit).
 *
 * Usage:
 *   php tests/integration/test-audit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\AuditLogStorage;

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

/** Fresh audit storage over a temp local disk. */
function makeAudit(array $disks = ['local']): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-audit-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $audit = new AuditLogStorage($meta, $disks);
    return [$audit, $dm->disk('local'), $root];
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Audit Log Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

test('log then list round-trips with action + file_key + user_id', function () {
    [$audit, $fs] = makeAudit();
    $audit->log('user-1', 'upload', 'local', 'photos/a.png', '1.2.3.4', 'TestAgent');
    assertTrue($fs->fileExists('_fluxfiles/audit.jsonl'), 'audit.jsonl written');
    $rows = $audit->list();
    assertEqual(1, count($rows), 'one entry');
    $e = $rows[0];
    assertEqual('user-1', $e['user_id'], 'user_id');
    assertEqual('upload', $e['action'], 'action');
    assertEqual('photos/a.png', $e['file_key'], 'file_key');
    assertEqual('1.2.3.4', $e['ip'], 'ip');
    assertTrue(($e['created_at'] ?? 0) > 0, 'timestamp set');
});

test('entries are returned newest-first', function () {
    [$audit] = makeAudit();
    $audit->log('u', 'upload', 'local', 'a.txt');
    usleep(1100000); // audit timestamps are second-resolution; ensure a later second
    $audit->log('u', 'delete', 'local', 'b.txt');
    $rows = $audit->list();
    assertEqual(2, count($rows), 'two entries');
    assertEqual('delete', $rows[0]['action'], 'newest (delete) first');
});

test('filter by user_id returns only that user', function () {
    [$audit] = makeAudit();
    $audit->log('alice', 'upload', 'local', 'a.txt');
    $audit->log('bob', 'upload', 'local', 'b.txt');
    $audit->log('alice', 'delete', 'local', 'c.txt');
    $all = $audit->list(100, 0, null);
    assertEqual(3, count($all), 'all entries');
    $alice = $audit->list(100, 0, 'alice');
    assertEqual(2, count($alice), 'only alice');
    foreach ($alice as $e) { assertEqual('alice', $e['user_id'], 'all alice'); }
});

test('audit is scoped to the caller\'s path prefix (no cross-tenant leak)', function () {
    [$audit] = makeAudit();
    $audit->log('tenant-42', 'upload', 'local', 'users/42/a.png');
    $audit->log('tenant-99', 'delete', 'local', 'users/99/secret.png');
    $audit->log('tenant-42', 'mkdir',  'local', ''); // keyless entry

    // Scoped tenant: even with userId=null (would otherwise see all), the prefix
    // must hide users/99 AND the keyless entry (default-deny).
    $scoped = new \FluxFiles\Claims('tenant-42', ['read'], ['local'], 'users/42', 10, null, 0, false);
    $rows = $audit->list(100, 0, null, $scoped);
    assertEqual(1, count($rows), 'only the in-scope entry is visible');
    assertEqual('users/42/a.png', $rows[0]['file_key'], 'and it is the tenant\'s own file');

    // Sibling-prefix confusion (users/42 vs users/420) must not leak.
    $audit->log('tenant-420', 'upload', 'local', 'users/420/x.png');
    assertEqual(1, count($audit->list(100, 0, null, $scoped)), 'users/420 not in users/42 scope');

    // Empty prefix (admin) = no scoping → sees everything.
    $admin = new \FluxFiles\Claims('admin', ['read'], ['local'], '', 10, null, 0, false);
    assertEqual(4, count($audit->list(100, 0, null, $admin)), 'empty prefix = no scoping');
});

test('limit + offset paginate the audit log', function () {
    [$audit] = makeAudit();
    for ($i = 0; $i < 5; $i++) { $audit->log('u', 'act' . $i, 'local', "f{$i}.txt"); }
    $page1 = $audit->list(2, 0);
    $page2 = $audit->list(2, 2);
    assertEqual(2, count($page1), 'page1 size');
    assertEqual(2, count($page2), 'page2 size');
    assertTrue($page1[0]['file_key'] !== $page2[0]['file_key'], 'pages differ');
});

test('many writes rotate but keep the log readable', function () {
    [$audit] = makeAudit();
    for ($i = 0; $i < 200; $i++) { $audit->log('u', 'op', 'local', "file{$i}.txt"); }
    $rows = $audit->list(1000, 0);
    assertTrue(count($rows) > 0, 'log still readable after many writes');
    assertEqual('op', $rows[0]['action'], 'entries intact');
});

test('reading audit on a disk with no log yields empty (graceful)', function () {
    [$audit] = makeAudit();
    assertEqual([], $audit->list(), 'no log → empty list, no error');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

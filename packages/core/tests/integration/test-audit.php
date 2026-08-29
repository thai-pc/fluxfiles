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
    return [$audit, $dm->disk('local'), $root, $meta];
}

/** One synthetic audit JSONL line, old-enough or fresh depending on $ts. */
function auditLine(int $ts, string $userId, string $fileKey): string
{
    return json_encode(['ts' => $ts, 'action' => 'upload', 'context' => ['user_id' => $userId, 'file_key' => $fileKey]]);
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

test('rotation archives the dropped tail instead of discarding it', function () {
    [$audit, $fs, $root, $meta] = makeAudit();

    // Pre-seed a live log big enough to force audit()'s size-based rotation
    // (MAX_AUDIT_BYTES = 5MB), well past AUDIT_KEEP_LINES (5000) too, so both
    // thresholds actually engage. Written directly to bypass 50k+ slow log()
    // calls — audit() only cares what's already on disk when it runs.
    $sample = auditLine(1_700_000_000, 'rot-user', 'rot/file-00000.png');
    $lineLen = strlen($sample) + 1; // + newline
    $keepLines = 5000;
    $n = intdiv(5 * 1024 * 1024, $lineLen) + $keepLines + 500;
    $lines = [];
    for ($i = 0; $i < $n; $i++) {
        $lines[] = auditLine(1_700_000_000 + $i, 'rot-user', sprintf('rot/file-%05d.png', $i));
    }
    $fs->write('_fluxfiles/audit.jsonl', implode("\n", $lines) . "\n");
    assertTrue(strlen($fs->read('_fluxfiles/audit.jsonl')) > 5 * 1024 * 1024, 'seed file exceeds the rotation threshold');

    $audit->log('rotator', 'trigger-rotate', 'local', 'trigger.txt');

    $archived = $meta->readAuditArchive('local');
    $droppedCount = $n - $keepLines;
    assertEqual($droppedCount, count($archived), 'every dropped line landed in the archive, none silently lost');
    assertTrue($fs->directoryExists('_fluxfiles/audit/archive/'), 'archive directory created');

    $live = $meta->readAudit('local');
    assertEqual($keepLines + 1, count($live), 'live log keeps the last N lines plus the new entry');
    $liveKeys = array_column($live, 'file_key');
    assertTrue(in_array('trigger.txt', $liveKeys, true), 'the entry that triggered rotation is in the live log');
    assertTrue(in_array(sprintf('rot/file-%05d.png', $n - 1), $liveKeys, true), 'the newest pre-existing entry survived rotation');
    assertTrue(!in_array(sprintf('rot/file-%05d.png', 0), $liveKeys, true), 'the oldest pre-existing entry was rotated out of the live log');

    $archivedKeys = array_column($archived, 'file_key');
    assertTrue(in_array(sprintf('rot/file-%05d.png', 0), $archivedKeys, true), 'the oldest entry is preserved in the archive');
    assertTrue(!in_array(sprintf('rot/file-%05d.png', $n - 1), $archivedKeys, true), 'kept lines are not duplicated into the archive');
});

test('readAuditArchive merges entries across multiple archive files', function () {
    [, $fs, , $meta] = makeAudit();
    $fs->write('_fluxfiles/audit/archive/audit-1000-aaa.jsonl', auditLine(1000, 'u', 'a.txt') . "\n");
    $fs->write('_fluxfiles/audit/archive/audit-2000-bbb.jsonl', auditLine(2000, 'u', 'b.txt') . "\n" . auditLine(2001, 'u', 'c.txt') . "\n");

    $entries = $meta->readAuditArchive('local');
    assertEqual(3, count($entries), 'entries from both archive files are merged');
    $keys = array_column($entries, 'file_key');
    sort($keys);
    assertEqual(['a.txt', 'b.txt', 'c.txt'], $keys, 'all three archived entries present');
});

test('purgeAuditBefore deletes fully-old archives, keeps mixed ones, trims the live log', function () {
    [$audit, $fs, , $meta] = makeAudit();
    $cutoff = 5000;

    // Fully old archive → deleted entirely.
    $fs->write('_fluxfiles/audit/archive/audit-old.jsonl', auditLine(1000, 'u', 'old1.txt') . "\n" . auditLine(2000, 'u', 'old2.txt') . "\n");
    // Mixed archive (one old, one new line) → must survive untouched; purge only
    // drops archives that are ENTIRELY older than the cutoff.
    $fs->write('_fluxfiles/audit/archive/audit-mixed.jsonl', auditLine(1500, 'u', 'mixed-old.txt') . "\n" . auditLine(9000, 'u', 'mixed-new.txt') . "\n");
    // Live log: two old lines, one new line.
    $fs->write('_fluxfiles/audit.jsonl', auditLine(1200, 'u', 'live-old-1.txt') . "\n" . auditLine(3000, 'u', 'live-old-2.txt') . "\n" . auditLine(9999, 'u', 'live-new.txt') . "\n");

    $result = $meta->purgeAuditBefore('local', $cutoff);
    assertEqual(1, $result['archives_deleted'], 'only the fully-old archive is deleted');
    assertEqual(2, $result['live_lines_removed'], 'both old live lines removed');

    assertTrue(!$fs->fileExists('_fluxfiles/audit/archive/audit-old.jsonl'), 'fully-old archive file is gone');
    assertTrue($fs->fileExists('_fluxfiles/audit/archive/audit-mixed.jsonl'), 'mixed archive file survives');

    $remainingArchived = $meta->readAuditArchive('local');
    assertEqual(2, count($remainingArchived), 'the surviving mixed archive still has both its lines (purge is file-granular, not line-granular for archives)');

    $liveKeys = array_column($meta->readAudit('local'), 'file_key');
    assertEqual(['live-new.txt'], $liveKeys, 'only the new live line remains');

    // The audit lock must be released cleanly — a subsequent write must not stall.
    $audit->log('u', 'post-purge-write', 'local', 'after.txt');
    assertTrue(in_array('after.txt', array_column($meta->readAudit('local'), 'file_key'), true), 'writes after purge still succeed (lock released)');
});

test('exportAll merges live + archived entries with the same scoping/filters as list()', function () {
    [$audit, $fs, , $meta] = makeAudit();
    $fs->write('_fluxfiles/audit/archive/audit-a.jsonl', json_encode(['ts' => 1000, 'action' => 'upload', 'context' => ['user_id' => 'tenant-42', 'file_key' => 'users/42/old.png']]) . "\n");
    $audit->log('tenant-42', 'delete', 'local', 'users/42/new.png');
    $audit->log('tenant-99', 'upload', 'local', 'users/99/secret.png');

    $all = $audit->exportAll();
    assertEqual(3, count($all), 'export merges live + archive across all entries');

    $scoped = new \FluxFiles\Claims('tenant-42', ['read'], ['local'], 'users/42', 10, null, 0, false);
    $scopedRows = $audit->exportAll($scoped);
    assertEqual(2, count($scopedRows), 'export respects tenant path-prefix scoping');
    foreach ($scopedRows as $e) {
        assertTrue(strpos($e['file_key'], 'users/42/') === 0, 'only in-scope entries returned');
    }

    $filtered = $audit->exportAll(null, ['action' => 'delete']);
    assertEqual(1, count($filtered), 'export respects the action filter');
    assertEqual('users/42/new.png', $filtered[0]['file_key'], 'the matching entry');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

<?php

/**
 * Tamper-evident audit chain (AuditChain) on both backends.
 *
 * JSON (`_fluxfiles/audit.jsonl` + archives) and the SQLite `db` backend: a clean
 * log verifies; editing a line, inserting a forged one, deleting from the middle
 * or reordering is reported; a purge of the oldest entries and a rotation into
 * archives are NOT false alarms; and a chain migrated JSON → DB still verifies
 * and keeps growing there.
 *
 * Usage: php tests/unit/test-audit-chain.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\AuditChain;
use FluxFiles\Db\Connection;
use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\JsonToDbMigrator;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

AuditChain::configure(str_repeat('k', 40));

/** @return array{0:StorageMetadataHandler,1:string} */
function jsonEnv(): array {
    $root = sys_get_temp_dir() . '/fluxfiles-chain-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    return [new StorageMetadataHandler($dm), $root];
}
function writeN($repo, int $n, string $tag = 'e'): void {
    for ($i = 0; $i < $n; $i++) {
        $repo->audit('local', 'upload', ['user_id' => 'u' . ($i % 3), 'file_key' => "{$tag}/{$i}.txt", 'ip' => '10.0.0.1', 'user_agent' => 'ua', 'detail' => null]);
    }
}
function verifyRepo($repo): array {
    return AuditChain::verify(array_merge($repo->readAuditArchive('local'), $repo->readAudit('local')));
}
function liveLines(string $root): array {
    return array_values(array_filter(explode("\n", (string) file_get_contents($root . '/_fluxfiles/audit.jsonl'))));
}
function putLines(string $root, array $lines): void {
    file_put_contents($root . '/_fluxfiles/audit.jsonl', implode("\n", $lines) . "\n");
}

echo "\n{$cyan}══ Audit chain ══{$reset}\n\n";

test('JSON: a clean log verifies; every entry is chained', function () {
    [$repo] = jsonEnv();
    writeN($repo, 12);
    $r = verifyRepo($repo);
    assertTrue($r['ok'] && $r['chained'] === 12 && $r['unchained'] === 0 && $r['gaps'] === 0, json_encode($r));
    assertTrue(is_string($r['head']) && strlen($r['head']) === 64, 'head reported');
});

test('JSON: editing one field of one line is detected', function () {
    [$repo, $root] = jsonEnv();
    writeN($repo, 6);
    $lines = liveLines($root);
    $row = json_decode($lines[2], true);
    $row['context']['file_key'] = 'innocent.txt';
    $lines[2] = json_encode($row);
    putLines($root, $lines);
    $r = verifyRepo($repo);
    assertTrue(!$r['ok'] && count($r['tampered']) === 1, json_encode($r));
});

test('JSON: deleting a line from the middle is detected (gap)', function () {
    [$repo, $root] = jsonEnv();
    writeN($repo, 6);
    $lines = liveLines($root);
    array_splice($lines, 3, 1);
    putLines($root, $lines);
    $r = verifyRepo($repo);
    assertTrue(!$r['ok'] && $r['gaps'] === 1 && $r['tampered'] === [], json_encode($r));
});

test('JSON: a forged line without the key is detected', function () {
    [$repo, $root] = jsonEnv();
    writeN($repo, 4);
    $lines = liveLines($root);
    $last = json_decode(end($lines), true);
    $lines[] = json_encode(['ts' => time(), 'action' => 'delete', 'context' => ['user_id' => 'x'],
        'prev' => $last['hash'], 'hash' => hash('sha256', 'guess')]);
    putLines($root, $lines);
    $r = verifyRepo($repo);
    assertTrue(!$r['ok'] && count($r['tampered']) === 1, json_encode($r));
});

test('JSON: re-chaining edits with a WRONG key is still detected', function () {
    [$repo, $root] = jsonEnv();
    writeN($repo, 3);
    $evilKey = hash_hkdf('sha256', 'not-the-secret', 32, 'fluxfiles-audit-chain');
    $prev = '';
    $lines = [];
    foreach (liveLines($root) as $line) {
        $row = json_decode($line, true);
        $row['context']['user_id'] = 'someone-else';
        $c = $row['context'];
        $row['prev'] = $prev;
        $row['hash'] = AuditChain::hash($evilKey, $prev, ['ts' => $row['ts'], 'action' => $row['action'],
            'user_id' => $c['user_id'], 'file_key' => $c['file_key'], 'ip' => $c['ip'], 'user_agent' => $c['user_agent'], 'detail' => $c['detail'] ?? null]);
        $prev = $row['hash'];
        $lines[] = json_encode($row);
    }
    putLines($root, $lines);
    $r = verifyRepo($repo);
    assertTrue(!$r['ok'] && count($r['tampered']) === 3, json_encode($r));
});

test('JSON: swapping two lines is detected', function () {
    [$repo, $root] = jsonEnv();
    writeN($repo, 5);
    $lines = liveLines($root);
    [$lines[1], $lines[2]] = [$lines[2], $lines[1]];
    // Order in the file alone doesn't matter to verify() — it follows prev links —
    // so swap their CONTENT under each other's links to simulate reordering history.
    $a = json_decode($lines[1], true); $b = json_decode($lines[2], true);
    [$a['prev'], $b['prev']] = [$b['prev'], $a['prev']];
    $lines[1] = json_encode($a); $lines[2] = json_encode($b);
    putLines($root, $lines);
    assertTrue(!verifyRepo($repo)['ok'], 'reorder detected');
});

test('JSON: purging the oldest entries is NOT a false alarm', function () {
    [$repo, $root] = jsonEnv();
    writeN($repo, 5);
    $lines = liveLines($root);
    putLines($root, array_slice($lines, 2)); // what purgeAuditBefore() leaves behind
    writeN($repo, 2, 'after');
    $r = verifyRepo($repo);
    assertTrue($r['ok'] && $r['chained'] === 5 && $r['gaps'] === 0, json_encode($r));
});

test('JSON: legacy unchained lines are reported but do not break the chain', function () {
    [$repo, $root] = jsonEnv();
    @mkdir($root . '/_fluxfiles', 0777, true);
    putLines($root, [json_encode(['ts' => 1, 'action' => 'upload', 'context' => ['user_id' => 'old']])]);
    writeN($repo, 3);
    $r = verifyRepo($repo);
    assertTrue($r['ok'] && $r['unchained'] === 1 && $r['chained'] === 3, json_encode($r));
});

test('JSON: rotation into archives keeps one continuous chain', function () {
    [$repo, $root] = jsonEnv();
    // Force a rotation by making the live log huge before the next write.
    writeN($repo, 3);
    $lines = liveLines($root);
    $pad = [];
    $prev = json_decode(end($lines), true)['hash'];
    $key = hash_hkdf('sha256', str_repeat('k', 40), 32, 'fluxfiles-audit-chain');
    for ($i = 0; $i < 6000; $i++) {
        $row = ['ts' => time(), 'action' => 'upload', 'context' => ['user_id' => 'p', 'file_key' => str_repeat('x', 900) . $i, 'ip' => null, 'user_agent' => null]];
        $row['prev'] = $prev;
        $row['hash'] = AuditChain::hash($key, $prev, ['ts' => $row['ts'], 'action' => 'upload', 'user_id' => 'p', 'file_key' => $row['context']['file_key'], 'ip' => null, 'user_agent' => null, 'detail' => null]);
        $prev = $row['hash'];
        $pad[] = json_encode($row);
    }
    putLines($root, array_merge($lines, $pad));
    writeN($repo, 1, 'rot');
    assertTrue(count($repo->readAuditArchive('local')) > 0, 'rotated into an archive');
    $r = verifyRepo($repo);
    assertTrue($r['ok'] && $r['gaps'] === 0 && $r['chained'] === 6004, json_encode(array_diff_key($r, ['tampered' => 1])));
});

test('no secret configured → entries unchained, verify reports configured=false', function () {
    AuditChain::configure('');
    $keepEnv = $_ENV['FLUXFILES_SECRET'] ?? null;
    unset($_ENV['FLUXFILES_SECRET']);
    putenv('FLUXFILES_SECRET');
    try {
        [$repo] = jsonEnv();
        writeN($repo, 2);
        $r = verifyRepo($repo);
        assertTrue($r['configured'] === false && $r['unchained'] === 2, json_encode($r));
    } finally {
        if ($keepEnv !== null) { $_ENV['FLUXFILES_SECRET'] = $keepEnv; }
        AuditChain::configure(str_repeat('k', 40));
    }
});

// ── SQLite db backend ───────────────────────────────────────────────────────
function dbEnv(): array {
    $file = sys_get_temp_dir() . '/ff-chain-' . uniqid() . '.sqlite';
    $conn = new Connection('sqlite:' . $file);
    (new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
    $root = sys_get_temp_dir() . '/fluxfiles-chaindb-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    return [new DbMetadataHandler($conn, $dm), $conn, $dm];
}

test('DB: a clean log verifies; the head table tracks the newest hash', function () {
    [$repo, $conn] = dbEnv();
    writeN($repo, 8);
    $r = verifyRepo($repo);
    assertTrue($r['ok'] && $r['chained'] === 8, json_encode($r));
    $head = $conn->pdo()->query("SELECT head_hash FROM audit_chain_head WHERE disk = 'local'")->fetchColumn();
    assertTrue($head === $r['head'], 'head row = verified head');
});

test('DB: an UPDATE of a row or a DELETE from the middle is detected', function () {
    [$repo, $conn] = dbEnv();
    writeN($repo, 6);
    $conn->pdo()->exec("UPDATE audit_log SET owner = 'nobody' WHERE id = 2");
    assertTrue(count(verifyRepo($repo)['tampered']) === 1, 'edit');
    $conn->pdo()->exec('DELETE FROM audit_log WHERE id = 4');
    assertTrue(verifyRepo($repo)['gaps'] === 1, 'gap');
});

test('JSON → DB migration carries the chain, which then keeps growing in the DB', function () {
    [$jsonRepo, $root] = jsonEnv();
    writeN($jsonRepo, 5);
    $file = sys_get_temp_dir() . '/ff-chain-mig-' . uniqid() . '.sqlite';
    $conn = new Connection('sqlite:' . $file);
    (new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $db = new DbMetadataHandler($conn, $dm);
    (new JsonToDbMigrator($dm, $jsonRepo, $db))->migrate('local');
    $r = verifyRepo($db);
    assertTrue($r['ok'] && $r['chained'] === 5, 'migrated chain verifies: ' . json_encode($r));
    writeN($db, 3, 'db');
    $r = verifyRepo($db);
    assertTrue($r['ok'] && $r['chained'] === 8 && $r['gaps'] === 0, 'continues without a gap: ' . json_encode($r));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

<?php

/**
 * AuditPolicy: the shared "what does the audit log record" rules that index.php
 * and (later) the Laravel/WordPress proxies call.
 *
 * Usage: php tests/unit/test-audit-policy.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\AuditLogStorage;
use FluxFiles\AuditPolicy;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$root = sys_get_temp_dir() . '/ff-audit-policy-' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root], 'other' => ['driver' => 'local', 'root' => $root . '/o']]);
$repo = new StorageMetadataHandler($dm);

function claims(string $prefix = '', array $disks = ['local'], array $perms = ['read', 'write', 'delete', 'audit']): Claims {
    return new Claims('u1', $perms, $disks, $prefix, 10, null, 0);
}
/** @return array<int,array<string,mixed>> */
function auditRows($repo, string $disk = 'local'): array { return $repo->readAudit($disk); }

echo "\n{$cyan}══ AuditPolicy ══{$reset}\n\n";

test('resolveAction: longest route wins, unknown otherwise', function () {
    assertEqual('share_revoke', AuditPolicy::resolveAction('/api/fm/share/revoke'));
    assertEqual('share_create', AuditPolicy::resolveAction('/api/fm/share'));
    assertEqual('chunk_upload', AuditPolicy::resolveAction('/api/fm/chunk/presign'));
    assertEqual('legal_hold_release', AuditPolicy::resolveAction('/api/fm/hold/release'));
    assertEqual('content_update', AuditPolicy::resolveAction('/api/fm/content'));
    assertEqual('unknown', AuditPolicy::resolveAction('/api/fm/nothing-like-it'));
});

test('every mapped needle resolves to its own action (no shadowing by an earlier shorter needle)', function () {
    foreach (AuditPolicy::actionMap() as $needle => $action) {
        assertEqual($action, AuditPolicy::resolveAction('/api/fm' . $needle), $needle);
    }
});

test('successEntry: presign is split by method, key is tenant-scoped, disk falls back to POST then local', function () {
    $c = claims('tenant');
    $e = AuditPolicy::successEntry('/api/fm/presign', ['method' => 'put', 'path' => 'a/b.txt', 'ttl' => 60], [], $c, ['url' => 'x']);
    assertEqual('presign_put', $e['action']);
    assertEqual('tenant/a/b.txt', $e['key']);
    assertEqual('ttl=60', $e['detail']);
    assertEqual('local', $e['disk']);
    assertEqual('presign_post', AuditPolicy::successEntry('/api/fm/presign', ['method' => 'POST'], [], $c, null)['action']);
    assertEqual('presign_get', AuditPolicy::successEntry('/api/fm/presign', [], [], $c, null)['action']);
    $u = AuditPolicy::successEntry('/api/fm/upload', [], ['disk' => 'other'], $c, ['key' => 'x.png']);
    assertEqual('other', $u['disk']);
    assertEqual('tenant/x.png', $u['key'], 'multipart upload: key comes from the result');
});

test('detail: terminal logs the command, git_deploy the path@branch, share_revoke the jti', function () {
    $c = claims();
    assertEqual('ls -la', AuditPolicy::detail('terminal', ['cmd' => 'ls -la'], $c));
    $c->gitDeployPath = '/srv/app';
    $c->gitDeployBranch = 'main';
    assertEqual('/srv/app@main', AuditPolicy::detail('git_deploy', [], $c));
    assertEqual('jti=abc', AuditPolicy::detail('share_revoke', ['jti' => 'abc'], $c));
    assertEqual('infected: EICAR', AuditPolicy::detail('virus_scan', [], $c, ['threat' => 'EICAR']));
    assertEqual('clean', AuditPolicy::detail('virus_scan', [], $c, ['clean' => true]));
    assertEqual(null, AuditPolicy::detail('rename', [], $c));
});

test('zipDetail caps a long selection', function () {
    $entries = [];
    for ($i = 0; $i < 30; $i++) { $entries[] = ['key' => "f{$i}.txt", 'name' => "f{$i}.txt", 'size' => 1]; }
    $d = AuditPolicy::zipDetail(['entries' => $entries, 'total' => 30, 'count' => 30]);
    assertTrue(str_starts_with($d, '30 files, 30 bytes: f0.txt'), $d);
    assertTrue(str_contains($d, '…'));
});

test('refusedWriteEntry: virus/pii/legal-hold map to blocked actions, anything else is null', function () {
    $c = claims('t');
    $v = AuditPolicy::refusedWriteEntry($c, 'virus_detected', ['name' => 'bad.exe'], [], ['path' => 'docs']);
    assertEqual(['action' => 'virus_blocked', 'disk' => 'local', 'key' => 't/docs/bad.exe', 'detail' => null], $v);
    $d = AuditPolicy::refusedWriteEntry($c, 'pii_detected', ['name' => 'a.txt', 'entities' => ['EMAIL', 'SSN']], ['disk' => 'other', 'path' => 'notes/a.txt'], []);
    assertEqual('dlp_blocked', $d['action']);
    assertEqual('other', $d['disk']);
    assertEqual('t/notes/a.txt', $d['key'], 'editor save names the file in the body');
    assertEqual('EMAIL,SSN', $d['detail']);
    $h = AuditPolicy::refusedWriteEntry($c, 'legal_hold_active', ['hold_id' => 'h1', 'path' => 'x/y'], ['src_disk' => 'other'], []);
    assertEqual(['action' => 'legal_hold_blocked', 'disk' => 'other', 'key' => 't/x/y', 'detail' => 'h1'], $h);
    assertEqual(null, AuditPolicy::refusedWriteEntry($c, 'permission_denied', [], [], []));
    assertEqual(null, AuditPolicy::refusedWriteEntry($c, null, [], [], []));
});

test('blockedWriteKey: extract entries stay relative to the tenant root', function () {
    $c = claims('t');
    assertEqual('t/inner/a.txt', AuditPolicy::blockedWriteKey($c, ['path' => 'ignored'], ['entry' => 'inner/a.txt']));
});

test('recordRefusedWrite writes the entry; a thrown scope error never escapes', function () use ($repo, $dm) {
    $c = claims('t');
    $log = new AuditLogStorage($repo, ['local']);
    AuditPolicy::recordRefusedWrite($log, $c, new ApiException('infected', 422, 'virus_detected', ['name' => 'e.exe']), [], ['path' => 'in']);
    $rows = auditRows($repo);
    assertEqual('virus_blocked', $rows[count($rows) - 1]['action']);
    assertEqual('t/in/e.exe', $rows[count($rows) - 1]['file_key']);
    // A traversal key makes scopePath() throw inside the legal-hold branch: swallowed.
    AuditPolicy::recordRefusedWrite($log, $c, new ApiException('held', 403, 'legal_hold_active', []), ['path' => '../../etc'], []);
    AuditPolicy::recordRefusedWrite($log, $c, new ApiException('nope', 400, 'missing_param'), [], []);
    assertTrue(true);
});

test('recordDenied: logs access_denied on a usable disk, names an unusable one in the detail', function () use ($repo) {
    $c = claims('t', ['local']);
    $log = new AuditLogStorage($repo, ['local']);
    $before = count(auditRows($repo));
    AuditPolicy::recordDenied($log, $c, new ApiException('no', 403, 'disk_denied'), 'POST', '/api/fm/delete', ['disk' => 'secret', 'path' => 'a.txt'], [], []);
    $rows = auditRows($repo);
    $last = $rows[count($rows) - 1];
    assertEqual($before + 1, count($rows));
    assertEqual('access_denied', $last['action']);
    assertEqual('disk_denied POST /api/fm/delete disk=secret', $last['detail']);
    assertEqual('t/a.txt', $last['file_key']);
    // Not a 403, and legal_hold_active: nothing written.
    AuditPolicy::recordDenied($log, $c, new ApiException('x', 400, 'bad'), 'POST', '/api/fm/delete', [], [], []);
    AuditPolicy::recordDenied($log, $c, new ApiException('x', 403, 'legal_hold_active'), 'POST', '/api/fm/delete', [], [], []);
    assertEqual($before + 1, count(auditRows($repo)));
});

test('decodeBody: anything that is not a JSON object becomes []', function () {
    assertEqual(['a' => 1], AuditPolicy::decodeBody('{"a":1}'));
    assertEqual([], AuditPolicy::decodeBody(''));
    assertEqual([], AuditPolicy::decodeBody('not json'));
    assertEqual([], AuditPolicy::decodeBody('"str"'));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

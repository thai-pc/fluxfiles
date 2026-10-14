<?php

/**
 * Audit-log coverage over HTTP. Boots the real router (private local disk, so
 * /stream is live) and checks the gaps closed in 2026-10:
 *
 *   - every mutating route resolves to a named action (no more `unknown`);
 *   - presign is split into presign_get / presign_put;
 *   - /zip — which exits before the generic write audit — logs zip_download;
 *   - an authenticated 403 is logged as access_denied, on a disk the token can
 *     use, with an out-of-scope probe kept outside the tenant's own view;
 *   - `audit_reads` adds content_read and stream_read (first request only).
 *
 * Usage: php tests/e2e/test-audit-coverage-http.php   (requires the curl extension)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$coreDir = realpath(__DIR__ . '/../..');

// ── 1. Static guard: no mutating route resolves to `unknown` ────────────────
echo "\n{$cyan}══ Audit coverage ══{$reset}\n\n";

$indexSrc = (string) file_get_contents($coreDir . '/api/index.php');
if (!preg_match('#\nfunction resolveAuditAction\(.*?\n\}\n#s', $indexSrc, $m)) {
    fwrite(STDERR, "resolveAuditAction() not found in api/index.php\n");
    exit(1);
}
eval('namespace AuditGuard; ' . $m[0]);

test('every POST/PUT/DELETE route maps to a named audit action', function () use ($indexSrc) {
    preg_match_all("#\\\$method === '(?:POST|PUT|DELETE)' && \\\$uri === '(/api/fm/[a-z0-9/_-]+)'#", $indexSrc, $mm);
    $routes = array_unique($mm[1]);
    assertTrue(count($routes) > 30, 'found the route table (' . count($routes) . ')');
    // Pre-auth: no Claims, nothing to audit against.
    $preAuth = ['/api/fm/sso/exchange'];
    $unknown = [];
    foreach ($routes as $r) {
        if (!in_array($r, $preAuth, true) && \AuditGuard\resolveAuditAction($r) === 'unknown') {
            $unknown[] = $r;
        }
    }
    assertEqual([], $unknown, 'routes logged as unknown');
});

test('longer routes win over their prefixes', function () {
    $cases = [
        '/api/fm/share' => 'share_create', '/api/fm/share/revoke' => 'share_revoke',
        '/api/fm/intake' => 'intake_create', '/api/fm/intake/revoke' => 'intake_revoke',
        '/api/fm/chunk/presign' => 'chunk_upload', '/api/fm/presign' => 'presign',
        '/api/fm/metadata/import' => 'metadata_import', '/api/fm/metadata' => 'metadata_update',
        '/api/fm/c2pa/sign' => 'c2pa_sign', '/api/fm/c2pa' => 'c2pa_verify',
        '/api/fm/versions/restore' => 'version_restore', '/api/fm/zip' => 'zip_download',
    ];
    foreach ($cases as $uri => $want) {
        assertEqual($want, \AuditGuard\resolveAuditAction($uri), $uri);
    }
});

// ── 2. Live server ──────────────────────────────────────────────────────────
$SECRET = str_repeat('a', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8121;
$BASE = "http://127.0.0.1:{$PORT}";
$uploadRoot = $coreDir . '/storage/uploads';
$auditFile = $uploadRoot . '/_fluxfiles/audit.jsonl';

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile,
    "FLUXFILES_SECRET={$SECRET}\n" .
    "FLUXFILES_LOCAL_PRIVATE=true\n" .
    "FLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n");

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

function req(string $method, string $url, string $tok, $body = null, array $extra = []): array {
    $ch = curl_init($url);
    $headers = array_merge(["Authorization: Bearer {$tok}"], $extra);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method];
    if (is_array($body) && isset($body['__file'])) {
        $opts[CURLOPT_POSTFIELDS] = $body['__file'];
    } elseif ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, json_decode((string) $raw, true), (string) $raw];
}
/** Audit entries written by $user since the run started. */
function auditFor(string $file, string $user): array {
    if (!is_file($file)) return [];
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['context']['user_id'] ?? '') === $user) {
            $out[] = ['action' => $row['action'] ?? '', 'key' => $row['context']['file_key'] ?? '', 'detail' => $row['context']['detail'] ?? null];
        }
    }
    return $out;
}
function lastAction(array $entries, string $action): ?array {
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        if ($entries[$i]['action'] === $action) return $entries[$i];
    }
    return null;
}

$run = bin2hex(random_bytes(4));
$prefix = "audit_e2e_{$run}";
$user = "audit-u-{$run}";
$readerUser = "audit-r-{$run}";

try {
    $tok = fluxfiles_token(['user' => $user, 'perms' => ['read', 'write', 'delete'], 'disks' => ['local'], 'prefix' => "{$prefix}/u1", 'claims' => ['allow_code_edit' => true]]);
    $reader = fluxfiles_token(['user' => $readerUser, 'perms' => ['read'], 'disks' => ['local'], 'prefix' => "{$prefix}/u1", 'claims' => ['audit_reads' => true]]);

    // Seed two files.
    foreach (['a.txt' => 'alpha', 'b.txt' => 'bravo'] as $n => $bytes) {
        $tmp = tempnam(sys_get_temp_dir(), 'ffa'); file_put_contents($tmp, $bytes);
        req('POST', "{$BASE}/api/fm/upload", $tok, ['__file' => ['disk' => 'local', 'path' => '', 'file' => new CURLFile($tmp, 'text/plain', $n)]]);
        @unlink($tmp);
    }

    // Not audit-specific, but this suite already runs a prefixed tenant: the
    // metadata editor sends the RELATIVE key list() returned, which used to 403.
    test('metadata read/save/delete accept the relative key a prefixed tenant sees', function () use ($BASE, $tok, $prefix) {
        [$st, $j] = req('PUT', "{$BASE}/api/fm/metadata", $tok, ['disk' => 'local', 'key' => 'a.txt', 'title' => 'Hello']);
        assertEqual(200, $st, 'save: ' . json_encode($j));
        [$st, $j] = req('GET', "{$BASE}/api/fm/metadata?disk=local&key=a.txt", $tok);
        assertEqual('Hello', $j['data']['title'] ?? null, 'read back');
        [$st, $j] = req('GET', "{$BASE}/api/fm/metadata?disk=local&key=" . rawurlencode("{$prefix}/u1/a.txt"), $tok);
        assertEqual('Hello', $j['data']['title'] ?? null, 'an already-scoped key still works');
        [$st, $j] = req('PUT', "{$BASE}/api/fm/metadata", $tok, ['disk' => 'local', 'key' => "{$prefix}/u2/x.txt", 'title' => 'pwn']);
        assertEqual(403, $st, 'sibling tenant refused');
        [$st] = req('DELETE', "{$BASE}/api/fm/metadata", $tok, ['disk' => 'local', 'key' => 'a.txt']);
        assertEqual(200, $st, 'delete');
    });

    test('presign is logged as presign_get with its ttl', function () use ($BASE, $tok, $auditFile, $user, $prefix) {
        [$st] = req('POST', "{$BASE}/api/fm/presign", $tok, ['disk' => 'local', 'path' => 'a.txt', 'method' => 'GET', 'ttl' => 120]);
        assertEqual(200, $st, 'presign on the private local disk');
        $e = lastAction(auditFor($auditFile, $user), 'presign_get');
        assertTrue($e !== null, 'presign_get entry written');
        assertEqual("{$prefix}/u1/a.txt", $e['key']);
        assertEqual('ttl=120', $e['detail']);
    });

    test('/zip logs zip_download (it exits before the generic audit)', function () use ($BASE, $tok, $auditFile, $user, $prefix) {
        [$st, , $raw] = req('POST', "{$BASE}/api/fm/zip", $tok, ['disk' => 'local', 'paths' => ['a.txt', 'b.txt']]);
        assertEqual(200, $st);
        assertEqual("PK", substr($raw, 0, 2), 'a zip was streamed');
        $e = lastAction(auditFor($auditFile, $user), 'zip_download');
        assertTrue($e !== null, 'zip_download entry written');
        assertEqual("{$prefix}/u1/a.txt", $e['key']);
        assertTrue(strpos((string) $e['detail'], '2 files') === 0, 'detail: ' . $e['detail']);
    });

    test('a refused permission is logged as access_denied', function () use ($BASE, $reader, $auditFile, $readerUser, $prefix) {
        [$st, $j] = req('DELETE', "{$BASE}/api/fm/delete", $reader, ['disk' => 'local', 'path' => 'a.txt']);
        assertEqual(403, $st);
        assertEqual('permission_denied', $j['error_code'] ?? null);
        $e = lastAction(auditFor($auditFile, $readerUser), 'access_denied');
        assertTrue($e !== null, 'access_denied written');
        assertEqual("{$prefix}/u1/a.txt", $e['key']);
        assertTrue(strpos((string) $e['detail'], 'permission_denied DELETE /api/fm/delete') === 0, 'detail: ' . $e['detail']);
    });

    test('a cross-tenant probe is logged outside the tenant\'s own scope', function () use ($BASE, $tok, $auditFile, $user, $prefix) {
        [$st, $j] = req('GET', "{$BASE}/api/fm/content?disk=local&path=" . rawurlencode("{$prefix}/u2/secret.txt"), $tok);
        assertEqual(403, $st);
        assertEqual('path_denied', $j['error_code'] ?? null);
        $e = lastAction(auditFor($auditFile, $user), 'access_denied');
        assertEqual("{$prefix}/u2/secret.txt", $e['key'] ?? null, 'raw (normalized) probe target kept');
    });

    test('a disk_denied probe is written to an ALLOWED disk, naming the refused one', function () use ($BASE, $tok, $auditFile, $user) {
        [$st, $j] = req('GET', "{$BASE}/api/fm/content?disk=s3&path=a.txt", $tok);
        assertEqual(403, $st);
        assertEqual('disk_denied', $j['error_code'] ?? null);
        // $auditFile IS the `local` disk's log — finding the entry there is the proof
        // it was not written onto the refused disk.
        $e = lastAction(auditFor($auditFile, $user), 'access_denied');
        assertTrue($e !== null, 'logged on the allowed (local) disk');
        assertTrue(strpos((string) $e['detail'], 'disk_denied') === 0 && strpos((string) $e['detail'], 'disk=s3') !== false, 'detail: ' . ($e['detail'] ?? ''));
    });

    test('a refused probe is visible to an unscoped admin but not the tenant\'s own /audit', function () use ($BASE, $user, $prefix) {
        $tenantAudit = fluxfiles_token(['user' => 'aud-t', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$prefix}/u1"]);
        [, $j] = req('GET', "{$BASE}/api/fm/audit?actor=" . rawurlencode($user) . '&action=access_denied&limit=500', $tenantAudit);
        $keys = array_column($j['data'] ?? [], 'file_key');
        assertTrue(!in_array("{$prefix}/u2/secret.txt", $keys, true), 'tenant does not see the cross-tenant key');
        $admin = fluxfiles_token(['user' => 'aud-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => '']);
        [, $j] = req('GET', "{$BASE}/api/fm/audit?actor=" . rawurlencode($user) . '&action=access_denied&limit=500', $admin);
        assertTrue(in_array("{$prefix}/u2/secret.txt", array_column($j['data'] ?? [], 'file_key'), true), 'admin sees it');
    });

    test('audit_reads off: GET /content is not logged', function () use ($BASE, $tok, $auditFile, $user) {
        [$st] = req('GET', "{$BASE}/api/fm/content?disk=local&path=a.txt", $tok);
        assertEqual(200, $st);
        assertEqual(null, lastAction(auditFor($auditFile, $user), 'content_read'));
    });

    test('audit_reads on: GET /content is logged as content_read', function () use ($BASE, $reader, $auditFile, $readerUser, $prefix) {
        [$st] = req('GET', "{$BASE}/api/fm/content?disk=local&path=b.txt", $reader);
        assertEqual(200, $st);
        $e = lastAction(auditFor($auditFile, $readerUser), 'content_read');
        assertEqual("{$prefix}/u1/b.txt", $e['key'] ?? null);
    });

    test('audit_reads on: /stream logs the first request only, not a mid-file seek', function () use ($BASE, $reader, $auditFile, $readerUser, $prefix) {
        [, $j] = req('GET', "{$BASE}/api/fm/list?disk=local&path=", $reader);
        $url = null;
        foreach ($j['data'] ?? [] as $it) {
            if (($it['name'] ?? '') === 'a.txt') $url = $it['url'] ?? null;
        }
        assertTrue(is_string($url) && strpos($url, '/api/fm/stream?token=') === 0, 'gated stream url minted');
        $count = static fn () => count(array_filter(auditFor($auditFile, $readerUser), fn ($e) => $e['action'] === 'stream_read'));
        $before = $count();
        req('GET', $BASE . $url, '', null, ['Range: bytes=2-']);
        assertEqual($before, $count(), 'a seek is not logged');
        req('GET', $BASE . $url, '');
        assertEqual($before + 1, $count(), 'the initial request is logged');
        $e = lastAction(auditFor($auditFile, $readerUser), 'stream_read');
        assertEqual("{$prefix}/u1/a.txt", $e['key']);
    });

    test('audit_reads off: /stream is not logged', function () use ($BASE, $tok, $auditFile, $user) {
        [, $j] = req('GET', "{$BASE}/api/fm/list?disk=local&path=", $tok);
        $url = null;
        foreach ($j['data'] ?? [] as $it) {
            if (($it['name'] ?? '') === 'a.txt') $url = $it['url'] ?? null;
        }
        req('GET', $BASE . $url, '');
        assertEqual(null, lastAction(auditFor($auditFile, $user), 'stream_read'));
    });
} finally {
    proc_terminate($proc); proc_close($proc);
    $dir = $uploadRoot . '/' . $prefix;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

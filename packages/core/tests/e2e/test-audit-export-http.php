<?php

/**
 * End-to-end HTTP test for the paid `audit-export` module: `GET /api/fm/audit/export`
 * (NDJSON/CSV, tenant-scoped, live+archive merge) and `POST /api/fm/audit/purge`
 * (admin-only). Boots the real router (own `php -S`, backs up/restores
 * packages/core/.env, needs curl) and drives both routes over the wire.
 *
 * Three phases, same reasoning as test-share-http.php:
 *
 *   1. FREE CORE (always runs, incl. CI) — `packages/audit-export` is not
 *      composer-installed, so both routes must 501 module_not_installed — but
 *      ONLY once the pre-module checks (the `audit` permission, and for purge the
 *      admin-only/before-cutoff checks) already pass, since those run before the
 *      module gate.
 *   2. MODULE INSTALLED, UNLICENSED — a wrapper router requires the module src
 *      directly (not a composer dep in dev) → 402. Skipped when packages/audit-export
 *      isn't checked out.
 *   3. LICENSED — a real key minted with scripts/license-gen.php + the local
 *      signing key. Skipped when the signing key isn't on this machine (the
 *      normal case in CI).
 *
 * Usage: php tests/e2e/test-audit-export-http.php   (requires the curl extension)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $yellow = "\033[33m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('h', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$coreDir = (string) realpath(__DIR__ . '/../..');
$repoRoot = (string) realpath(__DIR__ . '/../../../..');
$uploadRoot = $coreDir . '/storage/uploads';
$PREFIX = 'audit_export_e2e';

// ── HTTP helpers ────────────────────────────────────────────────────────────
/** @return array{0:int,1:array<string,string>,2:string} [status, headers(lower), body] */
function req(string $method, string $url, array $opt = []): array {
    $hdr = [];
    $ch = curl_init($url);
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdr) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) { $hdr[strtolower(trim($p[0]))] = trim($p[1]); }
            return strlen($line);
        },
    ];
    if (isset($opt['json'])) {
        $o[CURLOPT_POSTFIELDS] = json_encode($opt['json']);
        $o[CURLOPT_HTTPHEADER] = array_merge($o[CURLOPT_HTTPHEADER], ['Content-Type: application/json']);
    }
    curl_setopt_array($ch, $o);
    $body = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $hdr, $body];
}
/** @return array{0:int,1:array<string,string>,2:array<string,mixed>|null} */
function reqJson(string $method, string $url, array $opt = []): array {
    [$st, $h, $body] = req($method, $url, $opt);
    return [$st, $h, json_decode($body, true)];
}
/** The standard error envelope, asserted whole. */
function assertEnvelope(array $j, string $code): void {
    assertTrue(array_key_exists('data', $j) && array_key_exists('error', $j), 'envelope shape');
    assertEqual(null, $j['data'], 'data null on error');
    assertTrue(is_string($j['error']) && $j['error'] !== '', 'human message present');
    assertEqual($code, $j['error_code'] ?? null, 'error_code');
}

// ── server control ──────────────────────────────────────────────────────────
$procs = [];
/**
 * Boot a server. $router = null → the stock router.php (free core, module absent);
 * otherwise a wrapper router that pre-loads the module package.
 */
function boot(int $port, ?string $router = null): array {
    global $coreDir, $procs;
    $cmd = ['php', '-S', "127.0.0.1:{$port}", '-t', $coreDir, $router ?? 'router.php'];
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
    if (!is_resource($p)) { fwrite(STDERR, "could not start server on {$port}\n"); exit(1); }
    $procs[] = $p;
    for ($i = 0; $i < 60; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    return [$p, "http://127.0.0.1:{$port}"];
}
function stop($p): void {
    global $procs;
    $procs = array_values(array_filter($procs, static fn ($x) => $x !== $p));
    @proc_terminate($p);
    @proc_close($p);
}

// ── fixtures on the local disk ──────────────────────────────────────────────
// The live audit.jsonl is a sibling of, not nested under, `_fluxfiles/audit/`
// (that dir only holds archives) — so it survives this file's own end-of-run
// cleanup below, and this storage path is also the one used for manual dev-
// server testing. Start from a guaranteed-empty log so scoping assertions
// can't pass on a stale entry left by a previous run.
@unlink("{$uploadRoot}/_fluxfiles/audit.jsonl");
@mkdir("{$uploadRoot}/{$PREFIX}", 0777, true);
@mkdir("{$uploadRoot}/_fluxfiles/audit/archive", 0777, true);

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
$baseEnv = "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n";
file_put_contents($envFile, $baseEnv);

// The module src isn't a composer dep in dev, so phases 2+3 boot through a wrapper
// router that requires it before delegating to the real router.php (the built-in
// server ignores auto_prepend_file for its router script, hence the wrapper).
$moduleSrc = "{$repoRoot}/packages/audit-export/src/AuditExportModule.php";
$modRouter = sys_get_temp_dir() . '/ff-audit-export-router-' . getmypid() . '.php';
file_put_contents($modRouter, "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nrequire_once '{$moduleSrc}';\nreturn require '{$coreDir}/router.php';\n");

/** A real license for `audit-export`, or '' when the offline signing key isn't on this box. */
function mintLicense(string $repoRoot): string {
    $gen = "{$repoRoot}/scripts/license-gen.php";
    $keyFile = "{$repoRoot}/docs/license-signing-key.SECRET.txt";
    if (!is_file($gen) || !is_file($keyFile) || !function_exists('sodium_crypto_sign_detached')) { return ''; }
    $secret = '';
    foreach (preg_split('/\R/', (string) file_get_contents($keyFile)) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('#^[A-Za-z0-9+/=]{80,}$#', $line) && strlen((string) base64_decode($line, true)) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $secret = $line;
        }
    }
    if ($secret === '') { return ''; }
    $env = array_merge(getenv(), ['FLUXFILES_LICENSE_PRIVATE_KEY' => $secret]);
    $p = proc_open(['php', $gen, '--edition=pro', '--modules=audit-export', '--enforcement=perpetual', '--expires=+30d', '--kid=k1'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return ''; }
    $tok = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return (new \FluxFiles\LicenseManager($tok))->licensed('audit-export') ? $tok : '';
}

echo "\n{$cyan}══ Audit export / purge over HTTP (e2e) ══{$reset}\n\n";

try {
    // ════════════════════════════════════════════════════════════════════════
    // Phase 1 — FREE CORE: the module isn't installed.
    // ════════════════════════════════════════════════════════════════════════
    echo "{$cyan}── phase 1: free core (module absent) ──{$reset}\n\n";
    [$srv1, $B1] = boot(8106);

    $auditToken = fluxfiles_token(['user' => 'op', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => $PREFIX, 'ttl' => 600]);
    $auditHdr = ["Authorization: Bearer {$auditToken}"];
    $adminToken = fluxfiles_token(['user' => 'admin', 'perms' => ['read', 'write', 'audit'], 'disks' => ['local'], 'prefix' => '', 'ttl' => 600]);
    $adminHdr = ["Authorization: Bearer {$adminToken}"];
    $noAuditToken = fluxfiles_token(['user' => 'op2', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => $PREFIX, 'ttl' => 600]);

    test('GET /audit/export without the audit perm → 403, before the module gate', function () use ($B1, $noAuditToken) {
        [$st, , $j] = reqJson('GET', "{$B1}/api/fm/audit/export?format=ndjson", ['headers' => ["Authorization: Bearer {$noAuditToken}"]]);
        assertEqual(403, $st);
        assertEnvelope($j, 'forbidden');
    });

    test('GET /audit/export with the audit perm, module absent → 501 module_not_installed', function () use ($B1, $auditHdr) {
        [$st, $h, $j] = reqJson('GET', "{$B1}/api/fm/audit/export?format=ndjson", ['headers' => $auditHdr]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
        assertEqual('audit-export', $j['error_params']['module'] ?? null, 'names the module');
        assertTrue(stripos($h['content-type'] ?? '', 'application/json') === 0, 'json content-type, not a file download');
    });

    test('POST /audit/purge with a scoped token → 403, before the module gate (admin-only check runs first)', function () use ($B1, $auditHdr) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/audit/purge", ['json' => ['before' => time()], 'headers' => $auditHdr]);
        assertEqual(403, $st, 'scoped token is rejected even though the module is absent');
        assertEnvelope($j, 'forbidden');
    });

    test('POST /audit/purge admin token, no before + no retention claim → 400 audit_purge_no_cutoff, before the module gate', function () use ($B1, $adminHdr) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/audit/purge", ['json' => [], 'headers' => $adminHdr]);
        assertEqual(400, $st);
        assertEnvelope($j, 'audit_purge_no_cutoff');
    });

    test('POST /audit/purge admin token WITH an explicit before, module absent → 501 module_not_installed', function () use ($B1, $adminHdr) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/audit/purge", ['json' => ['before' => time()], 'headers' => $adminHdr]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
        assertEqual('audit-export', $j['error_params']['module'] ?? null);
    });

    // Regression: index.php's post-dispatch audit-logging block used to log the raw,
    // tenant-RELATIVE request path (or the equally-unscoped $data['key'] fallback) as
    // file_key. AuditLogStorage::list()/exportAll() scope entries via
    // Claims::isPathInScope(), which matches on the FULL prefixed path — so a scoped
    // tenant's own actions could never match their own prefix and were permanently
    // invisible to themselves on the FREE `/api/fm/audit` endpoint (no paid module
    // needed to hit this). Every prior test in this file only ever seeds file_key
    // directly at the storage layer (already scoped) or uses an empty/admin prefix,
    // so none of them exercised the real request-path → audit-log write path with a
    // non-empty prefix. This test performs a genuine write over HTTP with a scoped
    // token and confirms the tenant sees — and sees correctly-scoped — their own entry.
    test('a scoped tenant sees their own real write in GET /api/fm/audit, correctly prefixed', function () use ($B1, $PREFIX) {
        $scopedWriter = fluxfiles_token(['user' => 'scoping-regress', 'perms' => ['read', 'write', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/scoping_regress", 'ttl' => 600]);
        $hdr = ["Authorization: Bearer {$scopedWriter}"];

        [$stMkdir, , $jMkdir] = reqJson('POST', "{$B1}/api/fm/mkdir", ['json' => ['path' => 'regress-dir', 'disk' => 'local'], 'headers' => $hdr]);
        assertEqual(200, $stMkdir, 'mkdir succeeds');
        assertTrue(($jMkdir['data']['created'] ?? false) === true, 'mkdir reports created');

        [$stAudit, , $jAudit] = reqJson('GET', "{$B1}/api/fm/audit?limit=10", ['headers' => $hdr]);
        assertEqual(200, $stAudit, 'audit list succeeds');
        $entries = $jAudit['data'] ?? [];
        $mkdirEntries = array_values(array_filter($entries, static fn ($e) => ($e['action'] ?? '') === 'mkdir'));
        assertTrue(count($mkdirEntries) >= 1, 'the tenant sees their own mkdir — before the fix this was always empty (prefix could never match)');
        assertEqual("{$PREFIX}/scoping_regress/regress-dir", $mkdirEntries[0]['file_key'] ?? null, 'file_key is the FULL scoped path, not the tenant-relative one the client sent');
    });

    stop($srv1);

    // ════════════════════════════════════════════════════════════════════════
    // Phase 2 — module installed, NOT licensed.
    // ════════════════════════════════════════════════════════════════════════
    if (!is_file($moduleSrc)) {
        echo "\n  {$yellow}skip{$reset} phases 2+3 (packages/audit-export not checked out)\n";
    } else {
        echo "\n{$cyan}── phase 2: module installed, unlicensed ──{$reset}\n\n";
        [$srv2, $B2] = boot(8107, $modRouter);

        test('GET /audit/export → 402 license_required (gate order: installed → licensed)', function () use ($B2, $auditHdr) {
            [$st, , $j] = reqJson('GET', "{$B2}/api/fm/audit/export?format=ndjson", ['headers' => $auditHdr]);
            assertEqual(402, $st);
            assertEnvelope($j, 'license_required');
            assertEqual('audit-export', $j['error_params']['module'] ?? null);
        });

        test('POST /audit/purge → 402 license_required', function () use ($B2, $adminHdr) {
            [$st, , $j] = reqJson('POST', "{$B2}/api/fm/audit/purge", ['json' => ['before' => time()], 'headers' => $adminHdr]);
            assertEqual(402, $st);
            assertEnvelope($j, 'license_required');
        });

        stop($srv2);

        // ════════════════════════════════════════════════════════════════════
        // Phase 3 — licensed: the real export/purge journey.
        // ════════════════════════════════════════════════════════════════════
        $license = mintLicense($repoRoot);
        if ($license === '') {
            echo "\n  {$yellow}skip{$reset} phase 3 (no offline signing key on this machine — expected in CI)\n";
        } else {
            echo "\n{$cyan}── phase 3: licensed (real export/purge journey) ──{$reset}\n\n";
            file_put_contents($envFile, $baseEnv . "FLUXFILES_LICENSE_KEY={$license}\n");
            [$srv3, $B] = boot(8108, $modRouter);

            // Seed audit history for two tenants sharing the local disk, so scoping is
            // actually exercised. Tenant records go through the real /list route (which
            // audits reads) — simplest way to get real, realistic audit.jsonl entries
            // through the wire rather than writing storage files directly.
            $tenantA = fluxfiles_token(['user' => 'tenant-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/a", 'ttl' => 600]);
            $tenantB = fluxfiles_token(['user' => 'tenant-b', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/b", 'ttl' => 600]);
            @mkdir("{$uploadRoot}/{$PREFIX}/a", 0777, true);
            @mkdir("{$uploadRoot}/{$PREFIX}/b", 0777, true);
            file_put_contents("{$uploadRoot}/{$PREFIX}/a/one.txt", 'a1');
            file_put_contents("{$uploadRoot}/{$PREFIX}/b/two.txt", 'b1');

            [$stA] = reqJson('GET', "{$B}/api/fm/list?disk=local&path=", ['headers' => ["Authorization: Bearer {$tenantA}"]]);
            assertEqual(200, $stA, 'seed read for tenant A');
            [$stB] = reqJson('GET', "{$B}/api/fm/list?disk=local&path=", ['headers' => ["Authorization: Bearer {$tenantB}"]]);
            assertEqual(200, $stB, 'seed read for tenant B');

            // A pre-existing archived entry for tenant A, so export is proven to merge
            // live + archive, not just read the live file.
            $archiveLine = json_encode(['ts' => time() - 86400, 'action' => 'upload', 'context' => [
                'user_id' => 'tenant-a', 'file_key' => "{$PREFIX}/a/archived-old.txt",
            ]]);
            file_put_contents("{$uploadRoot}/_fluxfiles/audit/archive/audit-seed-e2e.jsonl", $archiveLine . "\n");

            test('NDJSON export: tenant-scoped rows only, live + archived merged', function () use ($B, $tenantA, $PREFIX) {
                $opA = fluxfiles_token(['user' => 'tenant-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/a", 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true]]);
                [$st, $h, $body] = req('GET', "{$B}/api/fm/audit/export?format=ndjson", ['headers' => ["Authorization: Bearer {$opA}"]]);
                assertEqual(200, $st);
                assertTrue(stripos($h['content-type'] ?? '', 'application/x-ndjson') === 0, 'ndjson content-type: ' . ($h['content-type'] ?? ''));
                assertTrue(stripos($h['content-disposition'] ?? '', 'attachment') !== false, 'download disposition');
                $lines = array_values(array_filter(explode("\n", trim($body))));
                assertTrue(count($lines) >= 1, 'at least the live entry');
                $keys = array_map(static fn ($l) => json_decode($l, true)['file_key'] ?? null, $lines);
                foreach ($keys as $k) {
                    assertTrue(strpos((string) $k, "{$PREFIX}/a/") === 0, "only tenant-a rows: {$k}");
                }
                assertTrue(strpos($body, "{$PREFIX}/b/") === false, 'tenant-b rows never appear');
                assertTrue(in_array("{$PREFIX}/a/archived-old.txt", $keys, true), 'archived entry merged in');
            });

            test('CSV export: header row + tenant-scoped rows', function () use ($B, $PREFIX) {
                $opA = fluxfiles_token(['user' => 'tenant-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/a", 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true]]);
                [$st, $h, $body] = req('GET', "{$B}/api/fm/audit/export?format=csv", ['headers' => ["Authorization: Bearer {$opA}"]]);
                assertEqual(200, $st);
                assertTrue(stripos($h['content-type'] ?? '', 'text/csv') === 0, 'csv content-type: ' . ($h['content-type'] ?? ''));
                $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", trim($body)))));
                assertTrue(count($lines) >= 2, 'header + at least one row');
                assertEqual('created_at,action,user_id,disk,file_key,ip,user_agent,detail', $lines[0], 'CSV header columns');
                assertTrue(strpos(implode("\n", $lines), "{$PREFIX}/b/") === false, 'tenant-b rows never appear in CSV either');
            });

            test('export without allow_audit_export claim → 403 via ModuleRegistry (claim layer)', function () use ($B, $PREFIX) {
                $noClaim = fluxfiles_token(['user' => 'tenant-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/a", 'ttl' => 600]);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/audit/export?format=ndjson", ['headers' => ["Authorization: Bearer {$noClaim}"]]);
                assertEqual(403, $st);
                assertEnvelope($j, 'allow_audit_export_forbidden');
            });

            test('action= filter narrows the export', function () use ($B, $PREFIX) {
                $opA = fluxfiles_token(['user' => 'tenant-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/a", 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true]]);
                [$st, , $body] = req('GET', "{$B}/api/fm/audit/export?format=ndjson&action=upload", ['headers' => ["Authorization: Bearer {$opA}"]]);
                assertEqual(200, $st);
                $lines = array_values(array_filter(explode("\n", trim($body))));
                foreach ($lines as $l) {
                    assertEqual('upload', json_decode($l, true)['action'] ?? null, 'only upload rows returned');
                }
            });

            test('purge with a scoped token (allow_audit_export set) still 403s — admin-only regardless of the claim', function () use ($B, $PREFIX) {
                $scopedAdmin = fluxfiles_token(['user' => 'tenant-a', 'perms' => ['read', 'audit'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/a", 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true]]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/audit/purge", ['json' => ['before' => time()], 'headers' => ["Authorization: Bearer {$scopedAdmin}"]]);
                assertEqual(403, $st);
                assertEnvelope($j, 'forbidden');
            });

            test('purge admin token without allow_audit_export claim → 403 (claim layer)', function () use ($B, $adminHdr) {
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/audit/purge", ['json' => ['before' => time()], 'headers' => $adminHdr]);
                assertEqual(403, $st);
                assertEnvelope($j, 'allow_audit_export_forbidden');
            });

            test('purge: unscoped admin token with allow_audit_export + explicit before → 200 with counts, entries actually gone', function () use ($B, $PREFIX) {
                $admin = fluxfiles_token(['user' => 'root', 'perms' => ['read', 'write', 'audit'], 'disks' => ['local'], 'prefix' => '', 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true]]);
                $adminH = ["Authorization: Bearer {$admin}"];
                $cutoff = time() - 3600; // the archived seed entry (created "yesterday") is older than this

                [$st, , $j] = reqJson('POST', "{$B}/api/fm/audit/purge", ['json' => ['disk' => 'local', 'before' => $cutoff], 'headers' => $adminH]);
                assertEqual(200, $st);
                assertTrue(array_key_exists('archives_deleted', $j['data'] ?? []), 'reports archive count');
                assertTrue(array_key_exists('live_lines_removed', $j['data'] ?? []), 'reports live-line count');

                [, , $j2] = reqJson('GET', "{$B}/api/fm/audit/export?format=ndjson", ['headers' => $adminH]);
                assertTrue(strpos((string) json_encode($j2), 'archived-old.txt') === false, 'the purged archived entry is gone (unscoped export sees everything)');
            });

            test('purge respects audit_retention_days when no explicit before is sent', function () use ($B, $PREFIX) {
                // Seed one more archived-old entry so there is something to purge again.
                global $uploadRoot;
                $line = json_encode(['ts' => time() - (400 * 86400), 'action' => 'upload', 'context' => [
                    'user_id' => 'tenant-a', 'file_key' => "{$PREFIX}/a/very-old.txt",
                ]]);
                file_put_contents("{$uploadRoot}/_fluxfiles/audit/archive/audit-retention-e2e.jsonl", $line . "\n");

                $admin = fluxfiles_token(['user' => 'root', 'perms' => ['read', 'write', 'audit'], 'disks' => ['local'], 'prefix' => '', 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true, 'audit_retention_days' => 365]]);
                $adminH = ["Authorization: Bearer {$admin}"];

                [$st, , $j] = reqJson('POST', "{$B}/api/fm/audit/purge", ['json' => ['disk' => 'local'], 'headers' => $adminH]);
                assertEqual(200, $st, 'retention-days-derived cutoff accepted without an explicit before');
                assertTrue(($j['data']['archives_deleted'] ?? 0) >= 1, 'the >365-day-old archive was purged');

                [, , $j2] = reqJson('GET', "{$B}/api/fm/audit/export?format=ndjson", ['headers' => $adminH]);
                assertTrue(strpos((string) json_encode($j2), 'very-old.txt') === false, 'the retention-purged entry is gone');
            });

            test('purge: unscoped admin token scoped to a DIFFERENT disk cannot purge this one (pathPrefix and allowedDisks are independent claims)', function () use ($B) {
                // An empty prefix makes the admin-only check pass, but 'disks' => ['s3']
                // never granted access to 'local' — purge must still be rejected.
                $s3OnlyAdmin = fluxfiles_token(['user' => 'root', 'perms' => ['read', 'write', 'audit'], 'disks' => ['s3'], 'prefix' => '', 'ttl' => 600,
                    'claims' => ['allow_audit_export' => true]]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/audit/purge", ['json' => ['disk' => 'local', 'before' => time()], 'headers' => ["Authorization: Bearer {$s3OnlyAdmin}"]]);
                assertEqual(403, $st);
                assertEnvelope($j, 'disk_not_allowed');
            });

            stop($srv3);
        }
    }
} finally {
    // .env FIRST: it's the developer's file, so it must be put back even if a later
    // cleanup step throws (proc_terminate() on a closed handle is a TypeError, not a
    // warning — @ won't save it).
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
    foreach ($procs as $p) { if (is_resource($p)) { @proc_terminate($p); } }
    @unlink($modRouter);
    foreach (["{$uploadRoot}/{$PREFIX}", "{$uploadRoot}/_fluxfiles/audit"] as $dir) {
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        }
    }
    @rmdir("{$uploadRoot}/{$PREFIX}");
    @unlink("{$uploadRoot}/_fluxfiles/audit.jsonl");
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

<?php

/**
 * End-to-end HTTP test for the free/core Compliance Readiness Scorecard
 * (docs/COMPLIANCE-SCORECARD-DESIGN.md): `GET /api/fm/compliance/scorecard`.
 * Boots the real router (own `php -S`, backs up/restores packages/core/.env,
 * needs curl) and drives the route over the wire — no module/license gate on
 * the route itself, just the `audit` perm, so this is a single-phase test
 * (unlike the paid-module e2e tests in this directory).
 *
 * Usage: php tests/e2e/test-compliance-scorecard-http.php   (requires the curl extension)
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
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('c', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8109; // 8108 is already used internally by test-audit-export-http.php's third boot() phase
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = (string) realpath(__DIR__ . '/../..');

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n");

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

function http(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, json_decode((string) $body, true)];
}

/** Recursively assert a forbidden key name never appears anywhere in a decoded response. */
function assertNoForbiddenKeys($val, array $forbidden, string $path = 'root'): void {
    if (!is_array($val)) { return; }
    foreach ($val as $k => $v) {
        if (is_string($k)) {
            foreach ($forbidden as $bad) {
                if (strcasecmp($k, $bad) === 0) { throw new \RuntimeException("forbidden key '{$k}' found at {$path}"); }
            }
        }
        assertNoForbiddenKeys($v, $forbidden, $path . '.' . $k);
    }
}

echo "\n{$cyan}══ Compliance Readiness Scorecard over HTTP ══{$reset}\n\n";

try {
    // Token WITHOUT the `audit` perm — must get 403, same shape as /api/fm/audit.
    $tokNoAudit = fluxfiles_token([
        'user' => 'no-audit-user', 'perms' => ['read', 'write'], 'disks' => ['local'],
    ]);

    // Token WITH `audit` perm, allow_virus_scan on, allow_c2pa off.
    $tokAudit = fluxfiles_token([
        'user' => 'audit-user', 'perms' => ['read', 'audit'], 'disks' => ['local'],
        'claims' => ['allow_virus_scan' => true, 'allow_c2pa' => false],
    ]);

    test('GET without `audit` perm → 403 forbidden, same shape as /api/fm/audit', function () use ($BASE, $tokNoAudit) {
        [$st, $j] = http("{$BASE}/api/fm/compliance/scorecard", ["Authorization: Bearer {$tokNoAudit}"]);
        assertEqual(403, $st);
        assertEqual('forbidden', $j['error_code'] ?? null, 'error_code');
        assertTrue(array_key_exists('data', $j) && $j['data'] === null, 'no data payload on error');
    });

    test('GET with `audit` perm → 200, normal {data, error:null} envelope', function () use ($BASE, $tokAudit) {
        [$st, $j] = http("{$BASE}/api/fm/compliance/scorecard", ["Authorization: Bearer {$tokAudit}"]);
        assertEqual(200, $st);
        assertTrue(array_key_exists('data', $j), 'has data key');
        assertTrue(array_key_exists('error', $j) && $j['error'] === null, 'error is null, envelope not bypassed');
    });

    test('response has exactly 6 items in stable order', function () use ($BASE, $tokAudit) {
        [, $j] = http("{$BASE}/api/fm/compliance/scorecard", ["Authorization: Bearer {$tokAudit}"]);
        $items = $j['data']['items'];
        assertEqual(6, count($items));
        assertEqual(
            ['virus_scan', 'c2pa', 'audit_export', 'sso', 'dlp_scan', 'legal_hold'],
            array_column($items, 'id')
        );
    });

    test('per-token claim state is reflected: virus_scan enabled, c2pa disabled, regardless of module install state', function () use ($BASE, $tokAudit) {
        [, $j] = http("{$BASE}/api/fm/compliance/scorecard", ["Authorization: Bearer {$tokAudit}"]);
        $byId = [];
        foreach ($j['data']['items'] as $item) { $byId[$item['id']] = $item; }
        assertEqual(true, $byId['virus_scan']['enabled'], 'virus_scan enabled tracks the claim');
        assertEqual(false, $byId['c2pa']['enabled'], 'c2pa disabled tracks the claim');
        // Free core / no license in this test env → every row unavailable.
        assertEqual(false, $byId['virus_scan']['available'], 'not licensed in this test env');
        assertEqual('locked', $byId['virus_scan']['status'], 'claim on but unavailable ⇒ locked, not on');
    });

    test('disclaimer is always present and summary arithmetic is consistent', function () use ($BASE, $tokAudit) {
        [, $j] = http("{$BASE}/api/fm/compliance/scorecard", ["Authorization: Bearer {$tokAudit}"]);
        $d = $j['data'];
        assertTrue(is_string($d['disclaimer']) && $d['disclaimer'] !== '', 'disclaimer present');
        assertEqual(6, $d['summary']['total_count']);
        $enabled = 0;
        foreach ($d['items'] as $i) { if ($i['enabled']) { $enabled++; } }
        assertEqual($enabled, $d['summary']['enabled_count'], 'enabled_count matches items');
    });

    test('response never contains score/percent/compliant anywhere (liability guardrail, §3)', function () use ($BASE, $tokAudit) {
        [, $j] = http("{$BASE}/api/fm/compliance/scorecard", ["Authorization: Bearer {$tokAudit}"]);
        assertNoForbiddenKeys($j['data'], ['score', 'percent', 'compliant']);
    });

} finally {
    proc_terminate($proc); proc_close($proc);
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

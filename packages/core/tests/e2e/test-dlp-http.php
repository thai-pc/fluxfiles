<?php

/**
 * End-to-end HTTP test for the DLP/PII-scan wiring in index.php (mirrors
 * test-virus-http.php). The FileManager seam is covered by
 * tests/integration/test-dlp.php with injected scanners; what can only be checked
 * through the real router is the WIRING decision itself:
 *
 *   - `allow_dlp_scan` off → uploads behave exactly as before (no cost, no gate);
 *   - `allow_dlp_scan` on with the proprietary module absent (this MIT checkout)
 *     → the write FAILS CLOSED with 501 module_not_installed and stores nothing,
 *     instead of silently keeping an unscanned file;
 *   - the gate is LAZY — reads with the same token are unaffected (resolving the
 *     module at boot would break plain listing for the same tenant);
 *   - the S3-multipart chunk routes, whose bytes never reach this server and so can
 *     never be scanned, are refused rather than left as a way around the scanner
 *     — and this check is independent of (and does not clash with) the virus one.
 *
 * Usage: php tests/e2e/test-dlp-http.php   (requires the curl extension)
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

$SECRET = str_repeat('d', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8151;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');
$uploadRoot = $coreDir . '/storage/uploads';

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n");

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

function http(string $url, array $headers = [], ?array $post = null): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers];
    if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($post); $opts[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']); }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, json_decode((string) $body, true)];
}
function upload(string $base, string $tok, string $disk, string $path, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'ffd'); file_put_contents($tmp, $bytes);
    $ch = curl_init("{$base}/api/fm/upload");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}"],
        CURLOPT_POSTFIELDS => ['disk' => $disk, 'path' => dirname($path) === '.' ? '' : dirname($path),
            'file' => new CURLFile($tmp, 'text/plain', basename($path))]]);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); @unlink($tmp);
    return [$st, json_decode((string) $body, true)];
}

echo "\n{$cyan}══ DLP/PII-scan wiring over HTTP (e2e) ══{$reset}\n\n";

$prefix = 'dlp_e2e';
try {
    $tokOff = fluxfiles_token('dlp-off', ['read', 'write'], ['local'], $prefix, 50, null, 3600);
    // Same token shape, plus the claim — this is the only difference under test.
    $tokOn = FluxFiles\JwtCompat::encode(array_merge(
        (array) FluxFiles\JwtCompat::decode($tokOff, $SECRET),
        ['sub' => 'dlp-on', 'allow_dlp_scan' => true]
    ), $SECRET);

    test('claim OFF → upload succeeds and is stored (free core pays nothing)', function () use ($BASE, $tokOff, $uploadRoot, $prefix) {
        [$st, $j] = upload($BASE, $tokOff, 'local', 'no-scan.txt', 'hello world');
        assertEqual(200, $st, 'http');
        assertEqual('no-scan.txt', $j['data']['name'] ?? null);
        assertTrue(is_file("{$uploadRoot}/{$prefix}/no-scan.txt"), 'file stored');
    });

    test('claim ON + module absent → 501 module_not_installed (fails closed)', function () use ($BASE, $tokOn) {
        [$st, $j] = upload($BASE, $tokOn, 'local', 'want-scan.txt', 'hello world');
        assertEqual(501, $st, 'http');
        assertEqual('module_not_installed', $j['error_code'] ?? null);
        assertEqual('dlp', $j['error_params']['module'] ?? null);
    });

    test('the unscanned file is NOT on disk', function () use ($uploadRoot, $prefix) {
        assertTrue(!is_file("{$uploadRoot}/{$prefix}/want-scan.txt"), 'nothing may be stored when the scan could not run');
    });

    test('the gate is lazy — reads with the same token still work', function () use ($BASE, $tokOn) {
        [$st, $j] = http("{$BASE}/api/fm/list?disk=local&path=", ["Authorization: Bearer {$tokOn}"]);
        assertEqual(200, $st, 'listing must not be broken by the claim');
        assertTrue(is_array($j['data'] ?? null), 'listing returns data');
    });

    test('chunk upload is refused while scanning is on (no unscannable side door)', function () use ($BASE, $tokOn) {
        foreach (['init', 'presign', 'complete', 'abort'] as $step) {
            [$st, $j] = http("{$BASE}/api/fm/chunk/{$step}", ["Authorization: Bearer {$tokOn}"],
                ['disk' => 'local', 'key' => 'big.bin', 'upload_id' => 'x', 'part_number' => 1, 'parts' => []]);
            assertEqual(409, $st, "chunk/{$step} http");
            assertEqual('dlp_unscannable', $j['error_code'] ?? null, "chunk/{$step} code");
        }
    });

    test('chunk upload still works normally when scanning is off', function () use ($BASE, $tokOff) {
        // local disk has no multipart backend, so this must NOT be the DLP refusal —
        // any other outcome proves the guard is scoped to the claim.
        [$st, $j] = http("{$BASE}/api/fm/chunk/init", ["Authorization: Bearer {$tokOff}"],
            ['disk' => 'local', 'key' => 'big.bin']);
        assertTrue(($j['error_code'] ?? '') !== 'dlp_unscannable', 'must not be refused for a token without the claim');
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

<?php

/**
 * End-to-end HTTP test for gated local media streaming (M4). Boots the real
 * router with FLUXFILES_LOCAL_PRIVATE=true, then exercises /api/fm/stream over
 * the wire: Range (206) seeking, full (200) serving, and the auth/traversal
 * rejections — the security glue not covered by the unit/integration tests.
 *
 * Usage: php tests/e2e/test-stream-http.php
 * Requires the curl extension (already a core dependency).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\StreamToken;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('e', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET; // for fluxfiles_token() minting in this process
$PORT = 8099;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');

// Fresh upload root with a known media file (40 bytes of A..Z…).
$uploadRoot = $coreDir . '/storage/uploads';
@mkdir($uploadRoot . '/e2e_stream', 0777, true);
$content = '';
for ($i = 0; $i < 40; $i++) { $content .= chr(65 + ($i % 26)); }
file_put_contents($uploadRoot . '/e2e_stream/clip.mp4', $content);

// The server loads packages/core/.env first (createImmutable populates $_ENV),
// which is the reliable way to inject config into php -S. Write our test .env,
// backing up any existing one so a developer's local .env is restored after.
$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile,
    "FLUXFILES_SECRET={$SECRET}\n" .
    "FLUXFILES_LOCAL_PRIVATE=true\n" .
    "FLUXFILES_RATE_LIMIT_READ=100000\n" .
    "FLUXFILES_RATE_LIMIT_WRITE=100000\n"
);

$proc = proc_open(
    ['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $pipes,
    $coreDir
);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }

// Wait for readiness.
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $PORT, $errno, $errstr, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100_000);
}

/** curl GET; returns [status, headers(assoc lower), body]. */
function httpGet(string $url, array $headers = []): array {
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdr) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) { $hdr[strtolower(trim($p[0]))] = trim($p[1]); }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $hdr, (string) $body];
}

echo "\n{$cyan}══ Gated media streaming over HTTP (M4 e2e) ══{$reset}\n\n";

try {
    $token = fluxfiles_token('e2e-user', ['read', 'write'], ['local'], '', 50, null, 3600);

    // Discover the stream URL the server minted for our file.
    [$st, , $listBody] = httpGet("{$BASE}/api/fm/list?disk=local&path=e2e_stream",
        ["Authorization: Bearer {$token}"]);
    assertEqual(200, $st, 'list ok');
    $list = json_decode($listBody, true);
    $items = $list['data']['items'] ?? $list['data'] ?? [];
    $streamUrl = '';
    foreach ($items as $it) {
        if (($it['name'] ?? '') === 'clip.mp4') { $streamUrl = $it['url'] ?? ''; }
    }
    assertTrue(strpos($streamUrl, '/api/fm/stream?token=') === 0, "minted stream url: {$streamUrl}");
    $fullUrl = $BASE . $streamUrl;

    test('full GET → 200, whole body, Accept-Ranges advertised', function () use ($fullUrl, $content) {
        [$st, $h, $body] = httpGet($fullUrl);
        assertEqual(200, $st);
        assertEqual($content, $body, 'full body');
        assertEqual('bytes', $h['accept-ranges'] ?? '', 'accept-ranges');
        assertTrue(stripos($h['content-disposition'] ?? '', 'inline') !== false, 'inline for video');
        assertEqual('nosniff', $h['x-content-type-options'] ?? '', 'nosniff');
    });

    test('Range GET bytes=5-9 → 206 + exact slice + Content-Range', function () use ($fullUrl, $content) {
        [$st, $h, $body] = httpGet($fullUrl, ['Range: bytes=5-9']);
        assertEqual(206, $st);
        assertEqual(substr($content, 5, 5), $body, 'sliced bytes');
        assertEqual('bytes 5-9/40', $h['content-range'] ?? '', 'content-range');
        assertEqual('5', $h['content-length'] ?? '', 'partial length');
    });

    test('Range GET bytes=-8 (suffix) → last 8 bytes', function () use ($fullUrl, $content) {
        [$st, , $body] = httpGet($fullUrl, ['Range: bytes=-8']);
        assertEqual(206, $st);
        assertEqual(substr($content, -8), $body);
    });

    test('unsatisfiable Range → 416', function () use ($fullUrl) {
        [$st, $h] = httpGet($fullUrl, ['Range: bytes=999-1099']);
        assertEqual(416, $st);
        assertEqual('bytes */40', $h['content-range'] ?? '', '416 extent');
    });

    test('bogus token → 403', function () use ($BASE) {
        [$st] = httpGet("{$BASE}/api/fm/stream?token=not-a-jwt");
        assertEqual(403, $st);
    });

    test('missing token → 403', function () use ($BASE) {
        [$st] = httpGet("{$BASE}/api/fm/stream");
        assertEqual(403, $st);
    });

    test('valid token but traversal path → 403 (defensive path guard)', function () use ($BASE, $SECRET) {
        $bad = StreamToken::mint('local', '../../../../etc/passwd', 'e2e', 300, $SECRET);
        [$st, , $body] = httpGet("{$BASE}/api/fm/stream?token=" . rawurlencode($bad));
        assertTrue($st === 403 || $st === 404, "blocked traversal (got {$st})");
        assertTrue(strpos($body, 'root:') === false, 'never served /etc/passwd');
    });

    test('token scoped to a non-existent file → 404', function () use ($BASE, $SECRET) {
        $tok = StreamToken::mint('local', 'e2e_stream/nope.mp4', 'e2e', 300, $SECRET);
        [$st] = httpGet("{$BASE}/api/fm/stream?token=" . rawurlencode($tok));
        assertEqual(404, $st);
    });

} finally {
    // Tear down the server + temp file; restore the developer's .env.
    proc_terminate($proc);
    proc_close($proc);
    @unlink($uploadRoot . '/e2e_stream/clip.mp4');
    @rmdir($uploadRoot . '/e2e_stream');
    if ($envBackup === null) { @unlink($envFile); }
    else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

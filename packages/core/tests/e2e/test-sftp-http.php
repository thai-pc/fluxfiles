<?php

/**
 * End-to-end HTTP test for the SFTP disk over the wire (M2): upload through the
 * API to an SFTP server, list it, then download it through /api/fm/stream (SFTP
 * has no static/presigned URL, so it streams through the app).
 *
 * Env-gated — skips unless an SFTP server is provided:
 *   FXTEST_SFTP_HOST / _PORT / _USERNAME / _PASSWORD / _ROOT
 *   FXTEST_SFTP_ALLOW_HOST=1   bypass the SSRF guard for a 127.0.0.1 test box
 *
 * Example (docker atmoz/sftp on 127.0.0.1:2222):
 *   FXTEST_SFTP_HOST=127.0.0.1 FXTEST_SFTP_PORT=2222 FXTEST_SFTP_USERNAME=ff \
 *   FXTEST_SFTP_PASSWORD=ffpass FXTEST_SFTP_ROOT=upload FXTEST_SFTP_ALLOW_HOST=1 \
 *   php tests/e2e/test-sftp-http.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$host = getenv('FXTEST_SFTP_HOST') ?: '';
if ($host === '') {
    echo "{$yellow}SKIP{$reset} test-sftp-http.php — set FXTEST_SFTP_HOST to run.\n";
    exit(0);
}

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('f', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8105;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');

$env = "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n"
    . 'SFTP_HOST=' . $host . "\n"
    . 'SFTP_PORT=' . (getenv('FXTEST_SFTP_PORT') ?: '22') . "\n"
    . 'SFTP_USERNAME=' . (getenv('FXTEST_SFTP_USERNAME') ?: '') . "\n"
    . 'SFTP_PASSWORD=' . (getenv('FXTEST_SFTP_PASSWORD') ?: '') . "\n"
    . 'SFTP_ROOT=' . (getenv('FXTEST_SFTP_ROOT') ?: '.') . "\n"
    // The server-side SsrfGuard would block 127.0.0.1; the router trusts hosts in
    // FLUXFILES_SSRF_ALLOW_HOSTS (legit for an SFTP box on a private network).
    . 'FLUXFILES_SSRF_ALLOW_HOSTS=' . (getenv('FXTEST_SFTP_ALLOW_HOST') ? $host : '') . "\n";

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, $env);

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

function http(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, (string) $body];
}

echo "\n{$cyan}══ SFTP disk over HTTP ({$host}) ══{$reset}\n\n";

$prefix = 'sftp_http_' . bin2hex(random_bytes(3));
try {
    $tok = fluxfiles_token('sftp-user', ['read', 'write', 'delete'], ['sftp'], $prefix, 50, null, 3600);
    $name = 'note.txt';
    $content = 'sftp served through the app ' . time();

    test('upload to the SFTP disk via the API', function () use ($BASE, $tok, $content, $name) {
        $tmp = tempnam(sys_get_temp_dir(), 'ffsftp'); file_put_contents($tmp, $content);
        $ch = curl_init("{$BASE}/api/fm/upload");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}"],
            CURLOPT_POSTFIELDS => ['disk' => 'sftp', 'path' => '', 'file' => new CURLFile($tmp, 'text/plain', $name)]]);
        $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); @unlink($tmp);
        assertEqual(200, $st, 'upload ok: ' . $body);
    });

    $streamUrl = '';
    test('list returns the file with a tokened /api/fm/stream URL (no static/presign)', function () use ($BASE, $tok, $name, &$streamUrl) {
        [$st, $body] = http("{$BASE}/api/fm/list?disk=sftp&path=", ["Authorization: Bearer {$tok}"]);
        assertEqual(200, $st);
        $j = json_decode($body, true);
        foreach (($j['data']['items'] ?? $j['data'] ?? []) as $it) {
            if (($it['name'] ?? '') === $name) { $streamUrl = $it['url'] ?? ''; }
        }
        assertTrue(strpos($streamUrl, '/api/fm/stream?token=') === 0, "stream url: {$streamUrl}");
    });

    test('download through /api/fm/stream returns the exact bytes', function () use ($BASE, &$streamUrl, $content) {
        [$st, $body] = http($BASE . $streamUrl);
        assertEqual(200, $st);
        assertEqual($content, $body, 'streamed bytes match');
    });

    test('a stream token for a missing SFTP file → 404', function () use ($BASE, $SECRET, $prefix) {
        $bad = \FluxFiles\StreamToken::mint('sftp', $prefix . '/nope.txt', 'x', 300, $SECRET);
        [$st] = http("{$BASE}/api/fm/stream?token=" . rawurlencode($bad));
        assertEqual(404, $st);
    });

    test('chmod: GET reads the mode, POST sets it (cPanel-style)', function () use ($BASE, $tok, $name) {
        [$st, $body] = http("{$BASE}/api/fm/chmod?disk=sftp&path=" . rawurlencode($name), ["Authorization: Bearer {$tok}"]);
        assertEqual(200, $st, 'get mode: ' . $body);
        $mode = json_decode($body, true)['data']['mode'] ?? '';
        assertTrue(preg_match('/^[0-7]{3}$/', $mode) === 1, "octal mode: {$mode}");

        $ch = curl_init("{$BASE}/api/fm/chmod");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['disk' => 'sftp', 'path' => $name, 'mode' => '600'])]);
        $b = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        assertEqual(200, $s, 'set mode: ' . $b);
        assertEqual('600', json_decode($b, true)['data']['mode'] ?? '', 'mode set to 600');

        [, $g2] = http("{$BASE}/api/fm/chmod?disk=sftp&path=" . rawurlencode($name), ["Authorization: Bearer {$tok}"]);
        assertEqual('600', json_decode($g2, true)['data']['mode'] ?? '', 'persisted');
    });

    test('chmod: invalid mode → 422', function () use ($BASE, $tok, $name) {
        $ch = curl_init("{$BASE}/api/fm/chmod");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['disk' => 'sftp', 'path' => $name, 'mode' => '999'])]);
        curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        assertEqual(422, $s, 'invalid mode rejected');
    });

    test('editor: GET content reads, PUT overwrites (allow_code_edit token)', function () use ($BASE, $SECRET, $prefix, $name) {
        // The default token has allow_code_edit unset (false) → mint an edit-enabled one.
        $edit = \FluxFiles\JwtCompat::encode([
            'sub' => 'ed', 'perms' => ['read', 'write'], 'disks' => ['sftp'], 'prefix' => $prefix,
            'max_upload' => 50, 'allow_code_edit' => true, 'exp' => time() + 3600,
        ], $SECRET);

        [$st, $body] = http("{$BASE}/api/fm/content?disk=sftp&path=" . rawurlencode($name), ["Authorization: Bearer {$edit}"]);
        assertEqual(200, $st, 'get content: ' . $body);
        assertTrue(strlen(json_decode($body, true)['data']['content'] ?? '') > 0, 'has content');

        $ch = curl_init("{$BASE}/api/fm/content");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$edit}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['disk' => 'sftp', 'path' => $name, 'content' => "edited via the code editor\n"])]);
        $b = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        assertEqual(200, $s, 'put content: ' . $b);

        [, $g2] = http("{$BASE}/api/fm/content?disk=sftp&path=" . rawurlencode($name), ["Authorization: Bearer {$edit}"]);
        assertEqual("edited via the code editor\n", json_decode($g2, true)['data']['content'] ?? '', 'persisted');
    });

    test('editor: a token without allow_code_edit → 403 on PUT', function () use ($BASE, $tok, $name) {
        $ch = curl_init("{$BASE}/api/fm/content");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['disk' => 'sftp', 'path' => $name, 'content' => 'x'])]);
        curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        assertEqual(403, $s, 'edit forbidden without the claim');
    });

    test('chmod: a token with allow_chmod=false → 403 on POST', function () use ($BASE, $SECRET, $prefix, $name) {
        $ro = \FluxFiles\JwtCompat::encode([
            'sub' => 'ro', 'perms' => ['read', 'write'], 'disks' => ['sftp'], 'prefix' => $prefix,
            'max_upload' => 50, 'allow_chmod' => false, 'exp' => time() + 3600,
        ], $SECRET);
        $ch = curl_init("{$BASE}/api/fm/chmod");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$ro}", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['disk' => 'sftp', 'path' => $name, 'mode' => '755'])]);
        curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        assertEqual(403, $s, 'allow_chmod=false blocks chmod');
    });

    // Cleanup the uploaded file.
    $del = curl_init("{$BASE}/api/fm/delete");
    curl_setopt_array($del, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['disk' => 'sftp', 'path' => $name])]);
    curl_exec($del); curl_close($del);

} finally {
    proc_terminate($proc); proc_close($proc);
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

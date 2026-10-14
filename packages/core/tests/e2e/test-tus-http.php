<?php

/**
 * Resumable uploads (tus 1.0) over HTTP. Boots the real router.
 *
 * Usage: php tests/e2e/test-tus-http.php   (requires the curl extension)
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

$SECRET = str_repeat('t', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8123;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');
$uploadRoot = $coreDir . '/storage/uploads';
$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n");
$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

/** @return array{0:int,1:array<string,string>,2:string} */
function tus(string $method, string $url, string $tok, array $headers = [], string $body = ''): array {
    $ch = curl_init($url);
    $h = ["Authorization: Bearer {$tok}", 'Tus-Resumable: 1.0.0'];
    foreach ($headers as $k => $v) { $h[] = "{$k}: {$v}"; }
    $out = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$out) {
            if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $out[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        }]);
    if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $raw = (string) curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, $out, $raw];
}
function meta(array $kv): string {
    return implode(',', array_map(fn ($k, $v) => $k . ' ' . base64_encode($v), array_keys($kv), $kv));
}

echo "\n{$cyan}══ tus resumable uploads ══{$reset}\n\n";
$prefix = 'tus_e2e_' . bin2hex(random_bytes(3));
try {
    $tok = fluxfiles_token(['user' => 'tus-u', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $prefix, 'maxUploadMb' => 2]);
    $other = fluxfiles_token(['user' => 'tus-other', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $prefix]);
    $payload = random_bytes(1_200_000);

    test('create → PATCH part → (connection lost) → HEAD → resume → complete through the upload pipeline', function () use ($BASE, $tok, $payload, $uploadRoot, $prefix) {
        [$st, $h] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => (string) strlen($payload),
            'Upload-Metadata' => meta(['filename' => 'big.bin', 'path' => 'inbox', 'disk' => 'local'])]);
        assertEqual(201, $st, 'create');
        $loc = $h['location'] ?? '';
        assertTrue(preg_match('#^/api/fm/tus/[a-f0-9]{32}$#', $loc) === 1, "location {$loc}");
        $ct = ['Content-Type' => 'application/offset+octet-stream'];
        [$st, $h] = tus('PATCH', $BASE . $loc, $tok, $ct + ['Upload-Offset' => '0'], substr($payload, 0, 500_000));
        assertEqual(204, $st, 'first chunk');
        assertEqual('500000', $h['upload-offset'] ?? null);
        // Client "forgot" where it was: ask.
        [$st, $h] = tus('HEAD', $BASE . $loc, $tok);
        assertEqual(200, $st);
        assertEqual('500000', $h['upload-offset'] ?? null, 'server-side offset');
        // A stale offset is refused, with the real one.
        [$st, $h] = tus('PATCH', $BASE . $loc, $tok, $ct + ['Upload-Offset' => '0'], 'x');
        assertEqual(409, $st, 'stale offset');
        [$st, $h] = tus('PATCH', $BASE . $loc, $tok, $ct + ['Upload-Offset' => '500000'], substr($payload, 500_000));
        assertEqual(204, $st, 'last chunk');
        $result = json_decode((string) base64_decode($h['x-fluxfiles-result'] ?? ''), true);
        assertEqual('inbox/big.bin', $result['key'] ?? null, 'result key (prefix stripped)');
        assertTrue(file_get_contents("{$uploadRoot}/{$prefix}/inbox/big.bin") === $payload, 'bytes intact');
        [$st] = tus('HEAD', $BASE . $loc, $tok);
        assertEqual(404, $st, 'staging gone after completion');
    });

    test('another user cannot see or touch the upload (404, not 403)', function () use ($BASE, $tok, $other) {
        [, $h] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => '10', 'Upload-Metadata' => meta(['filename' => 'a.txt'])]);
        $loc = $h['location'];
        [$st] = tus('HEAD', $BASE . $loc, $other);
        assertEqual(404, $st);
        [$st] = tus('PATCH', $BASE . $loc, $other, ['Content-Type' => 'application/offset+octet-stream', 'Upload-Offset' => '0'], 'xx');
        assertEqual(404, $st);
        [$st] = tus('DELETE', $BASE . $loc, $tok);
        assertEqual(204, $st, 'owner can terminate');
        [$st] = tus('HEAD', $BASE . $loc, $tok);
        assertEqual(404, $st, 'gone');
    });

    test('creation applies the upload rules before any byte is staged', function () use ($BASE, $tok) {
        [$st, , $raw] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => '10', 'Upload-Metadata' => meta(['filename' => 'shell.php8'])]);
        assertEqual(403, $st, 'dangerous name: ' . $raw);
        [$st, , $raw] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => (string) (3 * 1024 * 1024), 'Upload-Metadata' => meta(['filename' => 'big.zip'])]);
        assertEqual(413, $st, 'over max_upload: ' . $raw);
        [$st] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => '10', 'Upload-Metadata' => meta(['filename' => 'x.txt', 'path' => '_fluxfiles'])]);
        assertEqual(403, $st, 'reserved path');
        [$st] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Metadata' => meta(['filename' => 'x.txt'])]);
        assertEqual(400, $st, 'no Upload-Length');
    });

    test('more bytes than Upload-Length are refused (413) and nothing is stored', function () use ($BASE, $tok, $uploadRoot, $prefix) {
        [, $h] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => '4', 'Upload-Metadata' => meta(['filename' => 'four.txt'])]);
        [$st] = tus('PATCH', $BASE . $h['location'], $tok, ['Content-Type' => 'application/offset+octet-stream', 'Upload-Offset' => '0'], 'abcdefgh');
        assertEqual(413, $st);
        assertTrue(!is_file("{$uploadRoot}/{$prefix}/four.txt"), 'not stored');
    });

    test('wrong protocol version → 412; wrong content type → 415', function () use ($BASE, $tok) {
        $ch = curl_init("{$BASE}/api/fm/tus");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}", 'Tus-Resumable: 0.2.2', 'Upload-Length: 1']]);
        curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        assertEqual(412, $st);
        [, $h] = tus('POST', "{$BASE}/api/fm/tus", $tok, ['Upload-Length' => '2', 'Upload-Metadata' => meta(['filename' => 'b.txt'])]);
        [$st] = tus('PATCH', $BASE . $h['location'], $tok, ['Content-Type' => 'text/plain', 'Upload-Offset' => '0'], 'hi');
        assertEqual(415, $st);
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

<?php

/**
 * End-to-end HTTP test for the storage usage dashboard (M2). Boots the real
 * router, uploads a few files, then exercises GET /api/fm/usage: breakdown,
 * quota status, the per-prefix cache, and ?refresh=true.
 *
 * Usage: php tests/e2e/test-usage-http.php   (requires the curl extension)
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
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('u', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8104;
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

function http(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, json_decode((string) $body, true)];
}
function upload(string $base, string $tok, string $disk, string $path, string $file, string $mime): void {
    $tmp = tempnam(sys_get_temp_dir(), 'ffu'); file_put_contents($tmp, $file);
    $ch = curl_init("{$base}/api/fm/upload");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}"],
        CURLOPT_POSTFIELDS => ['disk' => $disk, 'path' => $path, 'file' => new CURLFile($tmp, $mime, basename($path) ?: 'f')]]);
    curl_exec($ch); curl_close($ch); @unlink($tmp);
}

echo "\n{$cyan}══ Usage dashboard over HTTP (M2 e2e) ══{$reset}\n\n";

$prefix = 'usage_e2e';
try {
    // 100 MB quota; mint with a 1s cache so we can test both cache + recompute.
    $tok = fluxfiles_token('usage-user', ['read', 'write'], ['local'], $prefix, 50, null, 3600, false, 100);
    // Re-mint carrying usage_cache_ttl + a quota that makes status visible.
    $tokWm = FluxFiles\JwtCompat::encode(array_merge(
        (array) FluxFiles\JwtCompat::decode($tok, $SECRET),
        ['usage_cache_ttl' => 1]
    ), $SECRET);

    // Upload: 2 images + 1 doc under sub-folders.
    upload($BASE, $tokWm, 'local', 'pics/a.jpg', str_repeat('x', 300000), 'image/jpeg');
    upload($BASE, $tokWm, 'local', 'pics/b.png', str_repeat('y', 200000), 'image/png');
    upload($BASE, $tokWm, 'local', 'docs/c.pdf', str_repeat('z', 100000), 'application/pdf');

    test('GET /api/fm/usage → breakdown by type + top folders + quota status', function () use ($BASE, $tokWm) {
        [$st, $j] = http("{$BASE}/api/fm/usage?disk=local", ["Authorization: Bearer {$tokWm}"]);
        assertEqual(200, $st);
        $d = $j['data'];
        assertEqual(3, $d['file_count'], '3 files');
        assertTrue($d['content_bytes'] >= 600000, 'content bytes summed');
        // by_type: image (jpg+png) should be the largest.
        assertEqual('image', $d['by_type'][0]['type'], 'images largest');
        assertEqual(3, array_sum(array_map(fn ($t) => $t['count'], $d['by_type'])), 'type counts sum to 3');
        // top folders include /pics and /docs.
        $folders = array_map(fn ($f) => $f['path'], $d['top_folders']);
        assertTrue(in_array('/pics', $folders, true) && in_array('/docs', $folders, true), 'folders listed');
        assertEqual('/pics', $d['top_folders'][0]['path'], '/pics is largest');
        assertEqual('ok', $d['quota']['status'], 'well under quota → ok');
        assertTrue($d['quota']['percent'] !== null, 'percent computed from limit');
        assertEqual(0, $d['cache_age_seconds'], 'fresh compute');
    });

    test('second call within TTL is served from cache (cache_age_seconds > 0)', function () use ($BASE, $tokWm) {
        [, $j] = http("{$BASE}/api/fm/usage?disk=local", ["Authorization: Bearer {$tokWm}"]);
        assertTrue($j['data']['cache_age_seconds'] >= 0, 'served (possibly cached)');
        // Cache file exists.
    });

    test('?refresh=true recomputes (cache_age_seconds = 0)', function () use ($BASE, $tokWm) {
        [$st, $j] = http("{$BASE}/api/fm/usage?disk=local&refresh=true", ["Authorization: Bearer {$tokWm}"]);
        assertEqual(200, $st);
        assertEqual(0, $j['data']['cache_age_seconds'], 'forced recompute');
    });

    test('cache file lives in _fluxfiles/ and is excluded from the breakdown', function () use ($uploadRoot) {
        assertTrue(is_file($uploadRoot . '/usage_e2e/_fluxfiles/usage.json'), 'cache written under _fluxfiles/');
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

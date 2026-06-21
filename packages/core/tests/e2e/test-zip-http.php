<?php

/**
 * End-to-end HTTP test for zip download (Phase 1 #5, M1). Boots the real router
 * and exercises POST /api/fm/zip over the wire: the streamed binary response
 * (bypassing the JSON encoder, ZipStream sends its own headers) and the JSON
 * error paths (guards/size). Validates the returned bytes with ZipArchive.
 *
 * Usage: php tests/e2e/test-zip-http.php   (requires the curl extension)
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

$SECRET = str_repeat('z', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8103;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');

// A small tree on the local disk under e2e_zip/.
$uploadRoot = $coreDir . '/storage/uploads';
@mkdir($uploadRoot . '/e2e_zip/docs/sub', 0777, true);
file_put_contents($uploadRoot . '/e2e_zip/docs/a.txt', 'alpha');
file_put_contents($uploadRoot . '/e2e_zip/docs/sub/b.txt', 'bravo!');
file_put_contents($uploadRoot . '/e2e_zip/photo.bin', str_repeat('x', 16));

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n");

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

/** curl POST JSON; returns [status, contentType, body]. */
function httpPost(string $url, array $json, string $bearer): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($json),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$bearer}", 'Content-Type: application/json'],
    ]);
    $body = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$st, (string) $ct, (string) $body];
}

echo "\n{$cyan}══ Zip download over HTTP (M1 e2e) ══{$reset}\n\n";

try {
    $tok = fluxfiles_token('e2e', ['read', 'write'], ['local'], '', 50, null, 3600);

    test('POST /zip → a real zip with the selected tree + file', function () use ($BASE, $tok, $coreDir) {
        [$st, $ct, $body] = httpPost("{$BASE}/api/fm/zip", ['disk' => 'local', 'paths' => ['e2e_zip/docs', 'e2e_zip/photo.bin'], 'name' => 'bundle'], $tok);
        assertEqual(200, $st, 'http ' . $st);
        assertTrue(stripos($ct, 'application/zip') !== false, 'zip content-type: ' . $ct);
        assertTrue(strncmp($body, "PK", 2) === 0, 'starts with the PK zip signature');

        $tmp = $coreDir . '/storage/uploads/e2e_zip/out.zip';
        file_put_contents($tmp, $body);
        $za = new \ZipArchive();
        assertTrue($za->open($tmp) === true, 'opens as zip');
        $found = [];
        for ($i = 0; $i < $za->numFiles; $i++) { $found[] = $za->getNameIndex($i); }
        sort($found);
        assertEqual(['docs/a.txt', 'docs/sub/b.txt', 'photo.bin'], $found, 'entries');
        assertEqual('alpha', $za->getFromName('docs/a.txt'), 'a.txt bytes');
        assertEqual('bravo!', $za->getFromName('docs/sub/b.txt'), 'b.txt bytes');
        $za->close();
        @unlink($tmp);
    });

    test('oversize cap → 413 JSON (no zip streamed)', function () use ($BASE, $SECRET) {
        // 1 MB cap, but the tree's photo is fine — craft a token with a tiny cap and a 2MB file.
        file_put_contents(realpath(__DIR__ . '/../..') . '/storage/uploads/e2e_zip/big.bin', str_repeat('y', 2 * 1024 * 1024));
        $capTok = \FluxFiles\JwtCompat::encode(['sub' => 'e2e', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => '', 'max_upload' => 50, 'zip_max_mb' => 1, 'exp' => time() + 3600], $SECRET);
        [$st, $ct, $body] = httpPost("{$BASE}/api/fm/zip", ['disk' => 'local', 'paths' => ['e2e_zip/big.bin']], $capTok);
        assertEqual(413, $st, 'http ' . $st);
        assertTrue(stripos($ct, 'application/json') !== false, 'json error, not a zip: ' . $ct);
        @unlink(realpath(__DIR__ . '/../..') . '/storage/uploads/e2e_zip/big.bin');
    });

    test('allow_zip=false → 403 JSON', function () use ($BASE, $SECRET) {
        $noZip = \FluxFiles\JwtCompat::encode(['sub' => 'e2e', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => '', 'max_upload' => 50, 'allow_zip' => false, 'exp' => time() + 3600], $SECRET);
        [$st, $ct] = httpPost("{$BASE}/api/fm/zip", ['disk' => 'local', 'paths' => ['e2e_zip/docs']], $noZip);
        assertEqual(403, $st);
        assertTrue(stripos($ct, 'application/json') !== false, 'json error');
    });

    test('preview-only (allow_download=false) → 403 JSON', function () use ($BASE, $SECRET) {
        $prev = \FluxFiles\JwtCompat::encode(['sub' => 'e2e', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => '', 'max_upload' => 50, 'allow_download' => false, 'exp' => time() + 3600], $SECRET);
        [$st] = httpPost("{$BASE}/api/fm/zip", ['disk' => 'local', 'paths' => ['e2e_zip/docs']], $prev);
        assertEqual(403, $st);
    });

    // ── Extract (M2) ────────────────────────────────────────────────────────
    test('POST /extract → writes the tree, returns JSON', function () use ($BASE, $tok, $uploadRoot) {
        $za = new \ZipArchive();
        $za->open($uploadRoot . '/e2e_zip/pkg.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $za->addFromString('one.txt', '1');
        $za->addFromString('d/two.txt', '2');
        $za->close();
        [$st, $ct, $body] = httpPost("{$BASE}/api/fm/extract", ['disk' => 'local', 'path' => 'e2e_zip/pkg.zip'], $tok);
        assertEqual(200, $st, 'http ' . $st);
        assertTrue(stripos($ct, 'application/json') !== false, 'json response');
        $j = json_decode($body, true);
        assertEqual(2, $j['data']['extracted'] ?? null, 'extracted count');
        assertEqual('1', file_get_contents($uploadRoot . '/e2e_zip/pkg/one.txt'), 'wrote one.txt');
        assertEqual('2', file_get_contents($uploadRoot . '/e2e_zip/pkg/d/two.txt'), 'wrote nested');
    });

    test('POST /extract: a zip-slip archive → 403 JSON, nothing escapes', function () use ($BASE, $tok, $uploadRoot) {
        $za = new \ZipArchive();
        $za->open($uploadRoot . '/e2e_zip/slip.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $za->addFromString('../escape.txt', 'bad');
        $za->close();
        [$st, $ct] = httpPost("{$BASE}/api/fm/extract", ['disk' => 'local', 'path' => 'e2e_zip/slip.zip'], $tok);
        assertEqual(403, $st);
        assertTrue(stripos($ct, 'application/json') !== false, 'json error');
        assertTrue(!file_exists($uploadRoot . '/escape.txt'), 'no escaped file');
    });

} finally {
    proc_terminate($proc); proc_close($proc);
    $rrm = function (string $dir) use (&$rrm): void {
        foreach (glob($dir . '/*') ?: [] as $f) { is_dir($f) ? $rrm($f) : @unlink($f); }
        @rmdir($dir);
    };
    $rrm($uploadRoot . '/e2e_zip');
    @unlink($uploadRoot . '/escape.txt');
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

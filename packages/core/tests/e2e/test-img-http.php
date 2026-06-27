<?php

/**
 * End-to-end HTTP test for on-demand WebP transform (auto-webp M2). Boots the
 * real router, uploads a JPEG, then exercises /api/fm/img over the wire:
 * convert + cache, cache-hit, width rounding/clamp, quality snap, content
 * negotiation, and the auth/skip rejections.
 *
 * Usage: php tests/e2e/test-img-http.php   (requires the curl extension)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\ImageToken;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('w', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$PORT = 8101;
$BASE = "http://127.0.0.1:{$PORT}";
$coreDir = realpath(__DIR__ . '/../..');

// A 1200×800 JPEG on the local disk under media/.
$uploadRoot = $coreDir . '/storage/uploads';
@mkdir($uploadRoot . '/e2e_img', 0777, true);
$im = imagecreatetruecolor(1200, 800);
imagefilledrectangle($im, 0, 0, 1199, 799, imagecolorallocate($im, 200, 100, 50));
imagejpeg($im, $uploadRoot . '/e2e_img/photo.jpg', 92);
imagedestroy($im);

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
file_put_contents($envFile, "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n");

$proc = proc_open(['php', '-S', "127.0.0.1:{$PORT}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
if (!is_resource($proc)) { fwrite(STDERR, "could not start server\n"); exit(1); }
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

/** curl GET with optional headers; returns [status, headers(lower), body]. */
function httpGet(string $url, array $headers = []): array {
    $ch = curl_init($url); $hdr = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdr) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) { $hdr[strtolower(trim($p[0]))] = trim($p[1]); }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, $hdr, (string) $body];
}
function isWebp(string $b): bool { return strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP'; }
function isAvif(string $b): bool { return substr($b, 4, 4) === 'ftyp' && strpos(substr($b, 8, 24), 'avif') !== false; }

echo "\n{$cyan}══ On-demand WebP transform over HTTP (M2 e2e) ══{$reset}\n\n";

try {
    $WEBP_ACCEPT = ['Accept: image/webp,image/*,*/*'];
    $tok = ImageToken::mint('local', 'e2e_img/photo.jpg', 'e2e', 3600, $SECRET, 2000);
    $imgUrl = "{$BASE}/api/fm/img?token=" . rawurlencode($tok);

    test('convert: ?width=800 → WebP, cached, immutable', function () use ($imgUrl, $WEBP_ACCEPT, $coreDir) {
        [$st, $h, $body] = httpGet("{$imgUrl}&width=800&quality=80", $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertEqual('image/webp', $h['content-type'] ?? '', 'webp content-type');
        assertTrue(isWebp($body), 'body is WebP');
        assertEqual('nosniff', $h['x-content-type-options'] ?? '', 'nosniff');
        assertTrue(stripos($h['cache-control'] ?? '', 'immutable') !== false, 'immutable cache');
        // A cache file landed in _variants/.
        $variants = glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w800_q80_*.webp');
        assertTrue(!empty($variants), 'cache file written to _variants/');
    });

    test('cache hit: second identical request also returns the WebP', function () use ($imgUrl, $WEBP_ACCEPT) {
        [$st, $h, $body] = httpGet("{$imgUrl}&width=800&quality=80", $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertTrue(isWebp($body), 'served from cache, still WebP');
    });

    test('width is rounded to 100px (801 → 800, same cache file)', function () use ($imgUrl, $WEBP_ACCEPT, $coreDir) {
        [$st] = httpGet("{$imgUrl}&width=801&quality=80", $WEBP_ACCEPT);
        assertEqual(200, $st);
        $files = glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w*_q80_*.webp');
        // 800 and 801 must collapse to the same _w800_ key (no _w801_).
        assertTrue(count(array_filter($files, fn ($f) => strpos($f, '_w801_') !== false)) === 0, 'no w801 variant');
    });

    test('quality snaps to the nearest allowed step (83 → 80)', function () use ($imgUrl, $WEBP_ACCEPT, $coreDir) {
        [$st] = httpGet("{$imgUrl}&width=400&quality=83", $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertTrue(!empty(glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w400_q80_*.webp')), 'q83→q80');
    });

    test('content negotiation: no image/webp in Accept → original JPEG', function () use ($imgUrl) {
        [$st, $h, $body] = httpGet("{$imgUrl}&width=400&format=auto", ['Accept: image/png,*/*']);
        assertEqual(200, $st);
        assertEqual('image/jpeg', $h['content-type'] ?? '', 'served original jpeg');
        assertTrue(!isWebp($body), 'not converted for a non-webp client');
    });

    // ── AVIF content negotiation (free in /img) ───────────────────────────────
    $AVIF_ACCEPT = ['Accept: image/avif,image/webp,*/*'];
    $avifOk = (new \FluxFiles\ImageOptimizer())->avifSupported();

    test('content negotiation: Accept image/avif → AVIF (or WebP if build lacks AVIF)', function () use ($imgUrl, $AVIF_ACCEPT, $avifOk, $coreDir) {
        [$st, $h, $body] = httpGet("{$imgUrl}&width=600&quality=80&format=auto", $AVIF_ACCEPT);
        assertEqual(200, $st);
        assertTrue(stripos($h['vary'] ?? '', 'accept') !== false, 'Vary: Accept set for negotiation');
        if ($avifOk) {
            assertEqual('image/avif', $h['content-type'] ?? '', 'negotiated AVIF');
            assertTrue(isAvif($body), 'body is AVIF');
            // AVIF caches to its own .avif file, separate from the .webp variant.
            assertTrue(!empty(glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w600_q80_*.avif')), 'avif cache written');
        } else {
            assertEqual('image/webp', $h['content-type'] ?? '', 'falls back to WebP without AVIF support');
            assertTrue(isWebp($body), 'body is WebP fallback');
        }
    });

    test('explicit format=webp ignores an AVIF-capable Accept', function () use ($imgUrl, $AVIF_ACCEPT) {
        [$st, $h, $body] = httpGet("{$imgUrl}&width=600&quality=80&format=webp", $AVIF_ACCEPT);
        assertEqual(200, $st);
        assertEqual('image/webp', $h['content-type'] ?? '', 'forced webp');
        assertTrue(isWebp($body), 'body is WebP');
    });

    // ── Box sizing: height + fit + DPR (free) ─────────────────────────────────
    test('height + fit=cover crops to fill; caches under a _cover key', function () use ($imgUrl, $WEBP_ACCEPT, $coreDir) {
        [$st, $h] = httpGet("{$imgUrl}&width=400&height=400&fit=cover&quality=80", $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertEqual('image/webp', $h['content-type'] ?? '');
        assertTrue(!empty(glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w400_h400_q80_cover_*.webp')), 'cover cache key');
    });

    test('dpr=2 doubles the physical width (300 → 600) under the hood', function () use ($imgUrl, $WEBP_ACCEPT, $coreDir) {
        [$st] = httpGet("{$imgUrl}&width=300&dpr=2&quality=80", $WEBP_ACCEPT);
        assertEqual(200, $st);
        // 300 CSS px × DPR 2 = 600 physical px → a _w600_ variant, no _w300_.
        assertTrue(!empty(glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w600_q80_*.webp')), 'dpr scaled to w600');
    });

    test('bogus token → 403', function () use ($BASE) {
        [$st] = httpGet("{$BASE}/api/fm/img?token=nope&width=400");
        assertEqual(403, $st);
    });

    test('a stream token is not accepted on /img → 403', function () use ($BASE, $SECRET) {
        $stream = \FluxFiles\StreamToken::mint('local', 'e2e_img/photo.jpg', 'e2e', 300, $SECRET);
        [$st] = httpGet("{$BASE}/api/fm/img?token=" . rawurlencode($stream) . '&width=400');
        assertEqual(403, $st);
    });

    test('token for a non-image path → 403', function () use ($BASE, $SECRET) {
        $t = ImageToken::mint('local', 'e2e_img/notes.txt', 'e2e', 300, $SECRET, 2000);
        [$st] = httpGet("{$BASE}/api/fm/img?token=" . rawurlencode($t) . '&width=400');
        assertEqual(403, $st);
    });

    // ── Watermark (M2) ──────────────────────────────────────────────────────
    test('text watermark: applied, cached under a wm-segment key, differs from clean', function () use ($BASE, $SECRET, $WEBP_ACCEPT, $coreDir) {
        $wm = ['enabled' => true, 'type' => 'text', 'text' => '© Acme', 'position' => 'bottom-right', 'opacity' => 0.6, 'font_size' => 20];
        $t = ImageToken::mint('local', 'e2e_img/photo.jpg', 'e2e', 300, $SECRET, 2000, 0, $wm);
        [$st, $h, $body] = httpGet("{$BASE}/api/fm/img?token=" . rawurlencode($t) . '&width=500&quality=80', $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertEqual('image/webp', $h['content-type'] ?? '', 'webp');
        assertTrue(isWebp($body), 'is webp');
        // Cache file has a _wm… segment, distinct from the clean _w500_q80 key.
        $wmFiles = glob($coreDir . '/storage/uploads/e2e_img/_variants/photo_w500_q80_*_wm*.webp');
        assertTrue(!empty($wmFiles), 'watermarked cache written with wm segment');
    });

    test('watermark token: non-webp client is NOT given the clean original', function () use ($BASE, $SECRET) {
        $wm = ['enabled' => true, 'type' => 'text', 'text' => 'WM', 'opacity' => 0.7];
        $t = ImageToken::mint('local', 'e2e_img/photo.jpg', 'e2e', 300, $SECRET, 2000, 0, $wm);
        [$st, $h, $body] = httpGet("{$BASE}/api/fm/img?token=" . rawurlencode($t) . '&width=400&format=auto', ['Accept: image/png,*/*']);
        assertEqual(200, $st);
        assertEqual('image/webp', $h['content-type'] ?? '', 'forced to watermarked webp, not clean jpeg');
        assertTrue(isWebp($body), 'watermarked webp even for a non-webp client');
    });

    test('logo watermark missing logo → text fallback + warning header (never clean)', function () use ($BASE, $SECRET, $WEBP_ACCEPT) {
        $wm = ['enabled' => true, 'type' => 'logo', 'logo_path' => 'e2e_img/_config/nope.png', 'text' => 'FB', 'opacity' => 0.6];
        $t = ImageToken::mint('local', 'e2e_img/photo.jpg', 'e2e', 300, $SECRET, 2000, 0, $wm);
        [$st, $h, $body] = httpGet("{$BASE}/api/fm/img?token=" . rawurlencode($t) . '&width=400', $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertTrue(stripos($h['x-fluxfiles-warning'] ?? '', 'logo-missing') !== false, 'warning header set');
        assertTrue(isWebp($body), 'fell back to a (text) watermarked webp, not the clean image');
    });

    test('logo watermark: real logo composites onto the image', function () use ($BASE, $SECRET, $WEBP_ACCEPT, $uploadRoot) {
        // Drop a PNG logo into storage and reference it by path.
        @mkdir($uploadRoot . '/e2e_img/_config', 0777, true);
        $lg = imagecreatetruecolor(60, 30); imagesavealpha($lg, true);
        imagefill($lg, 0, 0, imagecolorallocatealpha($lg, 0, 0, 0, 127));
        imagefilledrectangle($lg, 2, 2, 57, 27, imagecolorallocate($lg, 255, 255, 255));
        imagepng($lg, $uploadRoot . '/e2e_img/_config/logo.png'); imagedestroy($lg);

        $wm = ['enabled' => true, 'type' => 'logo', 'logo_path' => 'e2e_img/_config/logo.png', 'position' => 'top-left', 'opacity' => 0.8];
        $t = ImageToken::mint('local', 'e2e_img/photo.jpg', 'e2e', 300, $SECRET, 2000, 0, $wm);
        [$st, $h, $body] = httpGet("{$BASE}/api/fm/img?token=" . rawurlencode($t) . '&width=600', $WEBP_ACCEPT);
        assertEqual(200, $st);
        assertTrue(!isset($h['x-fluxfiles-warning']), 'no missing-logo warning when the logo exists');
        assertTrue(isWebp($body), 'logo watermarked webp');
    });

} finally {
    proc_terminate($proc); proc_close($proc);
    @array_map('unlink', glob($uploadRoot . '/e2e_img/_variants/*') ?: []);
    @rmdir($uploadRoot . '/e2e_img/_variants');
    @array_map('unlink', glob($uploadRoot . '/e2e_img/_config/*') ?: []);
    @rmdir($uploadRoot . '/e2e_img/_config');
    @unlink($uploadRoot . '/e2e_img/photo.jpg');
    @rmdir($uploadRoot . '/e2e_img');
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

<?php

/**
 * Unit tests for on-demand WebP transform (auto-webp M1):
 *   - ImageOptimizer::transform()        (raster → resized WebP; skip rules)
 *   - ImageOptimizer::transformCacheKey() (lives in _variants/, ver-stamped)
 *   - ImageOptimizer::isAnimatedGif()
 *
 * Usage: php tests/unit/test-image-transform.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ImageOptimizer;
use FluxFiles\ImageToken;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** A solid-colour JPEG of given size as a binary string. */
function jpegBytes(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 120, 60, 200));
    ob_start(); imagejpeg($im, null, 90); $b = ob_get_clean();
    imagedestroy($im);
    return (string) $b;
}

/** A PNG of given size as a binary string. */
function pngBytes(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 30, 200, 90));
    ob_start(); imagepng($im); $b = ob_get_clean();
    imagedestroy($im);
    return (string) $b;
}

/** A valid minimal 2-frame (animated) GIF, 1×1. */
function animatedGifBytes(): string
{
    return hex2bin(
        '474946383961' .          // "GIF89a"
        '0100' . '0100' .         // 1×1
        '80' . '00' . '00' .      // GCT present (2 colours), bg, aspect
        'ffffff' . '000000' .     // global colour table
        '21ff0b' . '4e45545343415045322e30' . '0301000000' . // NETSCAPE2.0 loop
        '21f904' . '000a000000' . '2c000000000100010000' . '0202440100' . // frame 1
        '21f904' . '000a000000' . '2c000000000100010000' . '0202440100' . // frame 2
        '3b'                      // trailer
    );
}

echo "\n{$cyan}══ On-demand WebP transform (M1) ══{$reset}\n\n";

$opt = new ImageOptimizer();

// ── transform() — happy paths ─────────────────────────────────────────────
test('transform: JPEG → WebP, scaled down to requested width, aspect kept', function () use ($opt) {
    $src = jpegBytes(1000, 500);
    $out = $opt->transform($src, 400, 80);
    assertTrue($out !== null, 'returned a result');
    assertEqual(400, $out['width'], 'width scaled to 400');
    assertEqual(200, $out['height'], 'aspect ratio kept (200)');
    // WebP magic: "RIFF"...."WEBP"
    assertTrue(strncmp($out['data'], 'RIFF', 4) === 0 && substr($out['data'], 8, 4) === 'WEBP', 'is WebP');
    assertTrue(strlen($out['data']) < strlen($src), 'smaller than the source jpeg');
});

test('transform: PNG → WebP works too', function () use ($opt) {
    $out = $opt->transform(pngBytes(600, 600), 300, 75);
    assertTrue($out !== null && $out['width'] === 300 && $out['height'] === 300, 'png converted+scaled');
    assertTrue(substr($out['data'], 8, 4) === 'WEBP', 'is WebP');
});

test('transform: width 0 keeps original dimensions; never upsizes', function () use ($opt) {
    $keep = $opt->transform(jpegBytes(320, 240), 0, 80);
    assertTrue($keep !== null && $keep['width'] === 320 && $keep['height'] === 240, 'kept 320×240');
    $up = $opt->transform(jpegBytes(320, 240), 800, 80); // larger than source
    assertEqual(320, $up['width'], 'scaleDown never upsizes past source width');
});

test('transform: lower quality yields a smaller file', function () use ($opt) {
    $src = jpegBytes(800, 600);
    $hi = $opt->transform($src, 800, 90);
    $lo = $opt->transform($src, 800, 40);
    assertTrue(strlen($lo['data']) < strlen($hi['data']), 'q40 < q90');
});

// ── transform() — skip rules (return null = serve original) ───────────────
test('transform: non-image / SVG bytes → null', function () use ($opt) {
    assertEqual(null, $opt->transform('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 200, 80), 'svg skipped');
    assertEqual(null, $opt->transform('not an image at all', 200, 80), 'garbage skipped');
});

test('transform: animated GIF → null (not flattened)', function () use ($opt) {
    $gif = animatedGifBytes();
    assertTrue(ImageOptimizer::isAnimatedGif($gif), 'fixture is detected animated');
    assertEqual(null, $opt->transform($gif, 100, 80), 'animated gif left alone');
});

test('transform: decompression-bomb (huge declared dimensions) → null', function () use ($opt) {
    // A PNG header claiming 30001×30001 (> 30 MP cap) without the pixel data.
    // getimagesizefromstring reads the IHDR dimensions cheaply; we must refuse it.
    $ihdr = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 30001) . pack('N', 30001) . "\x08\x02\x00\x00\x00";
    assertEqual(null, $opt->transform($ihdr . str_repeat("\x00", 64), 800, 80), 'bomb refused');
});

// ── isAnimatedGif ─────────────────────────────────────────────────────────
test('isAnimatedGif: static GIF (GD, 1 frame) → false', function () {
    $im = imagecreatetruecolor(4, 4);
    ob_start(); imagegif($im); $gif = ob_get_clean(); imagedestroy($im);
    assertEqual(false, ImageOptimizer::isAnimatedGif((string) $gif), 'single-frame is not animated');
});

test('isAnimatedGif: non-GIF → false', function () {
    assertEqual(false, ImageOptimizer::isAnimatedGif(jpegBytes(10, 10)), 'jpeg is not a gif');
});

// ── transformCacheKey ─────────────────────────────────────────────────────
test('transformCacheKey: lives in _variants/, ver-stamped, sanitized', function () {
    $k = ImageOptimizer::transformCacheKey('users/42/photo.jpg', 800, 80, '1781900000');
    assertEqual('users/42/_variants/photo_w800_q80_1781900000.webp', $k, 'key shape');

    // Root-level file → top-level _variants.
    assertEqual('_variants/pic_w400_q75_abc123.webp',
        ImageOptimizer::transformCacheKey('pic.png', 400, 75, 'abc123'), 'root key');

    // ver is sanitized (no path-y chars) and truncated to 12.
    $safe = ImageOptimizer::transformCacheKey('a.jpg', 100, 60, '../../etc/passwd');
    assertTrue(strpos($safe, '..') === false && strpos($safe, '/etc') === false, 'ver cannot inject traversal');
});

/** A small PNG logo with an alpha channel (transparent corner) as binary. */
function pngLogo(int $w = 80, int $h = 40): string
{
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent
    imagefilledrectangle($im, 4, 4, $w - 5, $h - 5, imagecolorallocate($im, 250, 250, 250));
    ob_start(); imagepng($im); $b = ob_get_clean();
    imagedestroy($im);
    return (string) $b;
}

// ── Watermark (M1) ─────────────────────────────────────────────────────────
test('transform: text watermark produces a valid WebP (all 5 positions)', function () use ($opt) {
    foreach (\FluxFiles\ImageOptimizer::WM_POSITIONS as $pos) {
        $out = $opt->transform(jpegBytes(600, 400), 400, 80, [
            'enabled' => true, 'type' => 'text', 'text' => '© Acme', 'position' => $pos,
            'opacity' => 0.6, 'font_size' => 22,
        ]);
        assertTrue($out !== null, "watermarked result for $pos");
        assertTrue(substr($out['data'], 8, 4) === 'WEBP', "is WebP ($pos)");
        assertEqual(400, $out['width'], "resized then watermarked ($pos)");
    }
});

test('transform: logo watermark composites + is a valid WebP', function () use ($opt) {
    $out = $opt->transform(jpegBytes(800, 600), 400, 80, [
        'enabled' => true, 'type' => 'logo', 'logo_data' => pngLogo(), 'position' => 'bottom-right',
        'opacity' => 0.7,
    ]);
    assertTrue($out !== null && substr($out['data'], 8, 4) === 'WEBP', 'logo watermarked webp');
    assertEqual(400, $out['width'], 'width preserved');
});

test('transform: watermarked output differs from the clean output', function () use ($opt) {
    $src = jpegBytes(500, 500);
    $clean = $opt->transform($src, 300, 80);
    $marked = $opt->transform($src, 300, 80, ['enabled' => true, 'type' => 'text', 'text' => 'WM', 'opacity' => 0.8]);
    assertTrue($clean['data'] !== $marked['data'], 'watermark changed the pixels');
});

test('transform: opacity boundaries 0.0 and 1.0 do not error', function () use ($opt) {
    foreach ([0.0, 1.0] as $op) {
        $out = $opt->transform(jpegBytes(300, 200), 200, 80, ['enabled' => true, 'type' => 'text', 'text' => 'x', 'opacity' => $op]);
        assertTrue($out !== null && substr($out['data'], 8, 4) === 'WEBP', "opacity $op ok");
    }
});

test('transform: enabled but empty text → no-op (still converts)', function () use ($opt) {
    $out = $opt->transform(jpegBytes(300, 200), 200, 80, ['enabled' => true, 'type' => 'text', 'text' => '   ']);
    assertTrue($out !== null && substr($out['data'], 8, 4) === 'WEBP', 'empty text is a safe no-op');
});

test('watermarkSignature: stable, distinguishes config + logo version', function () {
    $cfg = ['enabled' => true, 'type' => 'text', 'text' => '© A', 'position' => 'center', 'opacity' => 0.6, 'font_size' => 24];
    $a = \FluxFiles\ImageOptimizer::watermarkSignature($cfg, 1000);
    assertTrue(strpos($a, 'wm') === 0 && strlen($a) === 10, 'wm + 8 hex');
    assertEqual($a, \FluxFiles\ImageOptimizer::watermarkSignature($cfg, 1000), 'stable for same input');
    assertTrue($a !== \FluxFiles\ImageOptimizer::watermarkSignature($cfg, 2000), 'changes with logo version');
    assertTrue($a !== \FluxFiles\ImageOptimizer::watermarkSignature(['enabled' => true, 'text' => 'B'] + $cfg, 1000), 'changes with text');
    assertEqual('', \FluxFiles\ImageOptimizer::watermarkSignature(['enabled' => false]), 'disabled → empty');
    assertEqual('', \FluxFiles\ImageOptimizer::watermarkSignature(null), 'null → empty');
});

test('transformCacheKey: watermark variant segment separates marked from clean', function () {
    $clean = \FluxFiles\ImageOptimizer::transformCacheKey('p.jpg', 800, 80, '111');
    $marked = \FluxFiles\ImageOptimizer::transformCacheKey('p.jpg', 800, 80, '111', 'wmABCD1234');
    assertEqual('_variants/p_w800_q80_111.webp', $clean, 'clean key unchanged (backward compat)');
    assertEqual('_variants/p_w800_q80_111_wmABCD1234.webp', $marked, 'watermarked key has the wm segment');
});

// ── ImageToken (auth for the /api/fm/img endpoint) ────────────────────────
$imgSecret = str_repeat('i', 40);

test('ImageToken: mint → verify round-trips disk/path/maxWidth', function () use ($imgSecret) {
    $tok = ImageToken::mint('s3', 'users/7/pic.jpg', 'u7', 3600, $imgSecret, 1600);
    $s = ImageToken::verify($tok, $imgSecret);
    assertEqual('s3', $s['disk']);
    assertEqual('users/7/pic.jpg', $s['path']);
    assertEqual(1600, $s['maxWidth']);
    assertEqual('u7', $s['sub']);
});

test('ImageToken: a stream token (t=stream) is rejected here', function () use ($imgSecret) {
    $stream = \FluxFiles\StreamToken::mint('local', 'pic.jpg', 'u', 3600, $imgSecret);
    try { ImageToken::verify($stream, $imgSecret); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('img_token_invalid', $e->getErrorCode()); }
});

test('ImageToken: wrong secret / expired → 403', function () use ($imgSecret) {
    $tok = ImageToken::mint('local', 'a.jpg', 'u', 3600, $imgSecret, 800);
    try { ImageToken::verify($tok, str_repeat('x', 40)); throw new \RuntimeException('bad sig'); }
    catch (ApiException $e) { assertEqual('img_token_invalid', $e->getErrorCode()); }

    $expired = \FluxFiles\JwtCompat::encode(
        ['t' => 'img', 'disk' => 'local', 'path' => 'a.jpg', 'mw' => 800, 'iat' => time() - 100, 'exp' => time() - 10],
        $imgSecret
    );
    try { ImageToken::verify($expired, $imgSecret); throw new \RuntimeException('expired'); }
    catch (ApiException $e) { assertEqual('img_token_invalid', $e->getErrorCode()); }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

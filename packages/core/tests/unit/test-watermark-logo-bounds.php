<?php

/**
 * The watermark logo is untrusted input: it must be pixel-checked and scaled to
 * fit the base image.
 *
 * Regression (audit 2026-11-20, R2-3): placeLogoAt() decoded the logo without a
 * pixel check and scale()d it to a share of the base WIDTH keeping its aspect
 * ratio, so a 90-byte 1x600 PNG became (bw x 600*bw) pixels and GD allocated
 * gigabytes of native memory outside memory_limit.
 *
 * Usage: php tests/unit/test-watermark-logo-bounds.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\ImageOptimizer;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 40, 40));
    ob_start(); imagepng($im); return (string) ob_get_clean();
}
/** A PNG whose header claims $w x $h pixels (body is garbage): enough for getimagesize. */
function pngHeaderOnly(int $w, int $h): string
{
    $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    $chunk = pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    return "\x89PNG\r\n\x1a\n" . $chunk . str_repeat("\0", 16);
}

$opt = new ImageOptimizer();
$base = png(200, 100);

echo "\nwatermark logo bounds\n";

test('a tall 1x600 logo is fitted to the canvas, not blown up to 200x120000', function () use ($opt, $base) {
    $t = microtime(true);
    $r = $opt->burnWatermark($base, ['type' => 'logo', 'logo_data' => png(1, 600), 'x' => 0, 'y' => 0, 'scale' => 1.0, 'opacity' => 0.5], 'png');
    assertTrue($r['width'] === 200 && $r['height'] === 100, 'output keeps the base size');
    assertTrue(microtime(true) - $t < 5, 'must finish quickly');
});

test('a logo whose header claims 10000x10000 pixels is refused (413)', function () use ($opt, $base) {
    try {
        $opt->burnWatermark($base, ['type' => 'logo', 'logo_data' => pngHeaderOnly(10000, 10000), 'scale' => 0.25], 'png');
    } catch (ApiException $e) {
        assertTrue($e->getCode() === 413, 'expected 413, got ' . $e->getCode());
        return;
    }
    throw new \RuntimeException('bomb logo accepted');
});

test('logo bytes that are not an image are refused (415)', function () use ($opt, $base) {
    try {
        $opt->burnWatermark($base, ['type' => 'logo', 'logo_data' => 'not an image', 'scale' => 0.25], 'png');
    } catch (ApiException $e) {
        assertTrue($e->getCode() === 415, 'expected 415, got ' . $e->getCode());
        return;
    }
    throw new \RuntimeException('garbage logo accepted');
});

test('a normal wide logo still scales to the requested share of the width', function () use ($opt, $base) {
    $r = $opt->burnWatermark($base, ['type' => 'logo', 'logo_data' => png(40, 10), 'scale' => 0.5, 'opacity' => 1], 'png');
    assertTrue($r['width'] === 200 && $r['height'] === 100, 'output keeps the base size');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

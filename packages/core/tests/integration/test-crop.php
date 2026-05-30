<?php

/**
 * Image crop — FileManager::cropImage() output, in-place vs save-as, collision
 * guard on save-as, format derivation, and out-of-bounds coordinates. Covers
 * TEST-PLAN section 10 (crop edge).
 *
 * Usage:
 *   php tests/integration/test-crop.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

function placeImage(string $root, string $rel, int $w, int $h, string $fmt = 'png'): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 40, 120, 200));
    $f = $root . '/' . $rel; @mkdir(dirname($f), 0777, true);
    switch ($fmt) {
        case 'jpg': imagejpeg($im, $f, 90); break;
        case 'webp': imagewebp($im, $f); break;
        default: imagepng($im, $f);
    }
    imagedestroy($im);
}
function makeFM(array $perms = ['read', 'write', 'delete']): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-crop-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $claims = new Claims('u', $perms, ['local'], '', 50, null, 0, false);
    return [new FileManager($dm, $claims, new StorageMetadataHandler($dm)), $dm->disk('local'), $root];
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Crop Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

test('crop save-as → new file at requested dimensions', function () {
    [$fm, $fs, $root] = makeFM();
    placeImage($root, 'src.png', 400, 300);
    $r = $fm->cropImage('local', 'src.png', 50, 40, 200, 150, 'out.png');
    assertEqual(200, $r['width'] ?? 0, 'width 200');
    assertEqual(150, $r['height'] ?? 0, 'height 150');
    assertTrue($fs->fileExists('out.png'), 'output written');
    assertTrue($fs->fileExists('src.png'), 'source kept');
});

test('crop in-place (no savePath) overwrites the source', function () {
    [$fm, $fs, $root] = makeFM();
    placeImage($root, 'p.png', 400, 300);
    $r = $fm->cropImage('local', 'p.png', 0, 0, 100, 80);
    assertEqual(100, $r['width'] ?? 0, 'cropped width');
    assertTrue($fs->fileExists('p.png'), 'still there (overwritten in place)');
});

test('crop save-as onto an existing different file → 409 name_exists', function () {
    [$fm, , $root] = makeFM();
    placeImage($root, 'src.png', 400, 300);
    placeImage($root, 'taken.png', 50, 50);
    try {
        $fm->cropImage('local', 'src.png', 0, 0, 100, 100, 'taken.png');
        throw new \RuntimeException('should throw');
    } catch (ApiException $e) {
        assertEqual('name_exists', $e->getErrorCode(), 'expected name_exists');
    }
});

test('crop output format follows the source extension', function () {
    [$fm, $fs, $root] = makeFM();
    placeImage($root, 'a.jpg', 300, 300, 'jpg');
    $fm->cropImage('local', 'a.jpg', 0, 0, 100, 100);   // in-place
    assertEqual(IMAGETYPE_JPEG, getimagesizefromstring($fs->read('a.jpg'))[2], 'jpg source → JPEG output');

    placeImage($root, 'b.webp', 300, 300, 'webp');
    $fm->cropImage('local', 'b.webp', 0, 0, 100, 100);
    assertEqual(IMAGETYPE_WEBP, getimagesizefromstring($fs->read('b.webp'))[2], 'webp source → WEBP output');
});

test('crop with out-of-bounds coordinates does not crash', function () {
    [$fm, $fs, $root] = makeFM();
    placeImage($root, 'small.png', 100, 100);
    // request a crop window larger than / past the image bounds
    $r = $fm->cropImage('local', 'small.png', 80, 80, 300, 300, 'oob.png');
    assertTrue(is_array($r) && ($r['width'] ?? 0) > 0, 'returns a valid result');
    assertTrue($fs->fileExists('oob.png'), 'output written');
});

test('crop requires write permission', function () {
    [$fm, , $root] = makeFM(['read']);   // read-only
    placeImage($root, 'ro.png', 200, 200);
    try {
        $fm->cropImage('local', 'ro.png', 0, 0, 50, 50, 'x.png');
        throw new \RuntimeException('should throw');
    } catch (ApiException $e) {
        assertEqual('permission_denied', $e->getErrorCode(), 'expected permission_denied');
    }
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

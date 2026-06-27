<?php

/**
 * FileManager::applyWatermark (free drag-and-drop watermark editor — burn-in).
 * Distinct from the on-the-fly /img overlay. Tests logo + text burn, replace vs
 * save-as, extension immutability, and guards.
 *
 * Usage: php packages/core/tests/integration/test-watermark.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectApi(callable $f, string $code): void {
    try { $f(); throw new \RuntimeException("expected {$code}"); }
    catch (ApiException $e) { assertEqual($code, $e->getErrorCode()); }
}

function pngBytes(int $w, int $h, array $rgb = [40, 40, 40]): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$rgb));
    ob_start(); imagepng($im); return (string) ob_get_clean();
}

function setup(): array {
    $root = sys_get_temp_dir() . '/ff-wm-' . uniqid();
    @mkdir($root, 0777, true);
    file_put_contents("$root/photo.png", pngBytes(400, 300));
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [$fm, $dm, $root];
}

echo "\n{$cyan}══ Watermark editor (applyWatermark) ══{$reset}\n\n";

test('logo watermark → burned in place, stays PNG', function () {
    [$fm, $dm, $root] = setup();
    $logo = pngBytes(100, 40, [255, 0, 0]);
    $r = $fm->applyWatermark('local', 'photo.png', [
        'type' => 'logo', 'logo_data' => $logo, 'x' => 0.6, 'y' => 0.7, 'scale' => 0.3, 'opacity' => 0.7,
    ]);
    assertEqual('photo.png', $r['key'], 'in place');
    assertEqual(400, $r['width']); assertEqual(300, $r['height']);
    $out = (string) file_get_contents("$root/photo.png");
    assertTrue(strncmp($out, "\x89PNG", 4) === 0, 'valid PNG');
});

test('text watermark → burned, save-as copy keeps original', function () {
    [$fm, $dm, $root] = setup();
    $r = $fm->applyWatermark('local', 'photo.png', [
        'type' => 'text', 'text' => '(c) FluxFiles', 'x' => 0.1, 'y' => 0.1, 'font_size' => 22, 'opacity' => 0.8, 'color' => '#ffcc00',
    ], 'photo_wm.png');
    assertEqual('photo_wm.png', $r['key'], 'save-as');
    assertTrue(is_file("$root/photo.png"), 'original kept');
    assertTrue(is_file("$root/photo_wm.png"), 'copy written');
});

test('extension immutable: dest ext must match', function () {
    [$fm] = setup();
    expectApi(fn () => $fm->applyWatermark('local', 'photo.png', ['type' => 'text', 'text' => 'x'], 'photo_wm.jpg'), 'ext_changed');
});

test('save-as over an existing file → conflict', function () {
    [$fm, $dm, $root] = setup();
    file_put_contents("$root/taken.png", pngBytes(10, 10));
    expectApi(fn () => $fm->applyWatermark('local', 'photo.png', ['type' => 'text', 'text' => 'x'], 'taken.png'), 'name_exists');
});

test('guards: not an image → 415; missing → 404; no write perm → 403', function () {
    [$fm, $dm, $root] = setup();
    file_put_contents("$root/notes.txt", 'hi');
    expectApi(fn () => $fm->applyWatermark('local', 'notes.txt', ['type' => 'text', 'text' => 'x']), 'not_image');
    expectApi(fn () => $fm->applyWatermark('local', 'nope.png', ['type' => 'text', 'text' => 'x']), 'not_found');
    $dm2 = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $ro = new Claims('u', ['read'], ['local'], '', 50, null, 0);
    $fmRo = new FileManager($dm2, $ro, new StorageMetadataHandler($dm2));
    expectApi(fn () => $fmRo->applyWatermark('local', 'photo.png', ['type' => 'text', 'text' => 'x']), 'permission_denied');
});

test('overlay-watermark token cannot burn in (mutually exclusive) → 409', function () {
    [, $dm, $root] = setup();
    // A token with an OVERLAY watermark enabled (→ preview-only). Burning in would
    // double-watermark a file the token can't even download, so it's rejected.
    $claims = Claims::fromJwtPayload((object) [
        'sub' => 'u', 'perms' => ['read', 'write'], 'disks' => ['local'],
        'watermark_enabled' => true, 'watermark_type' => 'text', 'watermark_text' => '© x',
    ]);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    expectApi(fn () => $fm->applyWatermark('local', 'photo.png', ['type' => 'text', 'text' => 'y']), 'watermark_overlay_active');
    assertTrue($claims->watermark !== null, 'overlay watermark assembled');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

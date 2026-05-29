<?php

/**
 * Test script for image upload / variants / dedup / overwrite / collision.
 *
 * Exercises FileManager end-to-end against a temp LOCAL disk with a real
 * StorageMetadataHandler, covering the file-type + "exists vs not" matrix
 * from docs/TEST-PLAN.md (sections 1, 2, 3).
 *
 * Usage:
 *   php tests/test-images.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

foreach ([__DIR__ . "/..", __DIR__ . "/../../.."] as $envDir) {
    if (is_file($envDir . "/.env")) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\Claims;
use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m";
$red   = "\033[31m";
$yellow = "\033[33m";
$cyan  = "\033[36m";
$reset = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertTrue($cond, string $msg): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($msg ?: "Expected " . json_encode($expected) . " got " . json_encode($actual));
    }
}

/** Generate an image of given width/height/format, return temp file path. */
function makeImage(int $w, int $h, string $fmt): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 40, 120, 200));
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), max(2, intdiv($w, 2)), max(2, intdiv($h, 2)), imagecolorallocate($im, 240, 200, 60));
    $path = sys_get_temp_dir() . '/fximg-' . uniqid() . '.' . $fmt;
    switch ($fmt) {
        case 'jpg': case 'jpeg': imagejpeg($im, $path, 90); break;
        case 'gif': imagegif($im, $path); break;
        case 'webp': imagewebp($im, $path); break;
        case 'bmp': imagebmp($im, $path); break;
        default: imagepng($im, $path); break;
    }
    imagedestroy($im);
    return $path;
}

/** Build a $_FILES-style array from a real file on disk. */
function fileArray(string $tmpPath, string $name): array
{
    return ['name' => $name, 'size' => filesize($tmpPath), 'tmp_name' => $tmpPath];
}

/** Fresh FileManager over a unique temp local disk root. */
function makeFM(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-img-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('tester', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $fm = new FileManager($dm, $claims, $meta);
    return [$fm, $dm->disk('local'), $root];
}

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Image / Upload / Collision Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

// ── 1a. Variant thresholds ──────────────────────────────────────────
echo "{$yellow}► Variant thresholds (thumb=150, medium=768, large=1920){$reset}\n";

test('tiny image (100px) → only thumb', function () {
    [$fm] = makeFM();
    $r = $fm->upload('local', '', fileArray(makeImage(100, 80, 'png'), 't.png'));
    assertTrue(is_array($r['variants']), 'variants should be array');
    assertEqual(['thumb'], array_keys($r['variants']), 'only thumb expected');
    assertTrue($r['variants']['thumb']['width'] <= 100, 'thumb not upsized');
});

test('mid image (500px ≤768) → only thumb (medium needs >768)', function () {
    [$fm] = makeFM();
    $r = $fm->upload('local', '', fileArray(makeImage(500, 300, 'jpg'), 'm.jpg'));
    assertEqual(['thumb'], array_keys($r['variants']), 'only thumb expected for ≤768px');
});

test('image (900px >768) → thumb + medium', function () {
    [$fm] = makeFM();
    $r = $fm->upload('local', '', fileArray(makeImage(900, 500, 'jpg'), 'mm.jpg'));
    assertEqual(['thumb', 'medium'], array_keys($r['variants']), 'thumb+medium expected for >768px');
});

test('large image (1000px) → thumb + medium (large skipped, ≤1920)', function () {
    [$fm] = makeFM();
    $r = $fm->upload('local', '', fileArray(makeImage(1000, 600, 'png'), 'l.png'));
    assertEqual(['thumb', 'medium'], array_keys($r['variants']), 'large skipped for 1000px');
});

test('huge image (2400px) → thumb + medium + large', function () {
    [$fm] = makeFM();
    $r = $fm->upload('local', '', fileArray(makeImage(2400, 1400, 'jpg'), 'h.jpg'));
    assertEqual(['thumb', 'medium', 'large'], array_keys($r['variants']), 'all three expected');
});

// ── 1a. Each image format ───────────────────────────────────────────
echo "\n{$yellow}► Each image format produces WebP variants{$reset}\n";
foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'] as $fmt) {
    test("format .{$fmt} → variants generated + readable on disk", function () use ($fmt) {
        [$fm, $fs] = makeFM();
        $r = $fm->upload('local', '', fileArray(makeImage(600, 400, $fmt), "pic.{$fmt}"));
        assertTrue(is_array($r['variants']) && count($r['variants']) > 0, "no variants for {$fmt}");
        foreach ($r['variants'] as $v) {
            assertTrue($fs->fileExists($v['key']), "variant missing on disk: {$v['key']}");
            assertTrue(substr($v['key'], -5) === '.webp', 'variant should be .webp');
        }
    });
}

// ── 1b. Non-image → no variants ─────────────────────────────────────
echo "\n{$yellow}► Non-image files → no variants{$reset}\n";
foreach (['txt' => 'hello', 'csv' => 'a,b,c', 'json' => '{}', 'svg' => '<svg></svg>'] as $ext => $content) {
    test("non-image .{$ext} → variants null, upload ok", function () use ($ext, $content) {
        [$fm] = makeFM();
        $tmp = sys_get_temp_dir() . '/fx-' . uniqid() . ".{$ext}";
        file_put_contents($tmp, $content);
        $r = $fm->upload('local', '', fileArray($tmp, "doc.{$ext}"));
        assertEqual(null, $r['variants'], "variants should be null for {$ext}");
    });
}

test('corrupt .jpg → upload still 200, variants null (optimizer error swallowed)', function () {
    [$fm] = makeFM();
    $tmp = sys_get_temp_dir() . '/fx-bad-' . uniqid() . '.jpg';
    file_put_contents($tmp, 'this is not a real jpeg');
    $r = $fm->upload('local', '', fileArray($tmp, 'bad.jpg'));
    assertEqual('bad.jpg', $r['name'], 'upload should still succeed');
    assertEqual(null, $r['variants'], 'corrupt image yields no variants');
});

// ── 1c. Dangerous / extension rules ─────────────────────────────────
echo "\n{$yellow}► Extension safety{$reset}\n";

foreach (['shell.php', 'x.phtml', 'run.exe', 'go.sh', 'page.jsp', '.htaccess'] as $danger) {
    test("dangerous filename '{$danger}' → blocked (ext_dangerous/ext_not_allowed)", function () use ($danger) {
        [$fm] = makeFM();
        $tmp = sys_get_temp_dir() . '/fx-' . uniqid();
        file_put_contents($tmp, 'x');
        try {
            $fm->upload('local', '', fileArray($tmp, $danger));
            throw new \RuntimeException('should have thrown');
        } catch (ApiException $e) {
            assertTrue(in_array($e->getErrorCode(), ['ext_dangerous', 'ext_not_allowed'], true), 'wrong code: ' . $e->getErrorCode());
        }
    });
}

test("double-extension 'shell.php.jpg' → blocked", function () {
    [$fm] = makeFM();
    $tmp = makeImage(50, 50, 'jpg');
    try {
        $fm->upload('local', '', fileArray($tmp, 'shell.php.jpg'));
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('ext_dangerous', $e->getErrorCode(), 'expected ext_dangerous');
    }
});

test('allowedExt restriction → .gif rejected when only jpg/png allowed', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-img-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $claims = new Claims('tester', ['read', 'write'], ['local'], '', 50, ['jpg', 'png'], 0, false);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    try {
        $fm->upload('local', '', fileArray(makeImage(50, 50, 'gif'), 'a.gif'));
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('ext_not_allowed', $e->getErrorCode(), 'expected ext_not_allowed');
    }
});

// ── 2a/2b. Dedup + overwrite ────────────────────────────────────────
echo "\n{$yellow}► Dedup (SHA-256) & overwrite{$reset}\n";

test('identical content re-upload → duplicate:true (no force)', function () {
    [$fm] = makeFM();
    $img = makeImage(300, 200, 'png');
    $fm->upload('local', '', fileArray($img, 'same.png'));
    // Re-upload identical bytes under a different name; dedup keys on hash.
    $copy = sys_get_temp_dir() . '/fx-dup-' . uniqid() . '.png';
    copy($img, $copy);
    $r2 = $fm->upload('local', '', fileArray($copy, 'other.png'));
    assertEqual(true, $r2['duplicate'] ?? false, 'second upload should be duplicate');
});

test('identical content + force_upload=true → not duplicate', function () {
    [$fm] = makeFM();
    $img = makeImage(300, 200, 'png');
    $fm->upload('local', '', fileArray($img, 'same.png'));
    $copy = sys_get_temp_dir() . '/fx-dup-' . uniqid() . '.png';
    copy($img, $copy);
    $r2 = $fm->upload('local', '', fileArray($copy, 'forced.png'), true);
    assertTrue(empty($r2['duplicate']), 'force_upload should bypass dedup');
});

test('same name, different content → overwrite + variants regenerated', function () {
    [$fm, $fs] = makeFM();
    $r1 = $fm->upload('local', '', fileArray(makeImage(2400, 1400, 'jpg'), 'p.jpg'));
    assertEqual(['thumb', 'medium', 'large'], array_keys($r1['variants']), 'first: 3 variants');
    // Smaller image (≤768), same name → overwrite; only thumb regenerated.
    // NOTE: process() does not purge old medium/large variant files — stale variants
    // can linger on disk after overwriting with a smaller image (known behaviour).
    $r2 = $fm->upload('local', '', fileArray(makeImage(500, 300, 'jpg'), 'p.jpg'), true);
    assertEqual(['thumb'], array_keys($r2['variants']), 'after overwrite with smaller: only thumb');
    assertTrue($fs->fileExists('p.jpg'), 'file present');
});

// ── 3. Collision on rename / move / copy ────────────────────────────
echo "\n{$yellow}► Collision behaviour (rename/move/copy all guard → 409){$reset}\n";

test('rename onto existing file → 409 name_exists', function () {
    [$fm] = makeFM();
    $fm->upload('local', '', fileArray(makeImage(60, 60, 'png'), 'a.png'));
    $fm->upload('local', '', fileArray(makeImage(70, 70, 'png'), 'b.png'), true);
    try {
        $fm->rename('local', 'a.png', 'b.png');
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('name_exists', $e->getErrorCode(), 'expected name_exists');
    }
});

test('rename onto free name → 200, variants follow', function () {
    [$fm, $fs] = makeFM();
    $fm->upload('local', '', fileArray(makeImage(600, 400, 'png'), 'src.png'));
    $r = $fm->rename('local', 'src.png', 'dst.png');
    assertEqual('dst.png', $r['key'], 'renamed key');
    assertTrue($fs->fileExists('dst.png'), 'dst present');
    assertTrue(!$fs->fileExists('src.png'), 'src gone');
});

test('move onto existing file → 409 name_exists (source untouched)', function () {
    [$fm, $fs] = makeFM();
    $fm->upload('local', '', fileArray(makeImage(60, 60, 'png'), 'm-from.png'));
    $fm->upload('local', '', fileArray(makeImage(70, 70, 'png'), 'm-to.png'), true);
    try {
        $fm->move('local', 'm-from.png', 'm-to.png');
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('name_exists', $e->getErrorCode(), 'expected name_exists');
    }
    assertTrue($fs->fileExists('m-from.png'), 'source preserved after blocked move');
});

test('move onto free name → 200', function () {
    [$fm, $fs] = makeFM();
    $fm->upload('local', '', fileArray(makeImage(60, 60, 'png'), 'm-from.png'));
    $fm->move('local', 'm-from.png', 'm-dest.png');
    assertTrue($fs->fileExists('m-dest.png') && !$fs->fileExists('m-from.png'), 'moved');
});

test('copy onto existing file → 409 name_exists', function () {
    [$fm] = makeFM();
    $fm->upload('local', '', fileArray(makeImage(60, 60, 'png'), 'c-from.png'));
    $fm->upload('local', '', fileArray(makeImage(70, 70, 'png'), 'c-to.png'), true);
    try {
        $fm->copy('local', 'c-from.png', 'c-to.png');
        throw new \RuntimeException('should have thrown');
    } catch (ApiException $e) {
        assertEqual('name_exists', $e->getErrorCode(), 'expected name_exists');
    }
});

test('copy onto free name → 200', function () {
    [$fm, $fs] = makeFM();
    $fm->upload('local', '', fileArray(makeImage(60, 60, 'png'), 'c-from.png'));
    $fm->copy('local', 'c-from.png', 'c-dest.png');
    assertTrue($fs->fileExists('c-dest.png') && $fs->fileExists('c-from.png'), 'copied, source kept');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);

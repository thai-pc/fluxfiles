<?php

/**
 * On-upload auto-optimize (Phase 2 M2.5). The seam in FileManager::upload — gated
 * by the `auto_optimize` claim AND a wired upload-optimizer hook (which index.php
 * sets only when the paid module is installed + licensed). Here we inject a FAKE
 * hook to drive the pipeline without the proprietary module.
 *
 * Usage: php tests/integration/test-auto-optimize.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\OptimizeStats;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** A FileManager over a temp disk; $autoOptimize toggles the claim, $hook wires the optimizer. */
function makeFM(bool $autoOptimize, ?callable $hook): array {
    $root = sys_get_temp_dir() . '/ff-autoopt-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0);
    $claims->autoOptimize = $autoOptimize;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    if ($hook !== null) { $fm->setUploadOptimizer($hook); }
    return [$fm, $dm, $root];
}
/** A real JPEG that WebP compresses smaller. */
function jpegBytes(int $w = 600, int $h = 400): string {
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < 800; $i++) {
        imagefilledellipse($im, random_int(0, $w), random_int(0, $h), random_int(5, 50), random_int(5, 50),
            imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    ob_start(); imagejpeg($im, null, 92); $b = ob_get_clean(); imagedestroy($im);
    return $b;
}
/** Write bytes to a temp upload + return the $file array. */
function uploadFile(string $name, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'ffup');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'type' => 'image/jpeg'];
}
/** The real WebP optimizer hook (what the module's optimizeBytes does). */
function webpHook(): callable {
    return function (string $bytes, int $quality): ?array {
        $r = (new \FluxFiles\ImageOptimizer())->transform($bytes, 0, $quality);
        return ($r === null || strlen($r['data']) >= strlen($bytes)) ? null : ['data' => $r['data'], 'ext' => 'webp'];
    };
}

echo "\n{$cyan}══ On-upload auto-optimize (M2.5) ══{$reset}\n\n";

test('Claims parse auto_optimize + optimize_quality', function () {
    $c = Claims::fromJwtPayload((object) ['auto_optimize' => true, 'optimize_quality' => 70]);
    assertEqual(true, $c->autoOptimize);
    assertEqual(70, $c->optimizeQuality);
    $d = Claims::fromJwtPayload((object) []);
    assertEqual(false, $d->autoOptimize);
    assertEqual(0, $d->optimizeQuality);
});

test('claim on + hook wired → upload stored as smaller WebP, savings recorded', function () {
    [$fm, $dm, $root] = makeFM(true, webpHook());
    $jpg = jpegBytes();
    $r = $fm->upload('local', '', uploadFile('photo.jpg', $jpg));
    assertEqual('photo.webp', $r['name'], 'renamed to .webp');
    assertEqual(true, $r['optimized'] ?? false, 'flagged optimized');
    assertTrue(($r['saved_bytes'] ?? 0) > 0, 'savings reported');
    assertTrue($r['size'] < strlen($jpg), 'smaller stored size');
    assertTrue(is_file("$root/photo.webp"), 'webp on disk');
    assertTrue(!is_file("$root/photo.jpg"), 'no original jpg stored');
    // savings counter updated
    assertTrue(OptimizeStats::read($dm->disk('local'), '')['total_saved_bytes'] > 0, 'counter recorded');
});

test('claim OFF → no optimization even with a hook', function () {
    [$fm, , $root] = makeFM(false, webpHook());
    $jpg = jpegBytes();
    $r = $fm->upload('local', '', uploadFile('photo.jpg', $jpg));
    assertEqual('photo.jpg', $r['name'], 'kept original name');
    assertTrue(!isset($r['optimized']), 'not optimized');
    assertTrue(is_file("$root/photo.jpg"), 'original stored as-is');
});

test('claim on but NO hook (free core) → upload untouched', function () {
    [$fm, , $root] = makeFM(true, null);
    $r = $fm->upload('local', '', uploadFile('photo.jpg', jpegBytes()));
    assertEqual('photo.jpg', $r['name'], 'inert without the hook');
    assertTrue(is_file("$root/photo.jpg"));
});

test('non-image upload is never optimized', function () {
    [$fm, , $root] = makeFM(true, webpHook());
    $r = $fm->upload('local', '', ['name' => 'notes.txt', 'tmp_name' => (function () { $t = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($t, 'hello'); return $t; })(), 'size' => 5, 'type' => 'text/plain']);
    assertEqual('notes.txt', $r['name']);
    assertTrue(is_file("$root/notes.txt"));
});

test('hook returning null (no gain) → original kept', function () {
    [$fm, , $root] = makeFM(true, fn (string $b, int $q) => null);
    $r = $fm->upload('local', '', uploadFile('photo.jpg', jpegBytes()));
    assertEqual('photo.jpg', $r['name'], 'no-gain → unchanged');
    assertTrue(is_file("$root/photo.jpg"));
});

test('allowed_ext is re-checked against the new .webp name', function () {
    // token only allows jpg → optimizing to .webp would violate the policy → keep original
    $root = sys_get_temp_dir() . '/ff-autoopt-' . uniqid(); @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, ['jpg', 'jpeg'], 0);
    $claims->autoOptimize = true;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    $fm->setUploadOptimizer(webpHook());
    try {
        $fm->upload('local', '', uploadFile('photo.jpg', jpegBytes()));
        throw new \RuntimeException('should have rejected the .webp');
    } catch (\FluxFiles\ApiException $e) {
        assertEqual('ext_not_allowed', $e->getErrorCode(), 're-validated new ext');
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

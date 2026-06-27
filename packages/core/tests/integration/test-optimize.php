<?php

/**
 * OptimizeModule engine test (FREE/core). Tests the actual recompression: run()
 * turns an image into a smaller WebP, reports savings, replaces/keeps the source,
 * batch + PDF (Ghostscript, availability-gated). The endpoint's `allow_optimize`
 * opt-in gate lives in index.php (exercised by the e2e api test).
 *
 * Usage: php packages/core/tests/integration/test-optimize.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\ImageOptimizer;
use FluxFiles\ApiException;
use FluxFiles\OptimizeModule;

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

/** A FileManager over a temp local disk; returns [module, fm, disks, opt, root, claims]. */
function setup(): array {
    $root = sys_get_temp_dir() . '/ff-opt-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $claims->allowOptimize = true;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [new OptimizeModule(), $fm, $dm, new ImageOptimizer(), $root, $claims];
}

/** A noisy true-color JPEG that WebP compresses smaller than the JPEG. */
function makeJpeg(string $path, int $w = 800, int $h = 600): void {
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < $w * $h / 40; $i++) {
        imagefilledellipse($im, random_int(0, $w), random_int(0, $h), random_int(5, 60), random_int(5, 60),
            imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    imagejpeg($im, $path, 92);
    imagedestroy($im);
}

/** A minimal, valid one-page PDF (enough to exercise the gs path / 501 gating). */
function makePdf(string $path): void {
    $pdf = "%PDF-1.4\n"
        . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF";
    file_put_contents($path, $pdf);
}

/**
 * A one-page PDF embedding a high-res JPEG at a tiny MediaBox (~1200 dpi), so
 * Ghostscript's downsampling yields a large, deterministic size win — proves the
 * PDF path actually compresses (hermetic: no ImageMagick needed).
 */
function makeImagePdf(string $path, int $w = 2400, int $h = 1800): void {
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < $w * $h / 30; $i++) {
        imagefilledellipse($im, random_int(0, $w), random_int(0, $h), random_int(10, 120), random_int(10, 120),
            imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    ob_start(); imagejpeg($im, null, 90); $jpg = (string) ob_get_clean(); imagedestroy($im);

    $content = 'q 144 0 0 108 0 0 cm /Im0 Do Q';
    $objs = [
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 144 108]/Resources<</XObject<</Im0 4 0 R>>>>/Contents 5 0 R>>',
        4 => "<</Type/XObject/Subtype/Image/Width {$w}/Height {$h}/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode/Length " . strlen($jpg) . ">>\nstream\n" . $jpg . "\nendstream",
        5 => '<</Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $off = [];
    foreach ($objs as $n => $body) { $off[$n] = strlen($pdf); $pdf .= "{$n} 0 obj\n{$body}\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= count($objs); $n++) { $pdf .= sprintf("%010d 00000 n \n", $off[$n]); }
    $pdf .= 'trailer<</Size ' . (count($objs) + 1) . "/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    file_put_contents($path, $pdf);
}

echo "\n{$cyan}══ OptimizeModule — engine (free/core) ══{$reset}\n\n";

test('optimize a JPEG → smaller WebP, source replaced, savings reported', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeJpeg("$root/photo.jpg");
    $orig = filesize("$root/photo.jpg");

    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'photo.jpg', 'quality' => 80]);

    assertEqual(false, $r['skipped'], 'not skipped');
    assertEqual('webp', $r['format']);
    assertEqual('photo.webp', $r['path'], 'wrote .webp');
    assertEqual($orig, $r['original_bytes']);
    assertTrue($r['optimized_bytes'] < $r['original_bytes'], 'smaller');
    assertTrue($r['saved_pct'] > 0, 'positive savings');
    assertTrue($r['replaced'], 'source replaced');
    assertTrue(is_file("$root/photo.webp"), 'webp on disk');
    assertTrue(!is_file("$root/photo.jpg"), 'original removed');
    // The bytes on disk are a valid WebP.
    $b = file_get_contents("$root/photo.webp");
    assertTrue(strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP', 'valid WebP');
});

test('keep_original → original stays, webp added', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeJpeg("$root/keep.jpg");
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'keep.jpg', 'keep_original' => true]);
    assertEqual(false, $r['replaced']);
    assertTrue(is_file("$root/keep.jpg"), 'original kept');
    assertTrue(is_file("$root/keep.webp"), 'webp added');
});

test('explicit dest path', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    @mkdir("$root/out", 0777, true);
    makeJpeg("$root/p.jpg");
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'p.jpg', 'dest' => 'out/p.webp']);
    assertEqual('out/p.webp', $r['path']);
    assertTrue(is_file("$root/out/p.webp"));
});

test('quality is clamped to 40–95', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeJpeg("$root/q.jpg");
    // Absurd quality shouldn't error; just clamps.
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'q.jpg', 'quality' => 9999]);
    assertEqual(false, $r['skipped']);
});

test('non-image → 415 not_image; missing → 404; missing path → 400', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    file_put_contents("$root/notes.txt", 'hi');
    expectApi(fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'notes.txt']), 'not_image');
    expectApi(fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'nope.jpg']), 'not_found');
    expectApi(fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => '']), 'bad_request');
});

test('guards: disk not granted → 403; no write perm → 403', function () {
    [$mod, $fm, $dm, $opt, $root] = setup();
    makeJpeg("$root/g.jpg");
    $readOnly = new Claims('u', ['read'], ['local'], '', 50, null, 0);
    $readOnly->allowOptimize = true;
    expectApi(fn () => $mod->run($fm, $dm, $opt, $readOnly, ['disk' => 'local', 'path' => 'g.jpg']), 'perm_denied');
    $noDisk = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0);
    expectApi(fn () => $mod->run($fm, $dm, $opt, $noDisk, ['disk' => 's3', 'path' => 'g.jpg']), 'disk_denied');
});

test('write-only token (no delete perm) → optimizes but keeps the original', function () {
    [$mod, $fm, $dm, $opt, $root] = setup();
    makeJpeg("$root/wo.jpg");
    $writeOnly = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0);
    $writeOnly->allowOptimize = true;
    $r = $mod->run($fm, $dm, $opt, $writeOnly, ['disk' => 'local', 'path' => 'wo.jpg']);
    assertEqual(false, $r['replaced'], 'no delete perm → not replaced');
    assertTrue(is_file("$root/wo.jpg"), 'original kept (no surprise data loss)');
    assertTrue(is_file("$root/wo.webp"), 'optimized webp still written');
});

test('batch: paths[] → per-item results + totals; one bad file doesn\'t abort', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeJpeg("$root/a.jpg");
    makeJpeg("$root/b.jpg");
    file_put_contents("$root/notes.txt", 'x'); // not an image → per-item error

    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'paths' => ['a.jpg', 'b.jpg', 'notes.txt'], 'keep_original' => true]);

    assertEqual(3, $r['count'], 'three items');
    assertEqual(2, $r['files_optimized'], 'two optimized');
    assertTrue($r['total_saved_bytes'] > 0, 'positive total savings');
    // the .txt item carries an error, not a crash
    $byPath = [];
    foreach ($r['items'] as $it) { $byPath[$it['original_path']] = $it; }
    assertEqual('not_image', $byPath['notes.txt']['error'] ?? null, 'bad file → per-item error');
    assertEqual(false, $byPath['a.jpg']['skipped'], 'a optimized');
    assertTrue(is_file("$root/a.webp") && is_file("$root/b.webp"), 'both webps written');
});

test('batch records cumulative savings into OptimizeStats', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeJpeg("$root/x.jpg"); makeJpeg("$root/y.jpg");
    $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'paths' => ['x.jpg', 'y.jpg'], 'keep_original' => true]);
    $stats = \FluxFiles\OptimizeStats::read($dm->disk('local'), '');
    assertEqual(2, $stats['files_optimized'], 'counter +2');
    assertTrue($stats['total_saved_bytes'] > 0, 'bytes recorded');
    // a second run accumulates
    makeJpeg("$root/z.jpg");
    $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'z.jpg', 'keep_original' => true]);
    assertEqual(3, \FluxFiles\OptimizeStats::read($dm->disk('local'), '')['files_optimized'], 'accumulates to 3');
});

test('batch cap → 413 too_many', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    $many = array_map(fn ($i) => "f$i.jpg", range(1, 201));
    expectApi(fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'paths' => $many]), 'too_many');
});

test('claim defaults: keep_original + max_mb via Claims (no body override)', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    // keep_original from the claim (body doesn't set it).
    $claims->optimizeKeepOriginal = true;
    makeJpeg("$root/k.jpg");
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'k.jpg']);
    assertEqual(false, $r['replaced'], 'claim keep_original → not replaced');
    assertTrue(is_file("$root/k.jpg"), 'original kept via claim');

    // max_mb cap from the claim → oversized file is skipped.
    $claims->optimizeMaxMb = 1; // 1 MB cap
    $big = str_repeat('x', 2 * 1024 * 1024);
    makeJpeg("$root/small.jpg");
    file_put_contents("$root/big.jpg", $big); // not a real image, but size-gated first
    $r2 = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'big.jpg']);
    assertTrue(!empty($r2['skipped']), 'oversized skipped');
    assertEqual('too_large', $r2['reason'] ?? null, 'reason too_large');
});

test('body overrides the claim default (keep_original=false beats claim true)', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    $claims->optimizeKeepOriginal = true;
    makeJpeg("$root/o.jpg");
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'o.jpg', 'keep_original' => false]);
    assertEqual(true, $r['replaced'], 'explicit body keep_original=false → replaced');
});

test('optimizeBytes: image bytes → smaller WebP (the on-upload hook)', function () {
    [$mod] = setup();
    makeJpeg('/tmp/_ff_optbytes.jpg', 700, 500);
    $bytes = file_get_contents('/tmp/_ff_optbytes.jpg');
    $r = $mod->optimizeBytes($bytes, 80);
    assertTrue(is_array($r), 'returns array');
    assertEqual('webp', $r['ext']);
    assertTrue(strlen($r['data']) < strlen($bytes), 'smaller');
    assertTrue(strncmp($r['data'], 'RIFF', 4) === 0 && substr($r['data'], 8, 4) === 'WEBP', 'valid WebP');
    @unlink('/tmp/_ff_optbytes.jpg');
});

test('optimizeBytes: non-image / no-gain → null', function () {
    [$mod] = setup();
    assertEqual(null, $mod->optimizeBytes('not an image at all', 80), 'garbage → null');
});

// ── WebP-only at rest (AVIF delivery is free in core /img, not an at-rest format) ──
test('format=avif is ignored → always recompresses to WebP', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeJpeg("$root/av.jpg", 700, 500);
    // Even an explicit format:'avif' in the body is a no-op now — output is WebP.
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'av.jpg', 'quality' => 70, 'format' => 'avif']);
    assertEqual(false, $r['skipped'], 'not skipped');
    assertTrue($r['optimized_bytes'] < $r['original_bytes'], 'smaller');
    assertEqual('webp', $r['format'], 'always webp at rest');
    assertEqual('av.webp', $r['path'], 'wrote .webp');
    assertTrue(is_file("$root/av.webp"), 'webp on disk');
});

// ── PDF (Ghostscript) ──
test('PDF: 501 when Ghostscript absent; compresses or no-gain when present', function () {
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makePdf("$root/doc.pdf");
    if (!\FluxFiles\PdfOptimizer::available()) {
        expectApi(fn () => $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'doc.pdf']), 'pdf_unavailable');
        return;
    }
    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'doc.pdf', 'keep_original' => true]);
    // A trivial PDF may not shrink → either a clean skip(no_gain) or a pdf result.
    assertTrue(($r['skipped'] ?? false) === true || ($r['format'] ?? '') === 'pdf', 'pdf handled, no crash');
});

test('PDF: image-heavy PDF actually shrinks in place (when gs available)', function () {
    if (!\FluxFiles\PdfOptimizer::available()) {
        return; // CI without Ghostscript: the gating test above covers the 501 path.
    }
    [$mod, $fm, $dm, $opt, $root, $claims] = setup();
    makeImagePdf("$root/img.pdf");
    $orig = filesize("$root/img.pdf");

    $r = $mod->run($fm, $dm, $opt, $claims, ['disk' => 'local', 'path' => 'img.pdf', 'pdf_level' => 'screen']);

    assertEqual(false, $r['skipped'], 'not skipped');
    assertEqual('pdf', $r['format'], 'stays pdf');
    assertEqual('img.pdf', $r['path'], 'compressed in place');
    assertTrue($r['optimized_bytes'] < $orig, 'smaller pdf');
    assertTrue($r['saved_pct'] > 0, 'positive savings');
    assertTrue($r['replaced'], 'overwritten in place');
    $b = (string) file_get_contents("$root/img.pdf");
    assertTrue(strncmp($b, '%PDF-', 5) === 0, 'valid PDF on disk');
});

test('PdfOptimizer: isPdf magic + unavailable-safe optimize()', function () {
    assertTrue(\FluxFiles\PdfOptimizer::isPdf("%PDF-1.7\n..."), 'detects %PDF-');
    assertEqual(false, \FluxFiles\PdfOptimizer::isPdf('not a pdf'), 'rejects non-pdf');
    // optimize() never throws; returns null on non-pdf input regardless of gs.
    assertEqual(null, (new \FluxFiles\PdfOptimizer())->optimize('not a pdf'), 'non-pdf → null');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

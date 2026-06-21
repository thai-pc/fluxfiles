<?php

/**
 * Responsive srcset (Phase 1 #4). Two parts:
 *  1. Claims::sanitizeSrcsetWidths — the width-ladder normalizer.
 *  2. FileManager::list() emitting img_srcset / img_sizes on image entries.
 * Pure metadata on top of the shipped /api/fm/img endpoint — no image is read.
 *
 * Usage: php tests/integration/test-srcset.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
$SECRET = str_repeat('s', 40);

function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** Parse the `w` descriptors out of a srcset string → list of ints. */
function srcsetWidths(string $srcset): array {
    $out = [];
    foreach (explode(',', $srcset) as $part) {
        if (preg_match('/\b(\d+)w\s*$/', trim($part), $m)) { $out[] = (int) $m[1]; }
    }
    return $out;
}

echo "\n{$cyan}══ Responsive srcset — Claims::sanitizeSrcsetWidths ══{$reset}\n\n";

test('default ladder snaps to 100px (clamped to 2000)', function () {
    assertEqual([300, 600, 800, 1000, 1400, 1900], Claims::sanitizeSrcsetWidths(null, 0));
});

test('clamps every width to webp_max_width, then dedupes', function () {
    // 320→300, 640→600, 1024→1000→clamp 800, 2000→clamp 800 ⇒ {300,600,800}
    assertEqual([300, 600, 800], Claims::sanitizeSrcsetWidths([320, 640, 1024, 2000], 800));
});

test('ignores non-positive / non-numeric, accepts digit strings, dedupes + sorts', function () {
    // 640→600 (×2), 320/'320'→300, 'x'/-5/0 dropped, 100→100
    assertEqual([100, 300, 600], Claims::sanitizeSrcsetWidths([640, 640, '320', 'x', -5, 0, 100], 0));
});

test('empty / all-invalid ladder falls back to the default', function () {
    $def = Claims::sanitizeSrcsetWidths(null, 0);
    assertEqual($def, Claims::sanitizeSrcsetWidths([], 0));
    assertEqual($def, Claims::sanitizeSrcsetWidths(['x', -1, 0], 0));
    assertEqual($def, Claims::sanitizeSrcsetWidths('not-an-array', 0));
});

test('caps the ladder at 12 entries', function () {
    $w = Claims::sanitizeSrcsetWidths(range(100, 2000, 100), 0); // 20 distinct → cap 12
    assertEqual(12, count($w));
    assertEqual([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 1100, 1200], $w);
});

test('Claims::fromJwtPayload defaults + srcset_sizes parsing', function () {
    $def = Claims::fromJwtPayload((object) []);
    assertEqual([300, 600, 800, 1000, 1400, 1900], $def->srcsetWidths, 'snapped default');
    assertEqual('', $def->srcsetSizes, 'no sizes by default');
    $c = Claims::fromJwtPayload((object) ['srcset_sizes' => '  (max-width: 600px) 100vw, 50vw ']);
    assertEqual('(max-width: 600px) 100vw, 50vw', $c->srcsetSizes, 'trimmed');
    $c2 = Claims::fromJwtPayload((object) ['srcset_widths' => [400, 1200], 'webp_max_width' => 1000]);
    assertEqual([400, 1000], $c2->srcsetWidths, '1200 clamped to 1000');
});

echo "\n{$cyan}══ Responsive srcset — FileManager::list() ══{$reset}\n\n";

/**
 * FileManager over a disk holding pics/a.jpg (+ pics/notes.txt). $opts:
 *   webp(bool) secret(bool) maxWidth(int) sizes(string) ladder(?array) width(?int natural)
 */
function makeSrcsetFM(array $opts = []): array {
    global $SECRET;
    $root = sys_get_temp_dir() . '/ff-srcset-' . uniqid();
    @mkdir($root . '/pics', 0777, true);
    $im = imagecreatetruecolor(40, 30);
    imagejpeg($im, $root . '/pics/a.jpg', 80);
    imagedestroy($im);
    file_put_contents($root . '/pics/notes.txt', 'x');

    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0);
    $claims->webpEnabled = $opts['webp'] ?? true;
    $claims->webpMaxWidth = $opts['maxWidth'] ?? 1600;
    $claims->srcsetSizes = $opts['sizes'] ?? '';
    // Mirror production: fromJwtPayload always sanitizes the ladder.
    $claims->srcsetWidths = Claims::sanitizeSrcsetWidths($opts['ladder'] ?? null, $claims->webpMaxWidth);

    $meta = new StorageMetadataHandler($dm);
    if (isset($opts['width'])) {
        $meta->save('local', 'pics/a.jpg', ['width' => (int) $opts['width'], 'height' => 100]);
    }
    $fm = new FileManager($dm, $claims, $meta);
    if ($opts['secret'] ?? true) { $fm->setStreamSecret($SECRET); }
    return [$fm];
}
function srcEntry(FileManager $fm, string $name = 'a.jpg'): array {
    $res = $fm->list('local', 'pics');
    foreach (($res['items'] ?? $res) as $it) {
        if (($it['name'] ?? '') === $name) { return $it; }
    }
    return [];
}

test('no stored width → full snapped ladder up to maxWidth; URLs build on img_base', function () {
    [$fm] = makeSrcsetFM(['maxWidth' => 1000]);
    $a = srcEntry($fm);
    assertTrue(isset($a['img_base'], $a['img_srcset']), 'has img_base + img_srcset');
    // ladder(clamped 1000)=[300,600,800,1000]; ceiling=1000 → [300,600,800,1000]
    assertEqual([300, 600, 800, 1000], srcsetWidths($a['img_srcset']));
    assertEqual($a['img_base'] . '&width=300 300w', explode(', ', $a['img_srcset'])[0], 'first candidate references img_base');
});

test('stored width caps the ladder (no upscale candidates) + offers the source width', function () {
    [$fm] = makeSrcsetFM(['width' => 850, 'maxWidth' => 1600]);
    $a = srcEntry($fm);
    // ladder(max1600)=[300,600,800,1000,1400,1600]; natural 850 → ceiling floor=800; ≤800 + 800 ⇒ [300,600,800]
    assertEqual([300, 600, 800], srcsetWidths($a['img_srcset']));
});

test('source width is offered even when it is off the ladder', function () {
    [$fm] = makeSrcsetFM(['width' => 1250, 'maxWidth' => 2000]);
    $a = srcEntry($fm);
    // ladder(max2000)=[300,600,800,1000,1400,1900]; ceiling floor(1250)=1200; ≤1200 + 1200 ⇒ [300,600,800,1000,1200]
    assertEqual([300, 600, 800, 1000, 1200], srcsetWidths($a['img_srcset']));
});

test('img_sizes appears only when the srcset_sizes claim is set', function () {
    assertEqual('100vw', srcEntry(makeSrcsetFM(['sizes' => '100vw'])[0])['img_sizes'] ?? null);
    assertTrue(!isset(srcEntry(makeSrcsetFM([])[0])['img_sizes']), 'no img_sizes by default');
});

test('img_srcset rides the img_base gate (off when webp off / no secret / non-image)', function () {
    assertTrue(!isset(srcEntry(makeSrcsetFM(['webp' => false])[0])['img_srcset']), 'webp off → none');
    assertTrue(!isset(srcEntry(makeSrcsetFM(['secret' => false])[0])['img_srcset']), 'no secret → none');
    assertTrue(!isset(srcEntry(makeSrcsetFM([])[0], 'notes.txt')['img_srcset']), 'non-image → none');
});

test('tiny image (<100px wide) gets img_base but no img_srcset', function () {
    [$fm] = makeSrcsetFM(['width' => 64]);
    $a = srcEntry($fm);
    assertTrue(isset($a['img_base']), 'still has img_base');
    assertTrue(!isset($a['img_srcset']), 'too small → no srcset');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

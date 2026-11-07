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

test('in-place burn keeps the original (backup) + flags watermarked; remove restores it', function () {
    [$fm, $dm, $root] = setup();
    $orig = (string) file_get_contents("$root/photo.png");

    // Burn in place → file changes, original snapshot kept under _fluxfiles/originals/.
    $r = $fm->applyWatermark('local', 'photo.png', ['type' => 'text', 'text' => 'A', 'opacity' => 0.8]);
    assertEqual(true, $r['watermarked'], 'flagged watermarked');
    $burned = (string) file_get_contents("$root/photo.png");
    assertTrue($burned !== $orig, 'file changed by burn');
    assertTrue(is_file("$root/_fluxfiles/originals/photo.png"), 'original backed up');
    assertEqual($orig, (string) file_get_contents("$root/_fluxfiles/originals/photo.png"), 'backup == true original');

    // Re-burn at a new position → reads FROM the backup, so the backup stays the
    // pristine original (no stacking onto an already-watermarked file).
    $fm->applyWatermark('local', 'photo.png', ['type' => 'text', 'text' => 'B', 'x' => 0.1, 'y' => 0.1]);
    assertEqual($orig, (string) file_get_contents("$root/_fluxfiles/originals/photo.png"), 'backup untouched on re-edit');

    // Remove → restores the original byte-for-byte, drops the backup.
    $rr = $fm->removeWatermark('local', 'photo.png');
    assertEqual(false, $rr['watermarked'], 'flag cleared');
    assertEqual($orig, (string) file_get_contents("$root/photo.png"), 'restored to original');
    assertTrue(!is_file("$root/_fluxfiles/originals/photo.png"), 'backup removed');
});

test('remove watermark with no backup → 404', function () {
    [$fm] = setup();
    expectApi(fn () => $fm->removeWatermark('local', 'photo.png'), 'no_watermark_backup');
});

// ── Stale originals backup (owner_only resurrection) ──
function wmUsers(): array {
    $root = sys_get_temp_dir() . '/ff-wm-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $mk = fn (string $u) => new FileManager($dm, Claims::fromJwtPayload((object) [
        'sub' => $u, 'perms' => ['read', 'write', 'delete'], 'disks' => ['local'], 'owner_only' => true,
    ]), new StorageMetadataHandler($dm));
    return [$mk('u1'), $mk('u2'), $dm, $root];
}
function wmUpload(FileManager $fm, string $name): void {
    $png = pngBytes(60, 60);
    $tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, $png);
    $fm->upload('local', '', ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($png), 'error' => 0, 'type' => 'image/png'], true);
}

test('removeWatermark on a DELETED file → 404 not_found, nothing resurrected (owner_only PoC)', function () {
    [$u1, $u2, $dm, $root] = wmUsers();
    wmUpload($u1, 'p.png');
    $u1->applyWatermark('local', 'p.png', ['text' => 'X']);
    $u1->delete('local', 'p.png');
    assertTrue(!is_file("$root/_fluxfiles/originals/p.png"), 'delete drops the backup');
    expectApi(fn () => $u2->removeWatermark('local', 'p.png'), 'not_found');
    assertTrue(!$dm->disk('local')->fileExists('p.png'), 'p.png must stay gone');
});
test('stale backup (planted) never restores onto a different file or user', function () {
    [$u1, $u2, $dm, $root] = wmUsers();
    wmUpload($u1, 'p.png');
    $u1->applyWatermark('local', 'p.png', ['text' => 'X']);
    // Another file now occupies the key (bytes replaced outside this file's history).
    file_put_contents("$root/p.png", pngBytes(60, 60, [200, 10, 10]));
    file_put_contents("$root/_fluxfiles/originals/p.png", 'STALE');
    expectApi(fn () => $u1->removeWatermark('local', 'p.png'), 'no_watermark_backup');
    // a first burn overwrites the stale backup and burns from the real file
    $u1->applyWatermark('local', 'p.png', ['text' => 'X']);
    assertTrue(file_get_contents("$root/_fluxfiles/originals/p.png") !== 'STALE', 'stale backup overwritten');
    expectApi(fn () => $u2->removeWatermark('local', 'p.png'), 'owner_only');
});
test('legacy sidecar-less backup of a deleted file is never applied to another user\'s file', function () {
    foreach (['remove', 'apply'] as $op) {
        [, $u2, $dm, $root] = wmUsers();
        wmUpload($u2, 'p.png');
        $mine = (string) file_get_contents("$root/p.png");
        @mkdir("$root/_fluxfiles/originals", 0777, true);
        file_put_contents("$root/_fluxfiles/originals/p.png", pngBytes(60, 60, [255, 0, 0])); // user A's original
        if ($op === 'remove') {
            expectApi(fn () => $u2->removeWatermark('local', 'p.png'), 'no_watermark_backup');
            assertEqual($mine, (string) file_get_contents("$root/p.png"), 'B file untouched');
        } else {
            $u2->applyWatermark('local', 'p.png', ['text' => 'X']);
            // burned from B's own bytes: backup is now B's original, not A's red image
            assertEqual($mine, (string) file_get_contents("$root/_fluxfiles/originals/p.png"), 'fresh backup of B');
        }
        if ($op === 'remove') { assertTrue(!is_file("$root/_fluxfiles/originals/p.png"), 'legacy backup dropped'); }
    }
});
test('rename moves the backup with the file; trash drops it', function () {
    [$u1, , $dm, $root] = wmUsers();
    wmUpload($u1, 'p.png');
    $u1->applyWatermark('local', 'p.png', ['text' => 'X']);
    $u1->rename('local', 'p.png', 'q.png');
    assertTrue(is_file("$root/_fluxfiles/originals/q.png") && !is_file("$root/_fluxfiles/originals/p.png"), 'backup followed rename');
    $u1->removeWatermark('local', 'q.png'); // still works after rename
    $u1->applyWatermark('local', 'q.png', ['text' => 'X']);
    $u1->trash('local', 'q.png');
    assertTrue(!is_file("$root/_fluxfiles/originals/q.png"), 'trash drops the backup');
});
test('folder rename moves backups, folder delete drops them', function () {
    [$u1, , $dm, $root] = wmUsers();
    $u1->mkdir('local', 'd');
    $png = pngBytes(60, 60); $tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, $png);
    $u1->upload('local', 'd', ['name' => 'p.png', 'tmp_name' => $tmp, 'size' => strlen($png), 'error' => 0, 'type' => 'image/png'], true);
    $u1->applyWatermark('local', 'd/p.png', ['text' => 'X']);
    $u1->rename('local', 'd', 'e');
    assertTrue(is_file("$root/_fluxfiles/originals/e/p.png") && !is_dir("$root/_fluxfiles/originals/d"), 'dir backups followed rename');
    $u1->delete('local', 'e');
    assertTrue(!is_dir("$root/_fluxfiles/originals/e"), 'folder delete drops backups');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

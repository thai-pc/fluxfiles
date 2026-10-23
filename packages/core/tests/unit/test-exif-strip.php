<?php

/**
 * ExifStripper + the `strip_exif` upload claim.
 *
 * Builds real JPEG/PNG/WebP files with GD, injects metadata the way cameras and
 * editors do (EXIF with a GPS IFD + Orientation, XMP, IPTC, comments, PNG eXIf /
 * text chunks, WebP EXIF/XMP chunks behind a VP8X header), then checks that:
 * the metadata is gone, Orientation survives, the pixel data is byte-identical
 * (lossless), and the result still decodes.
 *
 * Usage: php tests/unit/test-exif-strip.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\ExifStripper;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

/** Big-endian EXIF APP1 payload: IFD0 {Orientation, GPS pointer} + GPS IFD {LatitudeRef "N"}. */
function exifWithGps(int $orientation): string {
    $ifd0 = 8;
    $gps = $ifd0 + 2 + 2 * 12 + 4;
    $tiff = "MM\x00\x2A" . pack('N', $ifd0)
        . pack('n', 2)
        . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\0\0"
        . pack('nnN', 0x8825, 4, 1) . pack('N', $gps)
        . pack('N', 0)
        . pack('n', 1)
        . pack('nnN', 0x0001, 2, 2) . "N\0\0\0"
        . pack('N', 0);
    return "Exif\0\0" . $tiff;
}
function seg(int $marker, string $payload): string {
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}
function gdImage() {
    $im = imagecreatetruecolor(16, 8);
    imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocate($im, 200, 30, 30));
    return $im;
}
function jpegWithMetadata(int $orientation): string {
    ob_start(); imagejpeg(gdImage(), null, 90); $jpg = (string) ob_get_clean();
    // Insert after SOI (+ JFIF APP0 if GD wrote one): EXIF, XMP, IPTC, COM.
    $pos = 2;
    if (substr($jpg, 2, 2) === "\xFF\xE0") {
        $pos += 2 + unpack('n', substr($jpg, 4, 2))[1];
    }
    $meta = seg(0xE1, exifWithGps($orientation))
        . seg(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><exif:GPSLatitude>10,1N</exif:GPSLatitude></x:xmpmeta>")
        . seg(0xED, "Photoshop 3.0\0" . str_repeat('I', 20))
        . seg(0xFE, 'shot at home');
    return substr($jpg, 0, $pos) . $meta . substr($jpg, $pos);
}
function scanData(string $jpg): string {
    return substr($jpg, strpos($jpg, "\xFF\xDA"));
}
function pngChunk(string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

echo "\n{$cyan}══ ExifStripper / strip_exif ══{$reset}\n\n";

test('JPEG: GPS EXIF, XMP, IPTC and comments are removed; pixels byte-identical', function () {
    $in = jpegWithMetadata(1);
    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'] && $r['format'] === 'jpeg', 'stripped');
    $out = $r['data'];
    foreach (['Exif', 'xmpmeta', 'Photoshop', 'shot at home', 'GPSLatitude'] as $needle) {
        assertTrue(strpos($out, $needle) === false, "{$needle} gone");
    }
    assertTrue(scanData($out) === scanData($in), 'scan data untouched (lossless)');
    assertTrue(imagecreatefromstring($out) !== false, 'still decodes');
});

test('JPEG: a rotated photo keeps ONLY its Orientation tag', function () {
    $r = ExifStripper::strip(jpegWithMetadata(6));
    $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
    file_put_contents($tmp, $r['data']);
    $exif = function_exists('exif_read_data') ? @exif_read_data($tmp) : null;
    @unlink($tmp);
    assertTrue(strpos($r['data'], 'Exif') !== false, 'a minimal EXIF block was written back');
    if (is_array($exif)) {
        assertTrue(($exif['Orientation'] ?? null) === 6, 'orientation preserved');
        assertTrue(!isset($exif['GPSLatitudeRef']) && !isset($exif['GPS_IFD_Pointer']), 'no GPS');
    }
});

test('JPEG without metadata is returned unchanged (idempotent)', function () {
    // GD writes a "CREATOR: gd-jpeg" COM segment, so strip once to get a clean file.
    $clean = ExifStripper::strip(jpegWithMetadata(1))['data'];
    $r = ExifStripper::strip($clean);
    assertTrue($r !== null && !$r['removed'] && $r['data'] === $clean, 'untouched');
});

test('JPEG: MPF secondary images (with their own GPS EXIF) after EOI are cut', function () {
    // Phone HDR/portrait JPEGs: an APP2 "MPF" index + secondary JPEGs appended after
    // the main image, each carrying its own EXIF (GPS included).
    $main = jpegWithMetadata(1);
    $main = substr($main, 0, 2) . seg(0xE2, "MPF\0" . str_repeat("\0", 40)) . substr($main, 2);
    $secondary = jpegWithMetadata(1);
    $in = $main . $secondary;
    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'], 'stripped');
    $out = $r['data'];
    foreach (['Exif', 'GPSLatitude', "MPF\0"] as $needle) {
        assertTrue(strpos($out, $needle) === false, "{$needle} gone (secondary image included)");
    }
    assertTrue(substr_count($out, "\xFF\xD8") === 1, 'only the main image is left');
    assertTrue(substr($out, -2) === "\xFF\xD9", 'ends at the main EOI');
    assertTrue(imagecreatefromstring($out) !== false, 'still decodes');
});

test('JPEG: progressive scans survive intact; a vendor trailer is cut', function () {
    $im = gdImage();
    imageinterlace($im, true);
    ob_start(); imagejpeg($im, null, 90); $jpg = (string) ob_get_clean();
    assertTrue(substr_count($jpg, "\xFF\xDA") > 1, 'fixture: several scans');
    $clean = ExifStripper::strip($jpg)['data']; // drops GD's COM segment
    $body = substr($clean, strpos($clean, "\xFF\xDA"));
    $r = ExifStripper::strip($clean . 'SEFHsamsung-trailer-with-location');
    assertTrue($r['removed'], 'trailer counts as removed');
    assertTrue(substr($r['data'], strpos($r['data'], "\xFF\xDA")) === $body, 'every scan byte-identical');
    assertTrue(strpos($r['data'], 'SEFH') === false, 'trailer gone');
    assertTrue(imagecreatefromstring($r['data']) !== false, 'still decodes');
});

test('JPEG: an ICC profile in APP2 is still kept', function () {
    $icc = seg(0xE2, "ICC_PROFILE\0\x01\x01" . str_repeat('P', 32));
    $in = jpegWithMetadata(1);
    $in = substr($in, 0, 2) . $icc . substr($in, 2);
    $out = ExifStripper::strip($in)['data'];
    assertTrue(strpos($out, 'ICC_PROFILE') !== false, 'colour profile kept');
});

test('PNG: eXIf and text chunks are removed, iCCP-style chunks kept', function () {
    ob_start(); imagepng(gdImage()); $png = (string) ob_get_clean();
    $at = 8 + 12 + 13; // after the IHDR chunk
    $in = substr($png, 0, $at)
        . pngChunk('eXIf', substr(exifWithGps(1), 6))
        . pngChunk('tEXt', "Comment\0shot at home")
        . pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0<x:xmpmeta/>")
        . pngChunk('sRGB', "\0")
        . substr($png, $at);
    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'], 'stripped');
    foreach (['eXIf', 'tEXt', 'iTXt', 'shot at home'] as $n) {
        assertTrue(strpos($r['data'], $n) === false, "{$n} gone");
    }
    assertTrue(strpos($r['data'], 'sRGB') !== false, 'colour chunk kept');
    assertTrue(imagecreatefromstring($r['data']) !== false, 'still decodes');
});

test('WebP: EXIF/XMP chunks removed, VP8X flags cleared, RIFF size fixed', function () {
    if (!function_exists('imagewebp')) {
        return;
    }
    ob_start(); imagewebp(gdImage(), null, 80); $webp = (string) ob_get_clean();
    $body = substr($webp, 12);                               // the VP8/VP8L chunk(s)
    $vp8x = 'VP8X' . pack('V', 10) . chr(0x0C) . "\0\0\0" . "\x0F\0\0" . "\x07\0\0"; // EXIF|XMP, 16x8
    $exif = substr(exifWithGps(1), 6);
    $exifChunk = 'EXIF' . pack('V', strlen($exif)) . $exif . (strlen($exif) % 2 ? "\0" : '');
    $xmp = '<x:xmpmeta/>';
    $xmpChunk = 'XMP ' . pack('V', strlen($xmp)) . $xmp;
    $chunks = $vp8x . $body . $exifChunk . $xmpChunk;
    $in = 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;

    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'], 'stripped');
    $out = $r['data'];
    assertTrue(strpos($out, 'EXIF') === false && strpos($out, 'XMP ') === false, 'chunks gone');
    assertTrue((ord($out[20]) & 0x0C) === 0, 'VP8X EXIF/XMP flags cleared');
    assertTrue(unpack('V', substr($out, 4, 4))[1] === strlen($out) - 8, 'RIFF size matches');
    assertTrue(imagecreatefromstring($out) !== false, 'still decodes');
});

test('JPEG: vendor APPn, C2PA/JUMBF, FlashPix and JFXX thumbnails go; Adobe APP14 stays', function () {
    ob_start(); imagejpeg(gdImage(), null, 90); $jpg = (string) ob_get_clean();
    $extra = seg(0xE0, "JFXX\0\x10" . str_repeat('T', 16))
        . seg(0xE2, "FPXR\0" . str_repeat('F', 12))
        . seg(0xE3, 'Meta' . str_repeat('m', 8))       // vendor (Kodak/Samsung)
        . seg(0xEB, "JP\0\0jumbc2pa" . str_repeat('c', 8)) // APP11 JUMBF / C2PA
        . seg(0xEE, "Adobe\0d\0\0\0\0\x01");          // colour transform
    $at = substr($jpg, 2, 2) === "\xFF\xE0" ? 4 + unpack('n', substr($jpg, 4, 2))[1] : 2;
    $in = substr($jpg, 0, $at) . $extra . substr($jpg, $at);
    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'], 'stripped');
    foreach (['JFXX', 'FPXR', 'Meta', 'jumbc2pa'] as $n) {
        assertTrue(strpos($r['data'], $n) === false, "{$n} gone");
    }
    assertTrue(strpos($r['data'], "Adobe\0") !== false, 'APP14 kept');
    assertTrue(scanData($r['data']) === scanData($in), 'pixels identical');
    assertTrue(imagecreatefromstring($r['data']) !== false, 'still decodes');
});

test('PNG: bytes appended after IEND and a C2PA caBX chunk are cut', function () {
    ob_start(); imagepng(gdImage()); $png = (string) ob_get_clean();
    $at = 8 + 12 + 13;
    $in = substr($png, 0, $at) . pngChunk('caBX', 'jumbc2pa') . substr($png, $at) . 'PK' . str_repeat("\0", 30);
    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'], 'stripped');
    assertTrue($r['data'] === $png, 'exactly the original image');
    $clean = ExifStripper::strip($png);
    assertTrue($clean !== null && !$clean['removed'], 'a clean PNG is untouched');
});

test('WebP: unknown chunks and bytes past the RIFF size are cut', function () {
    if (!function_exists('imagewebp')) {
        return;
    }
    ob_start(); imagewebp(gdImage(), null, 80); $webp = (string) ob_get_clean();
    $c2pa = 'C2PA' . pack('V', 8) . 'jumbc2pa';
    $chunks = substr($webp, 12) . $c2pa;
    $in = 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks . 'TRAILER-GPS';
    $r = ExifStripper::strip($in);
    assertTrue($r !== null && $r['removed'], 'stripped');
    assertTrue(strpos($r['data'], 'jumbc2pa') === false && strpos($r['data'], 'TRAILER') === false, 'gone');
    assertTrue(unpack('V', substr($r['data'], 4, 4))[1] === strlen($r['data']) - 8, 'RIFF size matches');
    assertTrue(imagecreatefromstring($r['data']) !== false, 'still decodes');
    $clean = ExifStripper::strip($webp);
    assertTrue($clean !== null && !$clean['removed'], 'a clean WebP is untouched');
});

test('unsupported / corrupt input is left alone (null)', function () {
    assertTrue(ExifStripper::strip('GIF89a....') === null, 'gif');
    assertTrue(ExifStripper::strip("\xFF\xD8\xFF\xE1\xFF\xFF") === null, 'truncated jpeg');
    assertTrue(ExifStripper::strip('') === null, 'empty');
});

// ── Upload integration ──────────────────────────────────────────────────────
function exifEnv(bool $strip): array {
    $root = sys_get_temp_dir() . '/fluxfiles-exif-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0, false);
    $claims->stripExif = $strip;
    return [new FileManager($dm, $claims, new StorageMetadataHandler($dm)), $root];
}
function exifUpload(FileManager $fm, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'exu');
    file_put_contents($tmp, $bytes);
    try {
        return $fm->upload('local', '', ['name' => 'photo.jpg', 'size' => strlen($bytes), 'tmp_name' => $tmp]);
    } finally {
        @unlink($tmp);
    }
}

test('upload with strip_exif: stored file has no GPS; result says so; size is the new size', function () {
    [$fm, $root] = exifEnv(true);
    $res = exifUpload($fm, jpegWithMetadata(1));
    $stored = (string) file_get_contents($root . '/photo.jpg');
    assertTrue(($res['exif_stripped'] ?? false) === true, 'exif_stripped flag');
    assertTrue(strpos($stored, 'GPSLatitude') === false && strpos($stored, 'Exif') === false, 'clean on disk');
    assertTrue((int) $res['size'] === strlen($stored), 'reported size matches stored bytes');
});

test('upload without strip_exif keeps the original bytes', function () {
    [$fm, $root] = exifEnv(false);
    $in = jpegWithMetadata(1);
    $res = exifUpload($fm, $in);
    assertTrue(!isset($res['exif_stripped']), 'no flag');
    assertTrue(file_get_contents($root . '/photo.jpg') === $in, 'untouched');
});

test('extract with strip_exif: images inside a zip are stripped too', function () {
    [$fm, $root] = exifEnv(true);
    $z = new \ZipArchive();
    $z->open($root . '/photos.zip', \ZipArchive::CREATE);
    $z->addFromString('trip/IMG_1.jpg', jpegWithMetadata(1));
    $z->addFromString('trip/notes.txt', 'Exif is just a word here');
    $z->close();
    $fm->extractZip('local', 'photos.zip');
    $img = (string) file_get_contents($root . '/photos/trip/IMG_1.jpg');
    assertTrue($img !== '' && strpos($img, 'GPSLatitude') === false && strpos($img, 'Exif') === false, 'image stripped');
    assertTrue(file_get_contents($root . '/photos/trip/notes.txt') === 'Exif is just a word here', 'non-image untouched');
});

test('claim decodes from a JWT payload', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'strip_exif' => true]);
    assertTrue($c->stripExif === true, 'on');
    assertTrue(Claims::fromJwtPayload((object) ['sub' => 'u'])->stripExif === false, 'default off');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);

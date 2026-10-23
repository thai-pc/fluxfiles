<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Lossless removal of embedded metadata (EXIF incl. GPS, XMP, IPTC, text chunks,
 * vendor APPn blocks, C2PA/JUMBF manifests, bytes appended after the image) from
 * JPEG, PNG and WebP — the `strip_exif` claim. A C2PA manifest goes too: stripping
 * breaks its hash binding anyway, and it can carry its own EXIF assertion (GPS).
 *
 * Works on the container bytes, never re-encodes pixels: no quality loss, no
 * GD/Imagick dependency, and the colour profile (JPEG APP2 / PNG iCCP) is kept.
 * The one EXIF field a viewer genuinely needs — Orientation — is preserved by
 * writing back a minimal EXIF block that carries only that tag, so a phone photo
 * doesn't suddenly display sideways once its EXIF is gone.
 *
 * Format is detected from magic bytes, not the extension. Anything else (HEIC,
 * AVIF, TIFF, GIF, a corrupt file) returns null and is stored untouched.
 */
final class ExifStripper
{
    /** EXIF Orientation tag id. */
    private const TAG_ORIENTATION = 0x0112;

    /**
     * @return array{data:string,removed:bool,format:string}|null null = unsupported
     *         format or not parseable (caller keeps the original bytes).
     */
    public static function strip(string $bytes): ?array
    {
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return self::wrap(self::stripJpeg($bytes), 'jpeg');
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return self::wrap(self::stripPng($bytes), 'png');
        }
        if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
            return self::wrap(self::stripWebp($bytes), 'webp');
        }
        return null;
    }

    /** @param array{0:string,1:bool}|null $r */
    private static function wrap(?array $r, string $format): ?array
    {
        return $r === null ? null : ['data' => $r[0], 'removed' => $r[1], 'format' => $format];
    }

    // ── JPEG ────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:bool}|null */
    private static function stripJpeg(string $b): ?array
    {
        $len = strlen($b);
        $pos = 2;
        $head = '';          // segments kept before the scan
        $app0 = '';          // JFIF APP0 kept first, so the minimal EXIF can follow it
        $orientation = null;
        $removed = false;

        while ($pos + 4 <= $len) {
            if ($b[$pos] !== "\xFF") {
                return null; // not at a marker → don't guess
            }
            $marker = ord($b[$pos + 1]);
            if ($marker === 0xFF) { // fill byte
                $pos++;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) { // SOS / EOI: rest is image data
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { // standalone
                $head .= substr($b, $pos, 2);
                $pos += 2;
                continue;
            }
            $segLen = unpack('n', substr($b, $pos + 2, 2))[1];
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                return null;
            }
            $seg = substr($b, $pos, 2 + $segLen);
            $payload = substr($seg, 4);
            if ($marker === 0xE1) { // APP1: EXIF or XMP
                if (strncmp($payload, "Exif\0\0", 6) === 0 && $orientation === null) {
                    $orientation = self::tiffOrientation(substr($payload, 6));
                }
                $removed = true;
            } elseif ($marker === 0xFE || ($marker >= 0xE3 && $marker <= 0xEF && $marker !== 0xEE)) {
                // COM, and every APPn but APP14 (Adobe colour transform, needed to
                // decode CMYK): APP13 IPTC/Photoshop, APP11 JUMBF/C2PA, vendor blocks
                // (Ricoh/Samsung/Qualcomm/…) that carry their own copies of the data.
                $removed = true;
            } elseif ($marker === 0xE2 && strncmp($payload, "ICC_PROFILE\0", 12) !== 0) {
                // APP2 that isn't the ICC profile: the Multi-Picture index (it points at
                // the secondary images appended after the main one — HDR gain maps,
                // depth/portrait maps — dropped with them below) or FlashPix data.
                $removed = true;
            } elseif ($marker === 0xE0 && strncmp($payload, "JFXX\0", 5) === 0) {
                // JFIF extension thumbnail: a second, older copy of the picture (it
                // can still show what an edit cropped out).
                $removed = true;
            } elseif ($marker === 0xE0 && $app0 === '' && $head === '') {
                $app0 = $seg;
            } else {
                $head .= $seg;
            }
            $pos += 2 + $segLen;
        }
        if ($pos >= $len) {
            return null; // never reached the scan data
        }
        // Anything after the main image's EOI is not this image: phones append MPF
        // secondary JPEGs (each with its OWN EXIF, GPS included) and vendor trailers
        // there. Keeping "everything from SOS on" carried all of it through untouched.
        $end = self::jpegImageEnd($b, $pos);
        if ($end < $len) {
            $removed = true;
        }
        if (!$removed) {
            return [$b, false];
        }
        $exif = ($orientation !== null && $orientation !== 1) ? self::minimalExifApp1($orientation) : '';
        return ["\xFF\xD8" . $app0 . $exif . $head . substr($b, $pos, $end - $pos), true];
    }

    /**
     * Offset just past the main image's EOI, walking from its first SOS: between
     * scans it steps over whole segments (a progressive JPEG has DHT/SOS/… there),
     * inside entropy-coded data it skips byte stuffing (FF 00), RSTn and fill bytes,
     * so the first real marker after a scan is found — never an FF D9 that happens
     * to sit inside a table. Malformed input → strlen (nothing is cut).
     */
    private static function jpegImageEnd(string $b, int $pos): int
    {
        $len = strlen($b);
        while ($pos + 1 < $len && $b[$pos] === "\xFF") {
            $marker = ord($b[$pos + 1]);
            if ($marker === 0xD9) {
                return $pos + 2;
            }
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $pos += 2;
                continue;
            }
            if ($pos + 4 > $len) {
                return $len;
            }
            $segLen = unpack('n', substr($b, $pos + 2, 2))[1];
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                return $len;
            }
            $pos += 2 + $segLen;
            if ($marker !== 0xDA) {
                continue;
            }
            // Entropy-coded data until the next real marker.
            while (true) {
                $ff = strpos($b, "\xFF", $pos);
                if ($ff === false || $ff + 1 >= $len) {
                    return $len;
                }
                $next = ord($b[$ff + 1]);
                if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                    $pos = $ff + 2;
                    continue;
                }
                $pos = $ff;
                break;
            }
        }
        return $len;
    }

    /** Orientation (1–8) from a TIFF block (the bytes after "Exif\0\0"), or null. */
    private static function tiffOrientation(string $t): ?int
    {
        if (strlen($t) < 8) {
            return null;
        }
        $order = substr($t, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $u16 = static fn (string $s): int => unpack($order === 'II' ? 'v' : 'n', $s)[1];
        $u32 = static fn (string $s): int => unpack($order === 'II' ? 'V' : 'N', $s)[1];
        $ifd = $u32(substr($t, 4, 4));
        if ($ifd + 2 > strlen($t)) {
            return null;
        }
        $count = $u16(substr($t, $ifd, 2));
        for ($i = 0; $i < $count; $i++) {
            $e = $ifd + 2 + $i * 12;
            if ($e + 12 > strlen($t)) {
                return null;
            }
            if ($u16(substr($t, $e, 2)) === self::TAG_ORIENTATION) {
                $v = $u16(substr($t, $e + 8, 2));
                return ($v >= 1 && $v <= 8) ? $v : null;
            }
        }
        return null;
    }

    /** An APP1 segment whose EXIF holds nothing but the Orientation tag. */
    private static function minimalExifApp1(int $orientation): string
    {
        $tiff = "MM\x00\x2A" . pack('N', 8)          // big-endian header, IFD0 at 8
            . pack('n', 1)                              // one entry
            . pack('nnN', self::TAG_ORIENTATION, 3, 1)  // tag, SHORT, count 1
            . pack('n', $orientation) . "\x00\x00"      // value, padded to 4 bytes
            . pack('N', 0);                             // no next IFD
        $payload = "Exif\0\0" . $tiff;
        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    // ── PNG ─────────────────────────────────────────────────────────────────

    /** Ancillary chunks that carry metadata (eXIf = EXIF incl. GPS; text chunks; caBX = C2PA). */
    private const PNG_DROP = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'caBX'];

    /** @return array{0:string,1:bool}|null */
    private static function stripPng(string $b): ?array
    {
        $len = strlen($b);
        $pos = 8;
        $out = substr($b, 0, 8);
        $removed = false;
        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            $total = 12 + $chunkLen;
            if ($pos + $total > $len) {
                return null;
            }
            if (in_array($type, self::PNG_DROP, true)) {
                $removed = true;
            } else {
                $out .= substr($b, $pos, $total);
            }
            $pos += $total;
            if ($type === 'IEND') {
                if ($pos < $len) {
                    $removed = true; // bytes after IEND aren't part of the image
                }
                break;
            }
        }
        return $removed ? [$out, true] : [$b, false];
    }

    // ── WebP ────────────────────────────────────────────────────────────────

    /** Chunks that make up the image; anything else (EXIF, XMP, C2PA, unknown) goes. */
    private const WEBP_KEEP = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    /** @return array{0:string,1:bool}|null */
    private static function stripWebp(string $b): ?array
    {
        $len = strlen($b);
        // Only what the RIFF header declares is the image; bytes appended past it go.
        $riffEnd = 8 + unpack('V', substr($b, 4, 4))[1];
        if ($riffEnd > $len) {
            return null;
        }
        $len = $riffEnd;
        $pos = 12;
        $chunks = '';
        $removed = false;
        $vp8xAt = null;
        while ($pos + 8 <= $len) {
            $fourcc = substr($b, $pos, 4);
            $size = unpack('V', substr($b, $pos + 4, 4))[1];
            $total = 8 + $size + ($size & 1); // chunks are padded to even length
            if ($pos + 8 + $size > $len) {
                return null;
            }
            if (!in_array($fourcc, self::WEBP_KEEP, true)) {
                $removed = true;
            } else {
                if ($fourcc === 'VP8X') {
                    $vp8xAt = strlen($chunks);
                }
                $chunks .= substr($b, $pos, min($total, $len - $pos));
            }
            $pos += $total;
        }
        if ($len < strlen($b)) {
            $removed = true;
        }
        if (!$removed) {
            return [$b, false];
        }
        if ($vp8xAt !== null) {
            // VP8X feature flags: clear the EXIF (0x08) and XMP (0x04) bits.
            $flagsAt = $vp8xAt + 8;
            $chunks[$flagsAt] = chr(ord($chunks[$flagsAt]) & ~0x0C);
        }
        return ['RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks, true];
    }
}

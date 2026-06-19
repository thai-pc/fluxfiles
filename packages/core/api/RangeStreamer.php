<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * HTTP Range serving for local files — the byte plumbing behind /api/fm/stream.
 *
 * Browsers seek a <video>/<audio> by sending `Range: bytes=START-END`; we must
 * answer with 206 Partial Content and `fseek()` to the offset rather than reading
 * the whole file from the start (which would make every seek O(filesize)). The
 * range parsing is a pure function so it can be unit-tested without HTTP.
 */
final class RangeStreamer
{
    /** Bytes per read/echo chunk while streaming. */
    private const CHUNK = 8192;

    /**
     * Parse a `Range` header against a known file size.
     *
     * @return array{0:int,1:int}|null  [start, end] inclusive, or null when the
     *         header is unsatisfiable (caller must answer 416).
     *         Returns [0, size-1] when $header is null/empty (whole file).
     */
    public static function parseRange(?string $header, int $size): ?array
    {
        if ($size <= 0) {
            return null;
        }
        if ($header === null || trim($header) === '') {
            return [0, $size - 1];
        }
        // Only the single-range "bytes=start-end" form is supported (no multipart).
        if (!preg_match('/^\s*bytes=(\d*)-(\d*)\s*$/', $header, $m)) {
            return null;
        }
        $startRaw = $m[1];
        $endRaw   = $m[2];

        if ($startRaw === '' && $endRaw === '') {
            return null; // "bytes=-" is meaningless
        }

        if ($startRaw === '') {
            // Suffix range: last N bytes ("bytes=-500").
            $n = (int) $endRaw;
            if ($n <= 0) {
                return null;
            }
            $start = max(0, $size - $n);
            return [$start, $size - 1];
        }

        $start = (int) $startRaw;
        $end   = $endRaw === '' ? $size - 1 : (int) $endRaw;
        $end   = min($end, $size - 1);

        if ($start > $end || $start >= $size) {
            return null; // out of bounds → 416
        }
        return [$start, $end];
    }

    /**
     * Stream a file to the client honouring an optional Range header. Emits the
     * status line, Content-Type / Range / Length / Accept-Ranges headers, then the
     * bytes. Caller is responsible for auth and for the security headers it wants.
     *
     * @param callable|null $headerFn  Header sink (defaults to header()); injectable for tests.
     */
    public static function stream(string $absPath, string $mime, ?string $rangeHeader, ?callable $headerFn = null): void
    {
        $emit = $headerFn ?? static function (string $h, int $code = 0): void {
            if ($code > 0) {
                http_response_code($code);
            }
            header($h);
        };

        $size = filesize($absPath);
        if ($size === false) {
            $emit('Content-Type: text/plain', 500);
            echo 'stat failed';
            return;
        }

        $emit('Content-Type: ' . $mime);
        $emit('Accept-Ranges: bytes');

        $range = self::parseRange($rangeHeader, $size);

        if ($range === null) {
            // Unsatisfiable range → 416 with the valid extent.
            $emit('Content-Range: bytes */' . $size, 416);
            return;
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;
        $isPartial = ($rangeHeader !== null && trim((string) $rangeHeader) !== '');

        if ($isPartial) {
            $emit('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            $emit('Content-Length: ' . $length, 206);
        } else {
            $emit('Content-Length: ' . $length, 200);
        }

        $fp = fopen($absPath, 'rb');
        if ($fp === false) {
            return;
        }
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $read = fread($fp, (int) min(self::CHUNK, $remaining));
            if ($read === false) {
                break;
            }
            echo $read;
            $remaining -= strlen($read);
            if (function_exists('flush')) {
                @flush();
            }
        }
        fclose($fp);
    }
}

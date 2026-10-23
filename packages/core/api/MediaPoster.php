<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Poster images for non-image files, so the grid can show what a PDF or video
 * looks like instead of a generic icon: page 1 of a PDF (Ghostscript) or a frame
 * ~1s into a video (ffmpeg). The caller turns the returned raster into the usual
 * `_variants/<name>_thumb.webp`, so move/copy/delete/trash already handle it.
 *
 * Both tools are OPTIONAL — absent binary → null, never an error — and both run
 * on untrusted uploads, so:
 *   - proc_open with an argument ARRAY (no shell), fixed flags, our own temp paths;
 *   - a hard timeout with SIGKILL;
 *   - Ghostscript with -dSAFER (no file/device access beyond its in/out);
 *   - ffmpeg with the demuxer FORCED from the extension and protocols restricted
 *     to `file`: otherwise a "video.mp4" that is really an HLS/concat playlist
 *     makes ffmpeg fetch URLs (SSRF) or read arbitrary local files into the
 *     frame it renders — a known ffmpeg-thumbnailer attack.
 */
final class MediaPoster
{
    private const TIMEOUT_SEC = 20;

    /** Video extension → the only demuxer ffmpeg may use for it. */
    private const VIDEO_DEMUXERS = [
        'mp4' => 'mov', 'm4v' => 'mov', 'mov' => 'mov',
        'webm' => 'matroska', 'mkv' => 'matroska',
        'avi' => 'avi',
    ];

    /** @var array<string,string|null|false> memoized binary lookups */
    private static array $bins = [];

    /** 'pdf' | 'video' | null for a key, by extension. */
    public static function kindFor(string $key): ?string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'pdf';
        }
        return isset(self::VIDEO_DEMUXERS[$ext]) ? 'video' : null;
    }

    public static function available(string $kind): bool
    {
        return ($kind === 'pdf' ? PdfOptimizer::binary() : self::binary('ffmpeg')) !== null;
    }

    /**
     * Render a poster for $srcPath (a local temp copy of the upload).
     * Returns PNG/JPEG bytes, or null when not applicable / tool missing / failed.
     */
    public static function render(string $srcPath, string $key): ?string
    {
        $kind = self::kindFor($key);
        if ($kind === null) {
            return null;
        }
        $out = tempnam(sys_get_temp_dir(), 'ffposter_');
        if ($out === false) {
            return null;
        }
        try {
            if ($kind === 'pdf') {
                $gs = PdfOptimizer::binary(); // shared lookup, incl. its minimum-version gate
                if ($gs === null || strncmp((string) @file_get_contents($srcPath, false, null, 0, 5), '%PDF-', 5) !== 0) {
                    return null;
                }
                // A fixed Letter-sized canvas the page is scaled into: rendering at the
                // page's own size lets a crafted MediaBox (200in², or a huge /UserUnit)
                // ask for a gigapixel raster — minutes of CPU on one upload.
                $args = [$gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=png16m',
                    '-dFirstPage=1', '-dLastPage=1', '-r96', '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
                    '-dDEVICEWIDTHPOINTS=612', '-dDEVICEHEIGHTPOINTS=792', '-dFIXEDMEDIA', '-dPDFFitPage',
                    '-o', $out, $srcPath];
            } else {
                $ff = self::binary('ffmpeg');
                if ($ff === null) {
                    return null;
                }
                $demuxer = self::VIDEO_DEMUXERS[strtolower(pathinfo($key, PATHINFO_EXTENSION))];
                $args = [$ff, '-nostdin', '-hide_banner', '-loglevel', 'error',
                    '-protocol_whitelist', 'file',
                    '-ss', '1', '-f', $demuxer, '-i', $srcPath,
                    '-frames:v', '1', '-vf', 'scale=min(800\,iw):-2',
                    '-f', 'image2', '-c:v', 'mjpeg', '-y', $out];
            }
            if (!self::exec($args)) {
                return null;
            }
            $bytes = (string) @file_get_contents($out);
            return $bytes !== '' ? $bytes : null;
        } finally {
            @unlink($out);
        }
    }

    /** @param list<string> $args */
    private static function exec(array $args): bool
    {
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = @proc_open($args, $desc, $pipes, null, ProcessEnv::minimal());
        if (!is_resource($proc)) {
            return false;
        }
        $start = microtime(true);
        $exit = -1;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) - $start > self::TIMEOUT_SEC) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(50_000);
        }
        proc_close($proc);
        return $exit === 0;
    }

    private static function binary(string $name): ?string
    {
        if (array_key_exists($name, self::$bins)) {
            return self::$bins[$name];
        }
        foreach (["/usr/bin/{$name}", "/usr/local/bin/{$name}", "/opt/homebrew/bin/{$name}", "/bin/{$name}"] as $cand) {
            if (@is_executable($cand)) {
                return self::$bins[$name] = $cand;
            }
        }
        $found = @trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return self::$bins[$name] = ($found !== '' && @is_executable($found)) ? $found : null;
    }
}

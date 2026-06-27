<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * PDF compression via Ghostscript (downsamples embedded images). Availability-gated:
 * when the `gs` binary is absent the optimize endpoint reports the feature
 * unavailable (501) rather than failing per file.
 *
 * Security: the only shell-out in the optimize path. Hardened by —
 *   - proc_open with an ARGUMENT ARRAY (no shell), so no flag/metachar injection;
 *   - a fixed flag set + a whitelisted `-dPDFSETTINGS` level (never user-controlled);
 *   - `-dSAFER` (Ghostscript's own sandbox: no arbitrary file/pipe access);
 *   - temp-file I/O under the system temp dir, cleaned up in finally;
 *   - a hard wall-clock timeout (the process is killed if it overruns);
 *   - an output sanity check (valid %PDF, non-empty, actually smaller).
 */
final class PdfOptimizer
{
    /** Ghostscript -dPDFSETTINGS presets, smallest → largest output. */
    private const LEVELS = ['screen', 'ebook', 'printer', 'prepress', 'default'];
    private const LEVEL_DEFAULT = 'ebook';

    /** Hard ceiling on a single Ghostscript run. */
    private const TIMEOUT_SEC = 60;

    /** @var string|null|false memoized resolved binary path (false = not looked up). */
    private static $binary = false;

    /** Locate the `gs` binary, or null when Ghostscript isn't installed. */
    public static function binary(): ?string
    {
        if (self::$binary !== false) {
            return self::$binary;
        }
        // Common absolute locations first (no shell), then `command -v` as a fallback.
        foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs', '/bin/gs'] as $cand) {
            if (@is_executable($cand)) {
                return self::$binary = $cand;
            }
        }
        // `command -v gs` carries no user input, so this is safe.
        $found = @trim((string) @shell_exec('command -v gs 2>/dev/null'));
        self::$binary = ($found !== '' && @is_executable($found)) ? $found : null;
        return self::$binary;
    }

    public static function available(): bool
    {
        return self::binary() !== null;
    }

    /** True when the bytes look like a PDF (magic header). */
    public static function isPdf(string $bytes): bool
    {
        return strncmp($bytes, '%PDF-', 5) === 0;
    }

    /**
     * Compress a PDF. Returns the optimized bytes, or null when Ghostscript is
     * unavailable, the input isn't a PDF, the run fails/times out, or there's no
     * size gain. Never throws.
     */
    public function optimize(string $pdfBytes, string $level = self::LEVEL_DEFAULT): ?string
    {
        $gs = self::binary();
        if ($gs === null || !self::isPdf($pdfBytes)) {
            return null;
        }
        $level = in_array($level, self::LEVELS, true) ? $level : self::LEVEL_DEFAULT;

        $dir = sys_get_temp_dir();
        $in  = tempnam($dir, 'ffpdf_in_');
        $out = tempnam($dir, 'ffpdf_out_');
        if ($in === false || $out === false) {
            if ($in !== false) { @unlink($in); }
            if ($out !== false) { @unlink($out); }
            return null;
        }

        try {
            if (@file_put_contents($in, $pdfBytes) === false) {
                return null;
            }

            // Fixed, audited argument array — no shell, nothing user-controlled but
            // the (whitelisted) level and the temp paths we created.
            $args = [
                $gs,
                '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS=/' . $level,
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dDownsampleMonoImages=true',
                '-dDetectDuplicateImages=true',
                '-o', $out,
                $in,
            ];

            $desc = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];
            $proc = @proc_open($args, $desc, $pipes);
            if (!is_resource($proc)) {
                return null;
            }

            $start = microtime(true);
            $timedOut = false;
            $exit = -1;
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    // Capture the exit code HERE: polling proc_get_status reaps the
                    // child, so a later proc_close() would return -1, not the real code.
                    $exit = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) - $start > self::TIMEOUT_SEC) {
                    proc_terminate($proc, 9);
                    $timedOut = true;
                    break;
                }
                usleep(50_000);
            }
            proc_close($proc);

            if ($timedOut || $exit !== 0 || !is_file($out)) {
                return null;
            }
            $result = (string) @file_get_contents($out);

            // Sanity: a valid, non-empty PDF that's actually smaller than the input.
            if ($result === '' || !self::isPdf($result) || strlen($result) >= strlen($pdfBytes)) {
                return null;
            }
            return $result;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}

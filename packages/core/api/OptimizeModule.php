<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Image + PDF optimization — recompresses an image to a smaller WebP at the SAME
 * dimensions (EXIF stripped), reusing `ImageOptimizer::transform`, and compresses
 * PDFs via Ghostscript. **Free/core** feature (it used to be a paid module, but
 * the remaining value was thin once AVIF/WebP delivery became free in `/api/fm/img`,
 * so it was folded into the MIT core). Opt-in per token via the `allow_optimize`
 * claim — it replaces/deletes originals, so it's a deliberate capability, not on
 * by default.
 *
 * Image → WebP, in-place (writes `<name>.webp`; deletes the source unless
 * `keep_original`). Single + batch (`paths[]`) + on-upload auto-optimize.
 * PDF → Ghostscript downsample (availability-gated → 501 when `gs` absent),
 * compressed in place. See {@see PdfOptimizer}.
 *
 * NOTE: AVIF *conversion-at-rest* used to live here, but AVIF is delivered
 * on-the-fly for free by `/api/fm/img` (Accept negotiation), so this is WebP-only.
 */
final class OptimizeModule
{
    /** WebP quality is clamped to this range; default when the request omits it. */
    private const Q_MIN = 40;
    private const Q_MAX = 95;
    private const Q_DEFAULT = 82;

    /**
     * Optimize raw image bytes → WebP, for the on-upload hook (FileManager calls
     * this when `auto_optimize` is set). Returns null to leave the upload untouched
     * (unsupported / no gain). Pure transform — FileManager owns the file write,
     * .webp rename, and savings recording.
     *
     * @return array{data:string, ext:string}|null
     */
    public function optimizeBytes(string $bytes, int $quality = self::Q_DEFAULT): ?array
    {
        $quality = max(self::Q_MIN, min(self::Q_MAX, $quality));
        $result = (new ImageOptimizer())->transform($bytes, 0, $quality);
        if ($result === null || strlen($result['data']) >= strlen($bytes)) {
            return null; // SVG / animated / bomb / non-raster, or no size gain
        }
        return ['data' => $result['data'], 'ext' => 'webp'];
    }

    /**
     * @param array<string,mixed> $body  {disk, path, quality?, dest?, keep_original?}
     * @return array<string,mixed>
     */
    /** Cap on a single batch request. */
    private const MAX_BATCH = 200;

    public function run(FileManager $fm, DiskManager $disks, ImageOptimizer $opt, Claims $claims, array $body): array
    {
        $disk = (string) ($body['disk'] ?? 'local');

        if (!$claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
        if (!$claims->hasPerm('write')) {
            throw new ApiException('Permission denied: write', 403, 'perm_denied');
        }

        // Single (path) or batch (paths[]).
        $paths = [];
        if (isset($body['paths']) && is_array($body['paths'])) {
            $paths = array_values(array_filter(array_map('strval', $body['paths']), static fn ($p) => $p !== ''));
        } elseif (($body['path'] ?? '') !== '') {
            $paths = [(string) $body['path']];
        }
        if ($paths === []) {
            throw new ApiException('Missing path', 400, 'bad_request');
        }
        if (count($paths) > self::MAX_BATCH) {
            throw new ApiException('Too many files in one request', 413, 'too_many', ['max' => self::MAX_BATCH]);
        }

        $quality = (int) ($body['quality'] ?? self::Q_DEFAULT);
        $quality = max(self::Q_MIN, min(self::Q_MAX, $quality));
        // Request body wins; otherwise fall back to the per-tenant claim defaults so
        // an operator can scope behaviour without the UI having to send every option.
        $keepOriginal = array_key_exists('keep_original', $body)
            ? (bool) $body['keep_original'] : $claims->optimizeKeepOriginal;
        // Ghostscript preset for PDFs (whitelisted in PdfOptimizer; default ebook).
        $pdfLevel = (string) ($body['pdf_level'] ?? $claims->pdfLevel);

        // Batch: optimize each, collect results, accumulate savings, record once.
        if (count($paths) > 1) {
            $items = [];
            $totalSaved = 0;
            $optimizedCount = 0;
            foreach ($paths as $p) {
                try {
                    $r = $this->optimizeOne($fm, $disks, $opt, $claims, $disk, $p, $quality, $keepOriginal, isset($body['dest']) ? (string) $body['dest'] : null, $pdfLevel);
                } catch (ApiException $e) {
                    // One bad file shouldn't abort the batch — record the error per item.
                    $r = ['original_path' => $p, 'error' => $e->getErrorCode() ?: 'error', 'message' => $e->getMessage(), 'skipped' => true, 'saved_bytes' => 0];
                }
                $items[] = $r;
                $totalSaved += (int) ($r['saved_bytes'] ?? 0);
                if (empty($r['skipped'])) {
                    $optimizedCount++;
                }
            }
            if ($totalSaved > 0) {
                OptimizeStats::record($disks->disk($disk), $claims->pathPrefix, $totalSaved, $optimizedCount);
            }
            return ['items' => $items, 'total_saved_bytes' => $totalSaved, 'files_optimized' => $optimizedCount, 'count' => count($items)];
        }

        // Single.
        $r = $this->optimizeOne($fm, $disks, $opt, $claims, $disk, $paths[0], $quality, $keepOriginal, isset($body['dest']) ? (string) $body['dest'] : null, $pdfLevel);
        if (!empty($r['saved_bytes'])) {
            OptimizeStats::record($disks->disk($disk), $claims->pathPrefix, (int) $r['saved_bytes'], 1);
        }
        return $r;
    }

    /**
     * Optimize one file. Throws for hard errors (404/415/etc) so a single call
     * surfaces them; the batch loop catches them per item.
     *
     * @return array<string,mixed>
     */
    private function optimizeOne(FileManager $fm, DiskManager $disks, ImageOptimizer $opt, Claims $claims, string $disk, string $path, int $quality, bool $keepOriginal, ?string $dest, string $pdfLevel = 'ebook'): array
    {
        // Scope + guard the source path (prefix, system-path, owner).
        $scoped = $fm->validateUserPath($path);
        $fm->assertCanModifyScopedPath($disk, $scoped);

        $fs = $disks->disk($disk);
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('File not found', 404, 'not_found');
        }

        $src = (string) $fs->read($scoped);
        $originalBytes = strlen($src);
        $ext = strtolower(pathinfo($scoped, PATHINFO_EXTENSION));

        // Per-tenant size cap (optimize_max_mb, 0 = no limit): skip oversized files
        // rather than spend CPU/time on them.
        $maxBytes = max(0, $claims->optimizeMaxMb) * 1024 * 1024;
        if ($maxBytes > 0 && $originalBytes > $maxBytes) {
            return $this->skipped($path, $originalBytes, 'too_large');
        }

        // ── PDF branch (Ghostscript) — downsample embedded images, stays a PDF ──
        if ($ext === 'pdf' || PdfOptimizer::isPdf($src)) {
            if (!PdfOptimizer::available()) {
                throw new ApiException('PDF optimization requires Ghostscript on the server', 501, 'pdf_unavailable');
            }
            $optimized = (new PdfOptimizer())->optimize($src, $pdfLevel);
            if ($optimized === null) {
                return $this->skipped($path, $originalBytes, 'no_gain');
            }
            $optimizedBytes = strlen($optimized);

            // Same .pdf extension → overwrite in place (or write to an explicit dest).
            $destUser = ($dest !== null && trim($dest) !== '') ? $dest : $path;
            $destScoped = $fm->validateUserPath($destUser);
            $fm->assertCanModifyScopedPath($disk, $destScoped);
            $fm->writeScopedFile($disk, $destScoped, $optimized);

            $saved = $originalBytes - $optimizedBytes;
            return [
                'path'            => $destUser,
                'original_path'   => $path,
                'original_bytes'  => $originalBytes,
                'optimized_bytes' => $optimizedBytes,
                'saved_bytes'     => $saved,
                'saved_pct'       => $originalBytes > 0 ? round($saved / $originalBytes * 100, 1) : 0.0,
                'format'          => 'pdf',
                'replaced'        => $destScoped === $scoped,
                'skipped'         => false,
            ];
        }

        // ── Image branch ──
        if (!$opt->isImage($scoped)) {
            throw new ApiException('Not an optimizable image or PDF', 415, 'not_image');
        }

        // Recompress at the same dimensions → WebP (null = SVG / animated GIF /
        // decompression-bomb / non-raster: leave it untouched).
        $result = $opt->transform($src, 0, $quality);
        if ($result === null) {
            return $this->skipped($path, $originalBytes, 'unsupported');
        }
        $optimizedBytes = strlen($result['data']);

        // Never make a file bigger (already-compressed images can grow re-encoded).
        if ($optimizedBytes >= $originalBytes) {
            return $this->skipped($path, $originalBytes, 'no_gain');
        }

        // Destination: an explicit dest, else `<source-without-ext>.webp`.
        $outExt = 'webp';
        $destUser = ($dest !== null && trim($dest) !== '')
            ? $dest
            : preg_replace('/\.[^.\/]+$/', '', $path) . '.' . $outExt;
        $destScoped = $fm->validateUserPath($destUser);
        $fm->assertCanModifyScopedPath($disk, $destScoped);

        $fm->writeScopedFile($disk, $destScoped, $result['data']);

        // Replace the source unless asked to keep it — via FileManager::delete so
        // metadata sidecars + image variants are cleaned up properly. Removing the
        // original is a destructive op, so it needs the `delete` permission: a
        // write-only token still optimizes, it just keeps the original (no orphan,
        // no surprise data loss). Skip when the optimized file IS the source path.
        $replaced = false;
        if (!$keepOriginal && $destScoped !== $scoped && $claims->hasPerm('delete')) {
            $fm->delete($disk, $path);
            $replaced = true;
        }

        $saved = $originalBytes - $optimizedBytes;
        return [
            'path'            => $destUser,
            'original_path'   => $path,
            'original_bytes'  => $originalBytes,
            'optimized_bytes' => $optimizedBytes,
            'saved_bytes'     => $saved,
            'saved_pct'       => $originalBytes > 0 ? round($saved / $originalBytes * 100, 1) : 0.0,
            'format'          => $outExt,
            'width'           => $result['width'],
            'height'          => $result['height'],
            'replaced'        => $replaced,
            'skipped'         => false,
        ];
    }

    /** @return array<string,mixed> */
    private function skipped(string $path, int $bytes, string $reason): array
    {
        return [
            'path'            => $path,
            'original_path'   => $path,
            'original_bytes'  => $bytes,
            'optimized_bytes' => $bytes,
            'saved_bytes'     => 0,
            'saved_pct'       => 0.0,
            'skipped'         => true,
            'reason'          => $reason,
        ];
    }
}

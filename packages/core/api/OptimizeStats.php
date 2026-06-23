<?php

declare(strict_types=1);

namespace FluxFiles;

use League\Flysystem\Filesystem;

/**
 * Storage-resident "bytes saved" counter for the Optimization module. Lives in the
 * MIT core as the persistence primitive (no DB) — the proprietary optimize module
 * calls `record()` after each optimize, and `/api/fm/usage` surfaces `read()` so
 * the dashboard can show "X saved across N files".
 *
 * One cumulative JSON per tenant prefix at `<prefix>/_fluxfiles/optimize.json`.
 * Best-effort read-modify-write (like the usage cache): a lost increment under a
 * rare concurrent optimize is acceptable for a display counter — never throws.
 */
final class OptimizeStats
{
    private const FILE = '_fluxfiles/optimize.json';

    /** Full key for a tenant prefix ('' → root). */
    private static function key(string $prefix): string
    {
        $prefix = trim($prefix, '/');
        return ($prefix !== '' ? $prefix . '/' : '') . self::FILE;
    }

    /**
     * Current totals for a prefix. Always returns the full shape (zeros when none).
     *
     * @return array{total_saved_bytes:int, files_optimized:int, updated_at:?int}
     */
    public static function read(Filesystem $fs, string $prefix): array
    {
        $empty = ['total_saved_bytes' => 0, 'files_optimized' => 0, 'updated_at' => null];
        try {
            $key = self::key($prefix);
            if (!$fs->fileExists($key)) {
                return $empty;
            }
            $data = json_decode((string) $fs->read($key), true);
            if (!is_array($data)) {
                return $empty;
            }
            return [
                'total_saved_bytes' => max(0, (int) ($data['total_saved_bytes'] ?? 0)),
                'files_optimized'   => max(0, (int) ($data['files_optimized'] ?? 0)),
                'updated_at'        => isset($data['updated_at']) ? (int) $data['updated_at'] : null,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Add to the cumulative counter for a prefix. Negative/zero savings are ignored
     * (a "skipped"/no-gain optimize doesn't move the needle). Best-effort.
     */
    public static function record(Filesystem $fs, string $prefix, int $savedBytes, int $files = 1): void
    {
        if ($savedBytes <= 0 || $files <= 0) {
            return;
        }
        try {
            $cur = self::read($fs, $prefix);
            $fs->write(self::key($prefix), (string) json_encode([
                'total_saved_bytes' => $cur['total_saved_bytes'] + $savedBytes,
                'files_optimized'   => $cur['files_optimized'] + $files,
                'updated_at'        => time(),
            ]));
        } catch (\Throwable $e) {
            /* display counter — best effort */
        }
    }
}

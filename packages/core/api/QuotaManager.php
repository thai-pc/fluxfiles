<?php

declare(strict_types=1);

namespace FluxFiles;

use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use League\Flysystem\FileAttributes;

class QuotaManager
{
    /** @var DiskManager */
    private $diskManager;

    /**
     * True for disks where a full RECURSIVE listing is prohibitively slow —
     * namely SFTP, where each directory is a separate round-trip (≈ a handful of
     * entries/second on a real tree, so a server webroot effectively hangs the
     * UI). For these we skip the *unconditional* usage scans (the storage meter
     * + usage dashboard that fire on every navigate) and report usage as
     * "unsupported" instead. Explicit, opt-in quota ENFORCEMENT
     * (assertQuota/assertFileCount, only when a max_storage/max_files limit is
     * set) still walks — the admin chose that trade-off.
     */
    private function recursiveWalkTooSlow(string $disk): bool
    {
        return ($this->diskManager->config($disk)['driver'] ?? '') === 'sftp';
    }

    // ---------------------------------------------------------------------
    // Enforcement = a LIVE walk; the meter = an optional cached figure
    //
    //   * ONE walk yields both bytes and file count (walk()), so an upload that
    //     carries both limits scans the prefix once, not twice.
    //   * check()/assertQuota()/assertFileCount() ALWAYS walk. The decision never
    //     reads a cache, so nothing a cache could miss (a walk racing a write, a
    //     sibling prefix's write, an operator edit, bytes that arrive straight at
    //     the bucket) can make a limit fail open. GUARANTEE: at the moment of the
    //     check, used + incoming <= limit. There is no reservation, so two
    //     requests whose checks overlap in time can both pass near the limit —
    //     the same window as before the cache existed.
    //   * The `/quota` meter and the usage dashboard may be a little stale and
    //     are the only readers of `<prefix>/_fluxfiles/quota.json`: figures()
    //     serves it for $meterTtl seconds and refreshes it with one walk when
    //     stale; getUsageBreakdown() refreshes it from its own walk. No write
    //     path touches it, so it costs no per-write I/O.
    // ---------------------------------------------------------------------

    /** @var int full walks performed (observability / tests) */
    public int $walks = 0;
    private int $meterTtl;

    /** The meter always walks when $meterTtl is 0. */
    public function __construct(DiskManager $diskManager, int $meterTtl = 120)
    {
        $this->diskManager = $diskManager;
        $this->meterTtl = max(0, $meterTtl);
    }

    /**
     * One recursive pass: raw bytes of EVERY file (internal ones included — the
     * quota meter's figure) and the count of user-visible files.
     *
     * @return array{bytes:int,files:int}
     */
    public function walk(string $disk, string $prefix): array
    {
        $this->walks++;
        $fs = $this->diskManager->disk($disk);
        $bytes = 0;
        $files = 0;

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($prefix, true) as $item) {
            if (!($item instanceof FileAttributes)) {
                continue;
            }
            $bytes += $item->fileSize() ?? 0;
            // FileManager::isReservedKey() is the single, case-insensitive rule —
            // a case-sensitive substring test here let `_FLUXFILES/` files count
            // against the user's quota (and, worse, diverge from what every other
            // caller considers reserved).
            if (!FileManager::isReservedKey($item->path())) {
                $files++;
            }
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    /** Live byte usage. */
    public function getUsage(string $disk, string $prefix): int
    {
        return $this->walk($disk, $prefix)['bytes'];
    }

    // --- meter cache (display only) -------------------------------------

    private function cachePath(string $prefix): string
    {
        $pt = trim($prefix, '/');
        return ($pt !== '' ? $pt . '/' : '') . '_fluxfiles/quota.json';
    }

    /** Record a walk's figures for the meter. Best-effort: a missing cache only costs a walk. */
    private function storeWalk(string $disk, string $prefix, int $bytes, int $files): void
    {
        if ($this->meterTtl <= 0) {
            return;
        }
        try {
            $this->diskManager->disk($disk)->write(
                $this->cachePath($prefix),
                (string) json_encode(['bytes' => $bytes, 'files' => $files, 'computed_at' => time()]),
                StorageMetadataHandler::INTERNAL_WRITE
            );
        } catch (\Throwable $e) {
            // ignored
        }
    }

    /**
     * Figures for the METER: the cached walk while younger than the TTL, else one
     * walk (which refreshes the cache). Never use this to enforce a limit.
     *
     * @return array{bytes:int,files:int,cached:bool}
     */
    public function figures(string $disk, string $prefix): array
    {
        if ($this->meterTtl > 0) {
            try {
                $c = json_decode((string) $this->diskManager->disk($disk)->read($this->cachePath($prefix)), true);
            } catch (\Throwable $e) {
                $c = null;
            }
            if (is_array($c) && isset($c['bytes'], $c['files'], $c['computed_at'])) {
                $age = time() - (int) $c['computed_at'];
                if ($age >= 0 && $age <= $this->meterTtl) {
                    return ['bytes' => (int) $c['bytes'], 'files' => (int) $c['files'], 'cached' => true];
                }
            }
        }
        $w = $this->walk($disk, $prefix);
        $this->storeWalk($disk, $prefix, $w['bytes'], $w['files']);
        return $w + ['cached' => false];
    }

    // --- enforcement (always live) --------------------------------------

    /**
     * Bound a write of $addBytes / $addFiles by the limits with ONE live walk
     * (bytes are checked first, so the error code matches the old two-step order).
     * A limit <= 0 is off; with both off nothing is walked. A write that adds no
     * file ($addFiles <= 0) can't breach the file cap and skips that check.
     *
     * @throws ApiException quota_exceeded / too_many_files
     */
    public function check(string $disk, string $prefix, int $addBytes, int $addFiles, int $maxStorageMb, int $maxFiles): void
    {
        $maxBytes = $maxStorageMb > 0 ? $maxStorageMb * 1024 * 1024 : 0;
        if ($addFiles <= 0) {
            $maxFiles = 0;
        }
        if ($maxBytes <= 0 && $maxFiles <= 0) {
            return;
        }

        $w = $this->walk($disk, $prefix);

        if ($maxBytes > 0 && ($w['bytes'] + $addBytes) > $maxBytes) {
            $usedMb = round($w['bytes'] / (1024 * 1024), 2);
            throw new ApiException(
                "Storage quota exceeded: {$usedMb}MB used of {$maxStorageMb}MB limit",
                413,
                'quota_exceeded',
                ['used' => $usedMb . 'MB', 'max' => $maxStorageMb . 'MB']
            );
        }
        if ($maxFiles > 0 && ($w['files'] + $addFiles) > $maxFiles) {
            throw new ApiException(
                "File limit reached: {$w['files']} of {$maxFiles} files used",
                413,
                'too_many_files',
                ['used' => $w['files'], 'max' => $maxFiles]
            );
        }
    }

    /**
     * @throws ApiException if quota would be exceeded
     */
    public function assertQuota(string $disk, string $prefix, int $fileSizeBytes, int $maxStorageMb): void
    {
        $this->check($disk, $prefix, $fileSizeBytes, 0, $maxStorageMb, 0);
    }

    /**
     * Count user-visible files under a prefix (live walk). Internal FluxFiles paths
     * (`_fluxfiles/`, `_variants/`) are excluded so the count matches what the
     * user actually sees and uploads.
     */
    public function getFileCount(string $disk, string $prefix): int
    {
        return $this->walk($disk, $prefix)['files'];
    }

    /**
     * @throws ApiException if adding $addCount files would exceed the file-count limit
     */
    public function assertFileCount(string $disk, string $prefix, int $addCount, int $maxFiles): void
    {
        $this->check($disk, $prefix, 0, $addCount, 0, $maxFiles);
    }

    /** Extension → type group. Anything not listed falls into "other". */
    private const TYPE_GROUPS = [
        'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif', 'heic'],
        'video'    => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'],
        'audio'    => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md', 'rtf', 'odt'],
        'archive'  => ['zip', 'rar', 'gz', 'tar', '7z', 'bz2'],
    ];

    /** Map a filename to its type group (by extension). */
    public static function typeOf(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        foreach (self::TYPE_GROUPS as $group => $exts) {
            if (in_array($ext, $exts, true)) {
                return $group;
            }
        }
        return 'other';
    }

    /**
     * One recursive pass over a prefix that computes the usage breakdown the
     * dashboard needs — total size, file count, size/count by type group (from
     * the extension, no per-file MIME lookup), and the largest folders. Internal
     * FluxFiles paths (`_fluxfiles/`, `_variants/`) are excluded so the figures
     * reflect user content, matching getFileCount.
     *
     * `raw_total` is the sum of EVERY file (including `_fluxfiles/`/`_variants/`),
     * matching getUsage — so the quota meter can reuse this single pass instead of
     * listing again.
     *
     * `supported` is false (with zeroed figures) on disks where a recursive walk is
     * too slow (SFTP) — the caller then hides the meter instead of showing a 0.
     *
     * @return array{total_size:int,raw_total:int,file_count:int,by_type:array<string,array{size:int,count:int}>,by_folder:list<array{path:string,size:int,count:int}>,supported:bool}
     */
    public function getUsageBreakdown(string $disk, string $prefix, int $topFolders = 10, int $folderDepth = 1): array
    {
        // SFTP: skip the recursive remote walk — it would hang on a real tree.
        if ($this->recursiveWalkTooSlow($disk)) {
            return [
                'total_size' => 0, 'raw_total' => 0, 'file_count' => 0,
                'by_type' => [], 'by_folder' => [], 'supported' => false,
            ];
        }

        $fs = $this->diskManager->disk($disk);
        $prefixTrim = trim($prefix, '/');
        $folderDepth = max(1, $folderDepth);

        $total = 0;
        $rawTotal = 0;
        $count = 0;
        $byType = [];
        $byFolder = [];

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($prefix, true) as $item) {
            if (!($item instanceof FileAttributes)) {
                continue;
            }
            $path = $item->path();
            $size = $item->fileSize() ?? 0;
            $rawTotal += $size; // includes internal — matches getUsage / the quota meter
            // FileManager::isReservedKey() is the single, case-insensitive rule —
            // a case-sensitive substring test here let `_FLUXFILES/` files count
            // against the user's quota (and, worse, diverge from what every other
            // caller considers reserved).
            if (FileManager::isReservedKey($path)) {
                continue;
            }
            $total += $size;
            $count++;

            $type = self::typeOf($path);
            $byType[$type]['size'] = ($byType[$type]['size'] ?? 0) + $size;
            $byType[$type]['count'] = ($byType[$type]['count'] ?? 0) + 1;

            // Folder = first $folderDepth segments of the path *relative to the
            // prefix*, excluding the filename. Files at the root group under "/".
            $rel = $path;
            if ($prefixTrim !== '' && strpos($rel, $prefixTrim . '/') === 0) {
                $rel = substr($rel, strlen($prefixTrim) + 1);
            }
            $segments = explode('/', $rel);
            array_pop($segments); // drop the filename
            $folder = $segments === [] ? '/' : '/' . implode('/', array_slice($segments, 0, $folderDepth));
            $byFolder[$folder]['size'] = ($byFolder[$folder]['size'] ?? 0) + $size;
            $byFolder[$folder]['count'] = ($byFolder[$folder]['count'] ?? 0) + 1;
        }

        // The same pass is what the meter's cache wants — refresh it (display only).
        $this->storeWalk($disk, $prefix, $rawTotal, $count);

        // by_folder → list sorted by size desc, top N.
        $folders = [];
        foreach ($byFolder as $fpath => $agg) {
            $folders[] = ['path' => $fpath, 'size' => $agg['size'], 'count' => $agg['count']];
        }
        usort($folders, static fn ($a, $b) => $b['size'] <=> $a['size']);

        return [
            'total_size' => $total,
            'raw_total'  => $rawTotal,
            'file_count' => $count,
            'by_type'    => $byType,
            'by_folder'  => array_slice($folders, 0, max(1, $topFolders)),
            'supported'  => true,
        ];
    }

    /**
     * Assemble the dashboard response from a breakdown: quota (raw usage vs limit
     * + ok/warning/critical status) and per-type percentages (of user content).
     * Pure — no storage access — so the threshold math is unit-testable.
     *
     * @param array $breakdown result of getUsageBreakdown()
     */
    public function usageResponse(array $breakdown, int $maxStorageMb, int $warnPct, int $critPct): array
    {
        $rawUsed = (int) ($breakdown['raw_total'] ?? 0);
        $maxBytes = $maxStorageMb > 0 ? $maxStorageMb * 1024 * 1024 : null;
        $percent = ($maxBytes !== null && $maxBytes > 0) ? round($rawUsed / $maxBytes * 100, 1) : null;

        $warn = $warnPct > 0 ? $warnPct : 70;
        $crit = $critPct > 0 ? $critPct : 90;
        $status = 'ok';
        if ($percent !== null) {
            if ($percent >= $crit) {
                $status = 'critical';
            } elseif ($percent >= $warn) {
                $status = 'warning';
            }
        }

        $userTotal = ((int) ($breakdown['total_size'] ?? 0)) ?: 1; // avoid div-by-zero
        $byType = [];
        foreach (($breakdown['by_type'] ?? []) as $type => $agg) {
            $byType[] = [
                'type'       => $type,
                'size_bytes' => $agg['size'],
                'count'      => $agg['count'],
                'percent'    => round($agg['size'] / $userTotal * 100, 1),
            ];
        }
        usort($byType, static fn ($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);

        return [
            'computed_at' => gmdate('c'),
            'supported'   => (bool) ($breakdown['supported'] ?? true),
            'quota' => [
                'used_bytes'  => $rawUsed,
                'limit_bytes' => $maxBytes,
                'percent'     => $percent,
                'status'      => $status,
            ],
            'file_count'    => (int) ($breakdown['file_count'] ?? 0),
            'content_bytes' => (int) ($breakdown['total_size'] ?? 0),
            'by_type'       => $byType,
            'top_folders'   => array_map(
                static fn ($f) => ['path' => $f['path'], 'size_bytes' => $f['size'], 'count' => $f['count']],
                $breakdown['by_folder'] ?? []
            ),
        ];
    }

    public function getQuotaInfo(string $disk, string $prefix, int $maxStorageMb): array
    {
        $maxBytes = $maxStorageMb > 0 ? $maxStorageMb * 1024 * 1024 : null;

        // SFTP: don't run the recursive remote walk on every navigate — report
        // usage as unknown/unsupported so the storage meter hides itself.
        if ($this->recursiveWalkTooSlow($disk)) {
            return [
                'used_bytes'   => null,
                'used_mb'      => null,
                'max_mb'       => $maxStorageMb > 0 ? $maxStorageMb : null,
                'max_bytes'    => $maxBytes,
                'remaining_mb' => null,
                'percentage'   => null,
                'supported'    => false,
            ];
        }

        $fig = $this->figures($disk, $prefix);
        $currentUsage = $fig['bytes'];

        return [
            'used_bytes'    => $currentUsage,
            'used_mb'       => round($currentUsage / (1024 * 1024), 2),
            'max_mb'        => $maxStorageMb > 0 ? $maxStorageMb : null,
            'max_bytes'     => $maxBytes,
            'remaining_mb'  => $maxBytes !== null ? round(($maxBytes - $currentUsage) / (1024 * 1024), 2) : null,
            'percentage'    => $maxBytes !== null ? round(($currentUsage / $maxBytes) * 100, 1) : null,
            'supported'     => true,
            'cached'        => $fig['cached'],
        ];
    }
}

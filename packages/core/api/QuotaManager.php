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

    public function __construct(DiskManager $diskManager)
    {
        $this->diskManager = $diskManager;
    }

    public function getUsage(string $disk, string $prefix): int
    {
        $fs = $this->diskManager->disk($disk);
        $total = 0;

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($prefix, true) as $item) {
            if ($item instanceof FileAttributes) {
                $total += $item->fileSize() ?? 0;
            }
        }

        return $total;
    }

    /**
     * @throws ApiException if quota would be exceeded
     */
    public function assertQuota(string $disk, string $prefix, int $fileSizeBytes, int $maxStorageMb): void
    {
        if ($maxStorageMb <= 0) {
            return;
        }

        $maxBytes = $maxStorageMb * 1024 * 1024;
        $currentUsage = $this->getUsage($disk, $prefix);

        if (($currentUsage + $fileSizeBytes) > $maxBytes) {
            $usedMb = round($currentUsage / (1024 * 1024), 2);
            throw new ApiException(
                "Storage quota exceeded: {$usedMb}MB used of {$maxStorageMb}MB limit",
                413,
                'quota_exceeded',
                ['used' => $usedMb . 'MB', 'max' => $maxStorageMb . 'MB']
            );
        }
    }

    /**
     * Count user-visible files under a prefix. Internal FluxFiles paths
     * (`_fluxfiles/`, `_variants/`) are excluded so the count matches what the
     * user actually sees and uploads.
     */
    public function getFileCount(string $disk, string $prefix): int
    {
        $fs = $this->diskManager->disk($disk);
        $count = 0;

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($prefix, true) as $item) {
            if (!($item instanceof FileAttributes)) {
                continue;
            }
            $path = $item->path();
            if (strpos($path, '_fluxfiles/') !== false || strpos($path, '_variants/') !== false) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @throws ApiException if adding $addCount files would exceed the file-count limit
     */
    public function assertFileCount(string $disk, string $prefix, int $addCount, int $maxFiles): void
    {
        if ($maxFiles <= 0) {
            return;
        }

        $current = $this->getFileCount($disk, $prefix);
        if (($current + $addCount) > $maxFiles) {
            throw new ApiException(
                "File limit reached: {$current} of {$maxFiles} files used",
                413,
                'too_many_files',
                ['used' => $current, 'max' => $maxFiles]
            );
        }
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
     * @return array{total_size:int,raw_total:int,file_count:int,by_type:array<string,array{size:int,count:int}>,by_folder:list<array{path:string,size:int,count:int}>}
     */
    public function getUsageBreakdown(string $disk, string $prefix, int $topFolders = 10, int $folderDepth = 1): array
    {
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
            if (strpos($path, '_fluxfiles/') !== false || strpos($path, '_variants/') !== false) {
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
        $currentUsage = $this->getUsage($disk, $prefix);
        $maxBytes = $maxStorageMb > 0 ? $maxStorageMb * 1024 * 1024 : null;

        return [
            'used_bytes'    => $currentUsage,
            'used_mb'       => round($currentUsage / (1024 * 1024), 2),
            'max_mb'        => $maxStorageMb > 0 ? $maxStorageMb : null,
            'max_bytes'     => $maxBytes,
            'remaining_mb'  => $maxBytes !== null ? round(($maxBytes - $currentUsage) / (1024 * 1024), 2) : null,
            'percentage'    => $maxBytes !== null ? round(($currentUsage / $maxBytes) * 100, 1) : null,
        ];
    }
}

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
                413
            );
        }
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

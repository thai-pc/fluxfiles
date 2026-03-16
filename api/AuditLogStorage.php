<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Audit log lưu trong storage của user (_fluxfiles/audit.jsonl) — không cần SQLite.
 */
class AuditLogStorage
{
    private StorageMetadataHandler $storage;
    private array $claimsDisks;

    public function __construct(StorageMetadataHandler $storage, array $claimsDisks)
    {
        $this->storage = $storage;
        $this->claimsDisks = $claimsDisks;
    }

    public function log(
        string $userId,
        string $action,
        string $disk,
        string $fileKey,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        $this->storage->audit($disk, $action, [
            'user_id'   => $userId,
            'file_key'  => $fileKey,
            'ip'        => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    }

    /**
     * List audit entries — đọc từ tất cả disks user có quyền.
     */
    public function list(int $limit = 100, int $offset = 0, ?string $userId = null): array
    {
        $all = [];
        foreach ($this->claimsDisks as $disk) {
            try {
                $entries = $this->storage->readAudit($disk, $userId);
                $all = array_merge($all, $entries);
            } catch (\Throwable $e) {
                // Skip disk if error
            }
        }
        usort($all, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        return array_slice($all, $offset, $limit);
    }
}

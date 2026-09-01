<?php

declare(strict_types=1);

namespace FluxFiles;

interface MetadataRepositoryInterface
{
    public function get(string $disk, string $key): ?array;

    public function save(string $disk, string $key, array $data): void;

    public function delete(string $disk, string $key): void;

    public function deleteChildren(string $disk, string $prefix): int;

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int;

    public function getBulk(string $disk, array $keys): array;

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array;

    public function saveHash(string $disk, string $key, string $hash): void;

    public function findByHash(string $disk, string $hash, string $pathPrefix = '', ?string $ownerUserId = null): ?array;

    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void;

    public function countChildren(string $disk, string $prefix): int;

    public function indexFile(string $disk, string $key, array $data, bool $overwrite = false): bool;

    public function trackDir(string $disk, string $dirKey): void;

    public function trackParents(string $disk, string $key): void;

    public function dirsCreated(string $disk): array;

    public function renameDirPrefix(string $disk, string $oldPrefix, string $newPrefix): int;

    public function deleteDirPrefix(string $disk, string $prefix): int;

    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array;

    public function readAudit(string $disk, ?string $userId = null): array;

    public function audit(string $disk, string $action, array $context = []): void;

    public function readAuditArchive(string $disk): array;

    public function purgeAuditBefore(string $disk, int $beforeTs): array;

    public function allTrash(string $disk): array;

    public function getTrash(string $disk, string $id): ?array;

    public function addTrash(string $disk, string $id, array $entry): void;

    public function removeTrash(string $disk, string $id): void;
}

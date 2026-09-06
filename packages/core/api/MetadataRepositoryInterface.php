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

    // ---------------------------------------------------------------------
    // Legal hold (retention) — docs/RETENTION-LEGAL-HOLD-DESIGN.md §5. Free/core
    // storage primitives; enforcement (FileManager::assertNoActiveHold()) is
    // unconditional and license-independent — see the design doc for why.
    // ---------------------------------------------------------------------

    /** @return array<string,array> id => entry */
    public function allHolds(string $disk): array;

    public function getHold(string $disk, string $id): ?array;

    public function addHold(string $disk, string $id, array $entry): void;

    /** Marks a hold released (released_at/released_by/release_reason) — never removes it. */
    public function releaseHold(string $disk, string $id, array $releaseInfo): void;

    /** Count of currently active (non-released) holds — for the FLUXFILES_LEGAL_HOLD_MAX_ACTIVE cap. */
    public function countActiveHolds(string $disk): int;

    /**
     * Ancestor-or-self only. "Is $scopedPath itself covered by a hold on it or
     * one of its ancestor folders?" Used for status/list enrichment.
     */
    public function holdCovering(string $disk, string $scopedPath): ?array;

    /**
     * Full bidirectional overlap: ancestor-or-self OR descendant. "Would an
     * operation on $scopedPath touch anything under an active hold?" Used by
     * FileManager's mutating-operation guard.
     */
    public function holdBlocking(string $disk, string $scopedPath): ?array;
}

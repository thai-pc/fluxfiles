<?php

declare(strict_types=1);

namespace FluxFiles\Db;

/**
 * Narrow, migration-only extension implemented by the DB-backed metadata
 * handlers (core's DbMetadataHandler, Laravel's LaravelDbMetadataHandler,
 * WordPress's WpDbMetadataHandler) — deliberately NOT a widening of the
 * public MetadataRepositoryInterface, since the JSON source
 * (StorageMetadataHandler) has no use for these methods.
 *
 * MetadataRepositoryInterface::audit()/trackDir() both stamp `time()`
 * internally and audit() has no dedup key, so neither can preserve original
 * timestamps or be re-run safely when bulk-importing historical rows from
 * JSON. These three methods exist only to make JsonToDbMigrator's import
 * idempotent and timestamp-faithful.
 */
interface MigrationImportInterface
{
    /**
     * Bulk-insert audit rows carrying their original timestamp and content
     * hash, ignoring any row whose (disk, content_hash) already exists.
     *
     * @param array<int, array{user_id:?string,action:string,file_key:?string,ip:?string,user_agent:?string,detail:mixed,created_at:int,content_hash:string}> $entries
     * @return int Number of rows actually inserted (excludes ignored duplicates).
     */
    public function insertAuditEntries(string $disk, array $entries): int;

    /**
     * @param string[] $contentHashes
     * @return string[] The subset of $contentHashes already present for $disk.
     */
    public function existingAuditContentHashes(string $disk, array $contentHashes): array;

    /**
     * Insert-if-missing directories carrying their original created_at,
     * instead of trackDir()'s hardcoded time().
     *
     * @param array<string, int|null> $dirs Map of dir path => created_at.
     * @return int Number of rows actually inserted.
     */
    public function insertDirectoriesPreservingTimestamp(string $disk, array $dirs): int;
}

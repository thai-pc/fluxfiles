<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\DiskManager;

/**
 * Repair flow for docs/DB-STORAGE-MIGRATION-DESIGN.md §8, part B: reunites a
 * `file_metadata` row with its S3/R2 object after an external tool (raw
 * `aws s3 sync`, cross-region replication, a bucket migration) has moved or
 * renamed the object outside FluxFiles. Cross-references the
 * `x-amz-meta-fluxfiles-id` breadcrumb every object carries
 * (DbMetadataHandler::maybeWriteS3Breadcrumb wrote it) against
 * `file_metadata.object_uuid` — deliberately usable WITHOUT trusting the DB,
 * since the DB might be exactly what's lost or stale.
 */
class S3MetadataRepairer
{
    private Connection $db;
    private DiskManager $diskManager;

    public function __construct(Connection $db, DiskManager $diskManager)
    {
        $this->db = $db;
        $this->diskManager = $diskManager;
    }

    /** @return array<string, string> object_uuid => path, for every row on $disk with a breadcrumb recorded */
    public function dbRows(string $disk): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT path, object_uuid FROM file_metadata WHERE disk = ? AND object_uuid IS NOT NULL');
        $stmt->execute([$disk]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['object_uuid']] = $row['path'];
        }
        return $out;
    }

    /**
     * Raw ListObjectsV2 + HeadObject scan of the bucket, reading the
     * `x-amz-meta-fluxfiles-id` breadcrumb directly off each object — no
     * dependency on `file_metadata` at all.
     *
     * @return array<string, string> object_uuid => key, for every object carrying a breadcrumb
     */
    public function scanBucket(string $disk): array
    {
        $config = $this->diskManager->config($disk);
        if (($config['driver'] ?? '') !== 's3') {
            throw new \RuntimeException("Disk '{$disk}' is not an S3-compatible disk");
        }
        $client = $this->diskManager->s3Client($disk);
        $bucket = $config['bucket'] ?? '';
        $prefix = $config['prefix'] ?? '';

        $found = [];
        $continuationToken = null;
        do {
            $params = ['Bucket' => $bucket];
            if ($prefix !== '') {
                $params['Prefix'] = $prefix;
            }
            if ($continuationToken !== null) {
                $params['ContinuationToken'] = $continuationToken;
            }
            $result = $client->listObjectsV2($params);
            foreach (($result['Contents'] ?? []) as $obj) {
                $key = $obj['Key'];
                try {
                    $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
                    $uuid = $head['Metadata']['fluxfiles-id'] ?? null;
                    if ($uuid !== null) {
                        $found[$uuid] = $key;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            $continuationToken = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
        } while ($continuationToken !== null);

        return $found;
    }

    /**
     * Pure diff, no S3/DB access — this is the seam unit tests exercise.
     * Given the DB's uuid=>path map and the bucket's uuid=>key map,
     * classifies every entry:
     *   - moved: the UUID exists in both but at different paths (the object
     *     was renamed by a raw tool outside FluxFiles) — the only bucket the
     *     repair actually re-points anything for
     *   - orphaned_objects: an object carries a breadcrumb with no matching
     *     `file_metadata` row (row deleted, or the DB was restored stale)
     *   - orphaned_rows: a `file_metadata` row's UUID doesn't appear on any
     *     scanned object (object deleted, or moved by a tool that strips
     *     metadata on copy)
     * Never guesses: an object with no breadcrumb, or a breadcrumb that
     * matches nothing, is reported, not auto-adopted.
     *
     * @param array<string, string> $dbRows uuid => path
     * @param array<string, string> $s3Objects uuid => key
     * @return array{moved: array<int, array{uuid:string, old_path:string, new_path:string}>, orphaned_objects: array<int, array{uuid:string, key:string}>, orphaned_rows: array<int, array{uuid:string, path:string}>}
     */
    public function reconcile(array $dbRows, array $s3Objects): array
    {
        $moved = [];
        $orphanedObjects = [];
        $orphanedRows = [];

        foreach ($s3Objects as $uuid => $key) {
            if (!array_key_exists($uuid, $dbRows)) {
                $orphanedObjects[] = ['uuid' => $uuid, 'key' => $key];
                continue;
            }
            if ($dbRows[$uuid] !== $key) {
                $moved[] = ['uuid' => $uuid, 'old_path' => $dbRows[$uuid], 'new_path' => $key];
            }
        }
        foreach ($dbRows as $uuid => $path) {
            if (!array_key_exists($uuid, $s3Objects)) {
                $orphanedRows[] = ['uuid' => $uuid, 'path' => $path];
            }
        }

        return ['moved' => $moved, 'orphaned_objects' => $orphanedObjects, 'orphaned_rows' => $orphanedRows];
    }

    /**
     * Applies a `reconcile()` "moved" list: re-points `file_metadata.path`
     * (and its `path_hash`) to each object's new key, inside one
     * transaction — a mid-batch failure rolls back every re-point rather
     * than leaving some rows repaired and others not. Any pre-existing row
     * already sitting at the destination path is deleted first (the
     * incoming move wins), matching `renameChildren()`'s convention.
     *
     * @param array<int, array{uuid:string, old_path:string, new_path:string}> $moved
     * @return int number of rows re-pointed
     */
    public function apply(string $disk, array $moved): int
    {
        if ($moved === []) {
            return 0;
        }
        $this->db->beginExclusive();
        try {
            $delDest = $this->db->pdo()->prepare('DELETE FROM file_metadata WHERE disk = ? AND path_hash = ?');
            $upd = $this->db->pdo()->prepare('UPDATE file_metadata SET path = ?, path_hash = ? WHERE disk = ? AND object_uuid = ?');
            $count = 0;
            foreach ($moved as $entry) {
                $newHash = hash('sha256', $entry['new_path']);
                $delDest->execute([$disk, $newHash]);
                $upd->execute([$entry['new_path'], $newHash, $disk, $entry['uuid']]);
                $count += $upd->rowCount();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $count;
    }
}

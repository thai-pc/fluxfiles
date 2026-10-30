<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\AuditChain;

use FluxFiles\DiskManager;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\StorageMetadataHandler;

/**
 * SQL-backed metadata store (FLUXFILES_STORAGE_BACKEND=db). Unifies what the
 * JSON backend (StorageMetadataHandler) keeps as two separate stores — a
 * per-file sidecar and a shared _fluxfiles/index.json — into one row per
 * (disk, path_hash) in `file_metadata`. Every method here is a direct
 * semantic port of StorageMetadataHandler; that class is the reference
 * implementation for exact field names and edge-case behavior.
 */
class DbMetadataHandler implements MetadataRepositoryInterface, MigrationImportInterface
{
    // Storage-agnostic row/hold/path logic shared with the Laravel and WordPress
    // handlers — see Db\MetadataRowLogic.
    use MetadataRowLogic;

    private Connection $db;
    private DiskManager $diskManager;

    public function __construct(Connection $db, DiskManager $diskManager)
    {
        $this->db = $db;
        $this->diskManager = $diskManager;
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * S3/R2 breadcrumb (docs/design/DB-STORAGE-MIGRATION-DESIGN.md §8): the first time
     * a file is saved under the DB backend, stamp a UUID onto the raw S3
     * object as `x-amz-meta-fluxfiles-id` via CopyObject — the one small
     * write that lets `scripts/repair-s3-metadata.php` reunite an
     * externally-moved object with its `file_metadata` row without the DB.
     * Best-effort and idempotent: a row that already has a UUID is left
     * alone, a non-S3 disk or a disabled flag is a no-op, and any S3 failure
     * is swallowed so a breadcrumb write can never block a metadata save.
     */
    private function maybeWriteS3Breadcrumb(string $disk, string $key, ?string $existingUuid): ?string
    {
        if ($existingUuid !== null) {
            return $existingUuid;
        }
        // Already stamped by the PUT that created the object (s3WriteMetadata).
        $pending = $this->pendingUuid[$disk . "\0" . $key] ?? null;
        if ($pending !== null) {
            unset($this->pendingUuid[$disk . "\0" . $key]);
            return $pending;
        }
        if (($_ENV['FLUXFILES_DB_S3_BREADCRUMB'] ?? 'true') === 'false') {
            return null;
        }
        $config = $this->diskManager->config($disk);
        if (($config['driver'] ?? '') !== 's3') {
            return null;
        }
        try {
            $client = $this->diskManager->s3Client($disk);
            $bucket = $config['bucket'] ?? '';
            $existing = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            $uuid = $this->generateUuidV4();
            $metadata = $existing['Metadata'] ?? [];
            $metadata['fluxfiles-id'] = $uuid;
            $params = [
                'Bucket' => $bucket,
                'Key' => $key,
                'CopySource' => $bucket . '/' . $key,
                'Metadata' => $metadata,
                'MetadataDirective' => 'REPLACE',
            ];
            if (isset($existing['ContentType'])) {
                $params['ContentType'] = $existing['ContentType'];
            }
            $client->copyObject($params);
            return $uuid;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var array<string,string> uuid stamped on an object at write time, until its row is saved */
    private array $pendingUuid = [];

    /**
     * S3 `Metadata` for the PUT that creates $key: the breadcrumb is born with the
     * object, so the first save() needs no HEAD + self-copy. An existing row keeps
     * its uuid (a PUT over the object wiped the header, so it is re-stamped).
     * Null off S3 or with the breadcrumb disabled.
     *
     * @return array<string,string>|null
     */
    public function s3WriteMetadata(string $disk, string $key, array $data = []): ?array
    {
        if (($_ENV['FLUXFILES_DB_S3_BREADCRUMB'] ?? 'true') === 'false'
            || ($this->diskManager->config($disk)['driver'] ?? '') !== 's3') {
            return null;
        }
        $uuid = ($this->fetchRow($disk, $key)['object_uuid'] ?? null) ?: null;
        if ($uuid === null) {
            $uuid = $this->generateUuidV4();
            $this->pendingUuid[$disk . "\0" . $key] = $uuid;
        }
        return ['fluxfiles-id' => $uuid];
    }

    /** Escape LIKE metacharacters in a bound value — never in the SQL string itself. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function fetchRow(string $disk, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM file_metadata WHERE disk = ? AND path_hash = ?');
        $stmt->execute([$disk, $this->pathHash($key)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // ---------------------------------------------------------------------
    // File metadata
    // ---------------------------------------------------------------------

    public function save(string $disk, string $key, array $data): void
    {
        $existing = $this->fetchRow($disk, $key);
        $ex = $existing ?? [];

        $title    = $data['title'] ?? ($ex['title'] ?? '');
        $altText  = $data['alt_text'] ?? ($ex['alt_text'] ?? '');
        $caption  = $data['caption'] ?? ($ex['caption'] ?? '');
        $tags     = $data['tags'] ?? ($ex['tags'] ?? '');
        $owner    = $data['uploaded_by'] ?? ($ex['owner'] ?? null);
        $mime     = $data['mime'] ?? ($ex['mime'] ?? null);
        $size     = $data['size'] ?? ($ex['size'] ?? null);
        $width    = $data['width'] ?? ($ex['width'] ?? null);
        $height   = $data['height'] ?? ($ex['height'] ?? null);
        $modified = $data['modified'] ?? ($ex['modified_at'] ?? null);
        $created  = ($ex['created_at'] ?? null) ?? ($data['created'] ?? null);
        $objectUuid = $this->maybeWriteS3Breadcrumb($disk, $key, $ex['object_uuid'] ?? null);

        $insertCols = [
            'disk' => $disk, 'owner' => $owner, 'path' => $key, 'path_hash' => $this->pathHash($key),
            'title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'tags' => $tags,
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'created_at' => $created, 'modified_at' => $modified, 'object_uuid' => $objectUuid,
        ];
        $updateCols = $insertCols;
        unset($updateCols['disk'], $updateCols['path'], $updateCols['path_hash']);

        $sql = $this->db->dialect()->upsert('file_metadata', array_keys($insertCols), ['disk', 'path_hash'], array_keys($updateCols));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_values($insertCols));
    }

    public function indexFile(string $disk, string $key, array $data, bool $overwrite = false): bool
    {
        $existing = $this->fetchRow($disk, $key);
        if (!$overwrite && $existing !== null) {
            return false;
        }
        $ex = $existing ?? [];

        $title    = $data['title'] ?? ($ex['title'] ?? null);
        $altText  = $data['alt_text'] ?? ($ex['alt_text'] ?? null);
        $caption  = $data['caption'] ?? ($ex['caption'] ?? null);
        $tags     = $data['tags'] ?? ($ex['tags'] ?? null);
        $owner    = $data['uploaded_by'] ?? ($ex['owner'] ?? null);
        $mime     = $data['mime'] ?? ($ex['mime'] ?? null);
        $width    = $data['width'] ?? ($ex['width'] ?? null);
        $height   = $data['height'] ?? ($ex['height'] ?? null);
        $size     = $data['size'] ?? ($ex['size'] ?? null);
        $modified = $data['modified'] ?? ($ex['modified_at'] ?? null);
        $created  = ($ex['created_at'] ?? null) ?? ($data['created'] ?? null);
        $fileHash = isset($data['file_hash']) ? $data['file_hash'] : ($ex['file_hash'] ?? null);
        $objectUuid = $this->maybeWriteS3Breadcrumb($disk, $key, $ex['object_uuid'] ?? null);

        $insertCols = [
            'disk' => $disk, 'owner' => $owner, 'path' => $key, 'path_hash' => $this->pathHash($key),
            'title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'tags' => $tags,
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'created_at' => $created, 'modified_at' => $modified, 'file_hash' => $fileHash,
            'object_uuid' => $objectUuid,
        ];
        $updateCols = $insertCols;
        unset($updateCols['disk'], $updateCols['path'], $updateCols['path_hash']);

        $sql = $this->db->dialect()->upsert('file_metadata', array_keys($insertCols), ['disk', 'path_hash'], array_keys($updateCols));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_values($insertCols));
        return true;
    }

    /**
     * Carry the whole row of $srcKey to $dstKey (hash, mime, size, dims, dates,
     * and the exact null-vs-"" of the text columns) after a rename/move/copy —
     * save() alone only carries the sidecar-shaped fields. No-op without a source row.
     * A COPY keeps the destination's owner (as save() just decided); a MOVE
     * ($objectMoved) always carries the source's, so a leftover destination row can
     * never decide who owns the moved file. $fresh (a copy, not a move) stamps
     * created/modified with now.
     */
    public function copyIndexEntry(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey, bool $objectMoved = false, bool $fresh = false): void
    {
        $src = $this->fetchRow($srcDisk, $srcKey);
        if ($src === null) {
            return;
        }
        $dst = $this->fetchRow($dstDisk, $dstKey);
        // A same-disk MOVE carries the object's headers (the breadcrumb included), so
        // the row keeps its uuid and nothing is written to the object.
        $keepUuid = $objectMoved && $srcDisk === $dstDisk ? ($src['object_uuid'] ?? null) : null;
        $insertCols = [
            'disk' => $dstDisk, 'owner' => $dst !== null && !$objectMoved ? $dst['owner'] : $src['owner'], 'path' => $dstKey, 'path_hash' => $this->pathHash($dstKey),
            'title' => $src['title'], 'alt_text' => $src['alt_text'], 'caption' => $src['caption'], 'tags' => $src['tags'],
            'mime' => $src['mime'], 'size' => $src['size'], 'width' => $src['width'], 'height' => $src['height'],
            'created_at' => $fresh ? time() : $src['created_at'], 'modified_at' => $fresh ? time() : $src['modified_at'], 'file_hash' => $src['file_hash'],
            'object_uuid' => $this->maybeWriteS3Breadcrumb($dstDisk, $dstKey, $keepUuid ?? $dst['object_uuid'] ?? null),
        ];
        $updateCols = $insertCols;
        unset($updateCols['disk'], $updateCols['path'], $updateCols['path_hash']);
        $sql = $this->db->dialect()->upsert('file_metadata', array_keys($insertCols), ['disk', 'path_hash'], array_keys($updateCols));
        $this->db->pdo()->prepare($sql)->execute(array_values($insertCols));
    }

    public function delete(string $disk, string $key): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM file_metadata WHERE disk = ? AND path_hash = ?');
        $stmt->execute([$disk, $this->pathHash($key)]);
    }

    public function deleteChildren(string $disk, string $prefix): int
    {
        $like = $this->escapeLike($prefix) . '/%';
        $esc = $this->db->dialect()->likeEscapeClause();
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM file_metadata WHERE disk = ? AND (path = ? OR path LIKE ? {$esc})"
        );
        $stmt->execute([$disk, $prefix, $like]);
        return $stmt->rowCount();
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $pdo = $this->db->pdo();
        $like = $this->escapeLike($oldPrefix) . '/%';
        $esc = $this->db->dialect()->likeEscapeClause();
        $sel = $pdo->prepare("SELECT id, path FROM file_metadata WHERE disk = ? AND (path = ? OR path LIKE ? {$esc})");
        $sel->execute([$disk, $oldPrefix, $like]);
        $candidates = $sel->fetchAll();

        $matches = [];
        foreach ($candidates as $row) {
            $k = $row['path'];
            if ($k === $oldPrefix || strpos($k, $oldPrefix . '/') === 0) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return 0;
        }

        $this->db->beginExclusive();
        try {
            $delDest = $pdo->prepare('DELETE FROM file_metadata WHERE disk = ? AND path_hash = ?');
            $upd = $pdo->prepare('UPDATE file_metadata SET path = ?, path_hash = ? WHERE id = ?');
            foreach ($matches as $row) {
                $newKey = $newPrefix . substr($row['path'], strlen($oldPrefix));
                $newHash = $this->pathHash($newKey);
                $delDest->execute([$disk, $newHash]);
                $upd->execute([$newKey, $newHash, $row['id']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return count($matches);
    }

    public function getBulk(string $disk, array $keys): array
    {
        // One SELECT per 500 keys (path_hash IN (…)) instead of one per key.
        $result = [];
        $byHash = [];
        foreach ($keys as $key) {
            $result[$key] = null;
            $byHash[$this->pathHash((string) $key)][] = $key;
        }
        $pdo = $this->db->pdo();
        foreach (array_chunk(array_keys($byHash), 500) as $hashes) {
            $in = implode(',', array_fill(0, count($hashes), '?'));
            $stmt = $pdo->prepare("SELECT * FROM file_metadata WHERE disk = ? AND path_hash IN ({$in})");
            $stmt->execute(array_merge([$disk], $hashes));
            foreach ($stmt->fetchAll() as $row) {
                $entry = [
                    'title'       => $row['title'],
                    'alt_text'    => $row['alt_text'],
                    'caption'     => $row['caption'],
                    'tags'        => $row['tags'],
                    'uploaded_by' => $row['owner'],
                    'mime'        => $row['mime'],
                    'width'       => $row['width'],
                    'height'      => $row['height'],
                    'size'        => $row['size'],
                    'modified'    => $row['modified_at'],
                    'created'     => $row['created_at'],
                ];
                foreach ($byHash[$row['path_hash']] ?? [] as $key) {
                    $result[$key] = $entry;
                }
            }
        }
        return $result;
    }

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';

        $esc = $this->db->dialect()->likeEscapeClause();
        $sql = "SELECT * FROM file_metadata WHERE disk = ?";
        $params = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = ? OR path LIKE ? {$esc})";
            $params[] = $prefix;
            $params[] = $this->escapeLike($prefix) . '/%';
        }
        $sql .= " AND (LOWER(path) LIKE LOWER(?) {$esc} OR LOWER(title) LIKE LOWER(?) {$esc}"
              . " OR LOWER(alt_text) LIKE LOWER(?) {$esc} OR LOWER(caption) LIKE LOWER(?) {$esc}"
              . " OR LOWER(tags) LIKE LOWER(?) {$esc})";
        array_push($params, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ);
        $sql .= " ORDER BY id ASC LIMIT " . (int) $cap;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $fileKey = $row['path'];
            if ($this->isReservedPath($fileKey)) {
                continue;
            }
            if (!$includeHidden && $this->isHiddenPath($fileKey)) {
                continue;
            }
            $out = [
                'file_key'    => $fileKey,
                'title'       => $row['title'],
                'alt_text'    => $row['alt_text'],
                'caption'     => $row['caption'],
                'tags'        => $row['tags'],
                'uploaded_by' => $row['owner'],
                'mime'        => $row['mime'],
                'width'       => $row['width'],
                'height'      => $row['height'],
                'size'        => $row['size'],
                'modified'    => $row['modified_at'],
                'created'     => $row['created_at'],
            ];
            $out['title_hl']   = $this->highlight($out['title'] ?? '', $query);
            $out['alt_hl']     = $this->highlight($out['alt_text'] ?? '', $query);
            $out['caption_hl'] = $this->highlight($out['caption'] ?? '', $query);
            $out['tags_hl']    = $this->highlight($out['tags'] ?? '', $query);
            $results[] = $out;
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    public function saveHash(string $disk, string $key, string $hash): void
    {
        $pathHash = $this->pathHash($key);
        $insertCols = [
            'disk' => $disk, 'owner' => null, 'path' => $key, 'path_hash' => $pathHash,
            'title' => null, 'alt_text' => null, 'caption' => null, 'tags' => null,
            'mime' => null, 'size' => null, 'width' => null, 'height' => null,
            'created_at' => null, 'modified_at' => null, 'file_hash' => $hash,
        ];
        $sql = $this->db->dialect()->upsert('file_metadata', array_keys($insertCols), ['disk', 'path_hash'], ['file_hash']);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_values($insertCols));
    }

    public function findByHash(string $disk, string $hash, string $pathPrefix = '', ?string $ownerUserId = null): ?array
    {
        $prefix = trim($pathPrefix, '/');
        $stmt = $this->db->pdo()->prepare('SELECT * FROM file_metadata WHERE disk = ? AND file_hash = ? ORDER BY id ASC');
        $stmt->execute([$disk, $hash]);

        foreach ($stmt->fetchAll() as $row) {
            $fileKey = $row['path'];
            if (str_starts_with($fileKey, '_fluxfiles/')
                || str_starts_with($fileKey, '_variants/')
                || str_contains($fileKey, '/_fluxfiles/')
                || str_contains($fileKey, '/_variants/')) {
                continue;
            }
            if ($prefix !== '' && $fileKey !== $prefix && strpos($fileKey, $prefix . '/') !== 0) {
                continue;
            }
            if ($ownerUserId !== null) {
                $owner = $row['owner'];
                if ($owner !== null && $owner !== $ownerUserId) {
                    continue;
                }
            }
            $out = ['file_key' => $fileKey];
            foreach (['title', 'alt_text', 'caption', 'tags'] as $k) {
                if (isset($row[$k])) {
                    $out[$k] = $row[$k];
                }
            }
            return $out;
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Directory index (folder search)
    // ---------------------------------------------------------------------

    public function trackDir(string $disk, string $dirKey): void
    {
        $dirKey = trim($dirKey, '/');
        if ($dirKey === '' || $dirKey === '.' || $this->isReservedPath($dirKey)) {
            return;
        }
        $pdo = $this->db->pdo();
        $hash = $this->pathHash($dirKey);
        $exists = $pdo->prepare('SELECT 1 FROM directories WHERE disk = ? AND path_hash = ?');
        $exists->execute([$disk, $hash]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $ins = $pdo->prepare('INSERT INTO directories (disk, path, path_hash, created_at) VALUES (?, ?, ?, ?)');
        $ins->execute([$disk, $dirKey, $hash, time()]);
    }

    public function dirsCreated(string $disk): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT path, created_at FROM directories WHERE disk = ?');
        $stmt->execute([$disk]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['path']] = $row['created_at'] !== null ? (int) $row['created_at'] : null;
        }
        return $out;
    }

    public function insertDirectoriesPreservingTimestamp(string $disk, array $dirs): int
    {
        $pdo = $this->db->pdo();
        $sql = $this->db->dialect()->insertIgnore('directories', ['disk', 'path', 'path_hash', 'created_at'], ['disk', 'path_hash']);
        $stmt = $pdo->prepare($sql);
        $inserted = 0;
        foreach ($dirs as $path => $createdAt) {
            $path = trim((string) $path, '/');
            if ($path === '' || $path === '.' || $this->isReservedPath($path)) {
                continue;
            }
            $stmt->execute([$disk, $path, $this->pathHash($path), $createdAt]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    public function renameDirPrefix(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = trim($oldPrefix, '/');
        $newPrefix = trim($newPrefix, '/');
        if ($oldPrefix === '' || $oldPrefix === '_fluxfiles') {
            return 0;
        }

        $pdo = $this->db->pdo();
        $like = $this->escapeLike($oldPrefix) . '/%';
        $esc = $this->db->dialect()->likeEscapeClause();
        $sel = $pdo->prepare("SELECT path, path_hash FROM directories WHERE disk = ? AND (path = ? OR path LIKE ? {$esc})");
        $sel->execute([$disk, $oldPrefix, $like]);
        $candidates = $sel->fetchAll();

        $matches = [];
        foreach ($candidates as $row) {
            $k = $row['path'];
            if ($k === $oldPrefix || str_starts_with($k, $oldPrefix . '/')) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return 0;
        }

        $this->db->beginExclusive();
        try {
            $delSrc = $pdo->prepare('DELETE FROM directories WHERE disk = ? AND path_hash = ?');
            $existsAtDest = $pdo->prepare('SELECT 1 FROM directories WHERE disk = ? AND path_hash = ?');
            $ins = $pdo->prepare('INSERT INTO directories (disk, path, path_hash, created_at) VALUES (?, ?, ?, ?)');
            foreach ($matches as $row) {
                $newKey = $newPrefix . substr($row['path'], strlen($oldPrefix));
                $newHash = $this->pathHash($newKey);
                $delSrc->execute([$disk, $row['path_hash']]);
                // Destination-existing wins on collision (matches the JSON backend's
                // `$dirs + $updated` array-union semantics — left operand wins).
                $existsAtDest->execute([$disk, $newHash]);
                if ($existsAtDest->fetchColumn() === false) {
                    $ins->execute([$disk, $newKey, $newHash, time()]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return count($matches);
    }

    public function deleteDirPrefix(string $disk, string $prefix): int
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '' || $prefix === '_fluxfiles') {
            return 0;
        }
        $like = $this->escapeLike($prefix) . '/%';
        $esc = $this->db->dialect()->likeEscapeClause();
        $stmt = $this->db->pdo()->prepare("DELETE FROM directories WHERE disk = ? AND (path = ? OR path LIKE ? {$esc})");
        $stmt->execute([$disk, $prefix, $like]);
        return $stmt->rowCount();
    }

    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';
        $esc = $this->db->dialect()->likeEscapeClause();

        $sql = 'SELECT path, created_at FROM directories WHERE disk = ?';
        $params = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = ? OR path LIKE ? {$esc})";
            $params[] = $prefix;
            $params[] = $this->escapeLike($prefix) . '/%';
        }
        $sql .= " AND LOWER(path) LIKE LOWER(?) {$esc}";
        $params[] = $likeQ;
        $sql .= ' ORDER BY path ASC LIMIT ' . (int) $cap;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $dirKey = $row['path'];
            if ($this->isReservedPath($dirKey)) {
                continue;
            }
            if (!$includeHidden && $this->isHiddenPath($dirKey)) {
                continue;
            }
            $results[] = [
                'dir_key' => $dirKey,
                'name'    => basename($dirKey),
                'created' => $row['created_at'] !== null ? (int) $row['created_at'] : null,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    // ---------------------------------------------------------------------
    // Audit log
    // ---------------------------------------------------------------------

    public function readAudit(string $disk, ?string $userId = null): array
    {
        return $this->readAuditWindow($disk, null, null, $userId);
    }

    /**
     * readAudit() narrowed in SQL — the audit_log table has no rotation cap, so a
     * caller that only needs a window (a page of /audit, a fixity window since
     * the oldest baseline) shouldn't load the whole table. Not on
     * MetadataRepositoryInterface (adding parameters there would break every
     * implementer); StorageMetadataHandler has the same method.
     *
     * @param ?int $since Only rows with created_at >= $since.
     * @param ?int $limit Only the NEWEST $limit matching rows (still oldest-first).
     */
    public function readAuditWindow(string $disk, ?int $since = null, ?int $limit = null, ?string $userId = null): array
    {
        [$sql, $params] = $this->auditWindowSql('audit_log', $disk, $since, $limit, $userId);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->auditWindowEntries($stmt->fetchAll(), $disk, $limit);
    }

    public function audit(string $disk, string $action, array $context = []): void
    {
        $ts = time();
        $values = [
            $disk,
            $context['user_id'] ?? null,
            $action,
            $context['file_key'] ?? null,
            $context['ip'] ?? null,
            $context['user_agent'] ?? null,
            $context['detail'] ?? null,
            $ts,
        ];
        $insert = 'INSERT INTO audit_log (disk, owner, action, file_key, ip, user_agent, detail, created_at, prev_hash, entry_hash)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $key = AuditChain::key();
        if ($key === null) {
            try {
                $this->db->pdo()->prepare($insert)->execute([...$values, null, null]);
            } catch (\Throwable $e) {
                // Silent fail — matches StorageMetadataHandler::audit()'s best-effort contract.
            }
            return;
        }
        $entry = [
            'ts' => $ts, 'action' => $action,
            'user_id' => $values[1], 'file_key' => $values[3], 'ip' => $values[4],
            'user_agent' => $values[5], 'detail' => $values[6],
        ];
        // Tamper-evident chain: read + advance the per-disk head under a row lock
        // (BEGIN IMMEDIATE on SQLite), so concurrent writers serialise instead of
        // forking. The very first write for a disk races on INSERTing the head row
        // — the loser rolls back and retries once, then sees the row.
        $pdo = $this->db->pdo();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->db->beginExclusive();
            try {
                $forUpdate = $this->db->dialect()->forUpdateSuffix();
                $sel = $pdo->prepare("SELECT head_hash FROM audit_chain_head WHERE disk = ?{$forUpdate}");
                $sel->execute([$disk]);
                $prev = $sel->fetchColumn();
                $haveHead = $prev !== false;
                if (!$haveHead) {
                    // No head row yet (first chained write, or a log migrated in from
                    // JSON): continue from the newest chained row if there is one.
                    $last = $pdo->prepare('SELECT entry_hash FROM audit_log WHERE disk = ? AND entry_hash IS NOT NULL ORDER BY id DESC LIMIT 1');
                    $last->execute([$disk]);
                    $prev = $last->fetchColumn();
                }
                $prev = is_string($prev) ? $prev : '';
                $hash = AuditChain::hash($key, $prev, $entry);
                $pdo->prepare($insert)->execute([...$values, $prev, $hash]);
                $pdo->prepare($haveHead
                    ? 'UPDATE audit_chain_head SET head_hash = ? WHERE disk = ?'
                    : 'INSERT INTO audit_chain_head (head_hash, disk) VALUES (?, ?)')->execute([$hash, $disk]);
                $this->db->commit();
                return;
            } catch (\Throwable $e) {
                $this->db->rollback();
            }
        }
        // Silent fail after the retry — best-effort contract, as above.
    }

    public function purgeAuditBefore(string $disk, int $beforeTs): array
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM audit_log WHERE disk = ? AND created_at < ?');
        $stmt->execute([$disk, $beforeTs]);
        return ['archives_deleted' => 0, 'live_lines_removed' => $stmt->rowCount()];
    }

    public function insertAuditEntries(string $disk, array $entries): int
    {
        $sql = $this->db->dialect()->insertIgnore(
            'audit_log',
            ['disk', 'owner', 'action', 'file_key', 'ip', 'user_agent', 'detail', 'created_at', 'content_hash', 'prev_hash', 'entry_hash'],
            ['disk', 'content_hash']
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $inserted = 0;
        foreach ($entries as $entry) {
            $detail = $entry['detail'] ?? null;
            if ($detail !== null && !is_scalar($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $stmt->execute([
                $disk,
                $entry['user_id'] ?? null,
                $entry['action'],
                $entry['file_key'] ?? null,
                $entry['ip'] ?? null,
                $entry['user_agent'] ?? null,
                $detail,
                $entry['created_at'],
                $entry['content_hash'],
                // Carried across verbatim so a chain built in audit.jsonl still
                // verifies once migrated (AuditChain's canonical form is
                // backend-independent).
                $entry['prev_hash'] ?? null,
                $entry['entry_hash'] ?? null,
            ]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    public function existingAuditContentHashes(string $disk, array $contentHashes): array
    {
        if ($contentHashes === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($contentHashes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT content_hash FROM audit_log WHERE disk = ? AND content_hash IN ({$placeholders})"
        );
        $stmt->execute([$disk, ...$contentHashes]);
        return array_column($stmt->fetchAll(), 'content_hash');
    }

    // ---------------------------------------------------------------------
    // Trash index (soft-delete)
    // ---------------------------------------------------------------------

    public function allTrash(string $disk): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM trash WHERE disk = ?');
        $stmt->execute([$disk]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['id']] = $this->rowToTrashEntry($row);
        }
        return $out;
    }

    public function getTrash(string $disk, string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM trash WHERE disk = ? AND id = ?');
        $stmt->execute([$disk, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToTrashEntry($row);
    }

    public function addTrash(string $disk, string $id, array $entry): void
    {
        $insertCols = [
            'disk' => $disk,
            'id' => $id,
            'owner' => $entry['deleted_by'] ?? null,
            'original_key' => $entry['original_key'] ?? '',
            'basename' => $entry['basename'] ?? null,
            'is_dir' => !empty($entry['is_dir']) ? 1 : 0,
            'size' => $entry['size'] ?? null,
            'deleted_at' => $entry['deleted_at'] ?? null,
            'variants' => json_encode($entry['variants'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta' => json_encode($entry['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'files' => json_encode($entry['files'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dirs' => json_encode($entry['dirs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $updateCols = $insertCols;
        unset($updateCols['disk'], $updateCols['id']);

        $sql = $this->db->dialect()->upsert('trash', array_keys($insertCols), ['disk', 'id'], array_keys($updateCols));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_values($insertCols));
    }

    public function removeTrash(string $disk, string $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM trash WHERE disk = ? AND id = ?');
        $stmt->execute([$disk, $id]);
    }

    // ---------------------------------------------------------------------
    // Legal hold (retention) — docs/design/RETENTION-LEGAL-HOLD-DESIGN.md §5/§7. Same
    // "one row per disk+id" shape as trash above; StorageMetadataHandler's
    // _fluxfiles/holds.json is the reference implementation for field names.
    // ---------------------------------------------------------------------

    public function allHolds(string $disk): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM legal_holds WHERE disk = ?');
        $stmt->execute([$disk]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['id']] = $this->rowToHoldEntry($row);
        }
        return $out;
    }

    public function getHold(string $disk, string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM legal_holds WHERE disk = ? AND id = ?');
        $stmt->execute([$disk, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToHoldEntry($row);
    }

    public function addHold(string $disk, string $id, array $entry): void
    {
        $insertCols = [
            'disk'           => $disk,
            'id'             => $id,
            'path'           => $entry['path'] ?? '',
            'is_dir'         => !empty($entry['is_dir']) ? 1 : 0,
            'reason'         => $entry['reason'] ?? null,
            'placed_by'      => $entry['placed_by'] ?? null,
            'placed_at'      => $entry['placed_at'] ?? null,
            'released_at'    => $entry['released_at'] ?? null,
            'released_by'    => $entry['released_by'] ?? null,
            'release_reason' => $entry['release_reason'] ?? null,
        ];
        // The placing token's scope (legal-hold module: a narrower token may not
        // release it). Migration 0009 adds the column; before it has run, the hold
        // is still written — without the scope, so release falls back to the old
        // in-scope rule — rather than refusing to place a hold at all.
        if (array_key_exists('placed_by_scope', $entry)) {
            $insertCols['placed_by_scope'] = $entry['placed_by_scope'];
        }
        try {
            $this->upsertHold($insertCols);
        } catch (\PDOException $e) {
            if (!array_key_exists('placed_by_scope', $insertCols)) {
                throw $e;
            }
            error_log('FluxFiles: legal_holds.placed_by_scope is missing — run DB migration 0009 (' . $e->getMessage() . ')');
            unset($insertCols['placed_by_scope']);
            $this->upsertHold($insertCols);
        }
    }

    /** @param array<string,mixed> $insertCols */
    private function upsertHold(array $insertCols): void
    {
        $updateCols = $insertCols;
        unset($updateCols['disk'], $updateCols['id']);
        $sql = $this->db->dialect()->upsert('legal_holds', array_keys($insertCols), ['disk', 'id'], array_keys($updateCols));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_values($insertCols));
    }

    public function countActiveHolds(string $disk): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) AS n FROM legal_holds WHERE disk = ? AND released_at IS NULL');
        $stmt->execute([$disk]);
        $row = $stmt->fetch();
        return $row === false ? 0 : (int) $row['n'];
    }

}

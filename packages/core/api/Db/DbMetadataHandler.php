<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\DiskManager;
use FluxFiles\MetadataRepositoryInterface;

/**
 * SQL-backed metadata store (FLUXFILES_STORAGE_BACKEND=db). Unifies what the
 * JSON backend (StorageMetadataHandler) keeps as two separate stores — a
 * per-file sidecar and a shared _fluxfiles/index.json — into one row per
 * (disk, path_hash) in `file_metadata`. Every method here is a direct
 * semantic port of StorageMetadataHandler; that class is the reference
 * implementation for exact field names and edge-case behavior.
 */
class DbMetadataHandler implements MetadataRepositoryInterface
{
    private Connection $db;
    private DiskManager $diskManager;

    public function __construct(Connection $db, DiskManager $diskManager)
    {
        $this->db = $db;
        $this->diskManager = $diskManager;
    }

    private function pathHash(string $key): string
    {
        return hash('sha256', $key);
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

    private function isReservedPath(string $key): bool
    {
        foreach (explode('/', trim($key, '/')) as $seg) {
            if ($seg === '_fluxfiles' || $seg === '_variants') {
                return true;
            }
        }
        return false;
    }

    private function isHiddenPath(string $key): bool
    {
        foreach (explode('/', trim($key, '/')) as $seg) {
            if ($seg !== '' && $seg[0] === '.') {
                return true;
            }
        }
        return false;
    }

    private function highlight(string $text, string $query): ?string
    {
        if ($text === '' || $query === '') {
            return null;
        }
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $q = preg_quote(htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/');
        return preg_replace('/(' . $q . ')/iu', '<mark>$1</mark>', $escaped) ?: null;
    }

    // ---------------------------------------------------------------------
    // File metadata
    // ---------------------------------------------------------------------

    public function get(string $disk, string $key): ?array
    {
        $row = $this->fetchRow($disk, $key);
        if ($row === null || $row['title'] === null) {
            return null;
        }
        return [
            'title'       => $row['title'] ?? '',
            'alt_text'    => $row['alt_text'] ?? '',
            'caption'     => $row['caption'] ?? '',
            'tags'        => $row['tags'] ?? '',
            'uploaded_by' => $row['owner'],
        ];
    }

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

        $insertCols = [
            'disk' => $disk, 'owner' => $owner, 'path' => $key, 'path_hash' => $this->pathHash($key),
            'title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'tags' => $tags,
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'created_at' => $created, 'modified_at' => $modified,
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

        $insertCols = [
            'disk' => $disk, 'owner' => $owner, 'path' => $key, 'path_hash' => $this->pathHash($key),
            'title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'tags' => $tags,
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'created_at' => $created, 'modified_at' => $modified, 'file_hash' => $fileHash,
        ];
        $updateCols = $insertCols;
        unset($updateCols['disk'], $updateCols['path'], $updateCols['path_hash']);

        $sql = $this->db->dialect()->upsert('file_metadata', array_keys($insertCols), ['disk', 'path_hash'], array_keys($updateCols));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_values($insertCols));
        return true;
    }

    public function delete(string $disk, string $key): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM file_metadata WHERE disk = ? AND path_hash = ?');
        $stmt->execute([$disk, $this->pathHash($key)]);
    }

    public function deleteChildren(string $disk, string $prefix): int
    {
        $like = $this->escapeLike($prefix) . '/%';
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM file_metadata WHERE disk = ? AND (path = ? OR path LIKE ? ESCAPE '\\')"
        );
        $stmt->execute([$disk, $prefix, $like]);
        return $stmt->rowCount();
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $pdo = $this->db->pdo();
        $like = $this->escapeLike($oldPrefix) . '/%';
        $sel = $pdo->prepare("SELECT id, path FROM file_metadata WHERE disk = ? AND (path = ? OR path LIKE ? ESCAPE '\\')");
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
        $result = [];
        foreach ($keys as $key) {
            $row = $this->fetchRow($disk, $key);
            if ($row === null) {
                $result[$key] = null;
                continue;
            }
            $result[$key] = [
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
        }
        return $result;
    }

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';

        $sql = "SELECT * FROM file_metadata WHERE disk = ?";
        $params = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = ? OR path LIKE ? ESCAPE '\\')";
            $params[] = $prefix;
            $params[] = $this->escapeLike($prefix) . '/%';
        }
        $sql .= " AND (LOWER(path) LIKE LOWER(?) ESCAPE '\\' OR LOWER(title) LIKE LOWER(?) ESCAPE '\\'"
              . " OR LOWER(alt_text) LIKE LOWER(?) ESCAPE '\\' OR LOWER(caption) LIKE LOWER(?) ESCAPE '\\'"
              . " OR LOWER(tags) LIKE LOWER(?) ESCAPE '\\')";
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

    /** No-op: metadata already lives in the `file_metadata` table. */
    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void
    {
    }

    public function countChildren(string $disk, string $prefix): int
    {
        $fs = $this->diskManager->disk($disk);
        $count = 0;
        foreach ($fs->listContents($prefix, true) as $item) {
            if ($item->isFile() && !str_ends_with($item->path(), '.meta.json')) {
                $count++;
            }
        }
        return $count;
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

    public function trackParents(string $disk, string $key): void
    {
        $key = trim($key, '/');
        if ($key === '' || $key === '.' || $this->isReservedPath($key)) {
            return;
        }
        $dir = dirname($key);
        if ($dir === '.' || $dir === '') {
            return;
        }
        $dir = trim($dir, '/');
        if ($dir === '') {
            return;
        }
        $acc = [];
        foreach (explode('/', $dir) as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                continue;
            }
            $acc[] = $p;
            $d = implode('/', $acc);
            if ($this->isReservedPath($d)) {
                continue;
            }
            $this->trackDir($disk, $d);
        }
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

    public function renameDirPrefix(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = trim($oldPrefix, '/');
        $newPrefix = trim($newPrefix, '/');
        if ($oldPrefix === '' || $oldPrefix === '_fluxfiles') {
            return 0;
        }

        $pdo = $this->db->pdo();
        $like = $this->escapeLike($oldPrefix) . '/%';
        $sel = $pdo->prepare("SELECT path, path_hash FROM directories WHERE disk = ? AND (path = ? OR path LIKE ? ESCAPE '\\')");
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
        $stmt = $this->db->pdo()->prepare("DELETE FROM directories WHERE disk = ? AND (path = ? OR path LIKE ? ESCAPE '\\')");
        $stmt->execute([$disk, $prefix, $like]);
        return $stmt->rowCount();
    }

    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';

        $sql = 'SELECT path, created_at FROM directories WHERE disk = ?';
        $params = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = ? OR path LIKE ? ESCAPE '\\')";
            $params[] = $prefix;
            $params[] = $this->escapeLike($prefix) . '/%';
        }
        $sql .= " AND LOWER(path) LIKE LOWER(?) ESCAPE '\\'";
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
        $sql = 'SELECT * FROM audit_log WHERE disk = ?';
        $params = [$disk];
        if ($userId !== null) {
            $sql .= ' AND owner = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $entries = [];
        foreach ($stmt->fetchAll() as $row) {
            $entries[] = [
                'user_id'    => $row['owner'] ?? '',
                'action'     => $row['action'] ?? '',
                'disk'       => $disk,
                'file_key'   => $row['file_key'] ?? '',
                'ip'         => $row['ip'],
                'user_agent' => $row['user_agent'],
                'detail'     => $row['detail'],
                'created_at' => (int) $row['created_at'],
            ];
        }
        return $entries;
    }

    public function audit(string $disk, string $action, array $context = []): void
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO audit_log (disk, owner, action, file_key, ip, user_agent, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $disk,
                $context['user_id'] ?? null,
                $action,
                $context['file_key'] ?? null,
                $context['ip'] ?? null,
                $context['user_agent'] ?? null,
                $context['detail'] ?? null,
                time(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail — matches StorageMetadataHandler::audit()'s best-effort contract.
        }
    }

    /** Always empty: DB mode has no rotation/archive concept (no size cap to rotate against). */
    public function readAuditArchive(string $disk): array
    {
        return [];
    }

    public function purgeAuditBefore(string $disk, int $beforeTs): array
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM audit_log WHERE disk = ? AND created_at < ?');
        $stmt->execute([$disk, $beforeTs]);
        return ['archives_deleted' => 0, 'live_lines_removed' => $stmt->rowCount()];
    }

    // ---------------------------------------------------------------------
    // Trash index (soft-delete)
    // ---------------------------------------------------------------------

    private function rowToTrashEntry(array $row): array
    {
        return [
            'original_key' => $row['original_key'],
            'disk'         => $row['disk'],
            'basename'     => $row['basename'],
            'is_dir'       => (bool) $row['is_dir'],
            'size'         => $row['size'] !== null ? (int) $row['size'] : 0,
            'deleted_at'   => $row['deleted_at'] !== null ? (int) $row['deleted_at'] : 0,
            'deleted_by'   => $row['owner'],
            'variants'     => $row['variants'] !== null ? (json_decode($row['variants'], true) ?: []) : [],
            'meta'         => $row['meta'] !== null ? (json_decode($row['meta'], true) ?: []) : [],
            'files'        => $row['files'] !== null ? (json_decode($row['files'], true) ?: []) : [],
            'dirs'         => $row['dirs'] !== null ? (json_decode($row['dirs'], true) ?: []) : [],
        ];
    }

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
}

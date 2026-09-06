<?php

defined('ABSPATH') || exit;

use FluxFiles\Db\MigrationImportInterface;
use FluxFiles\DiskManager;
use FluxFiles\MetadataRepositoryInterface;

/**
 * SQL-backed metadata store for the WordPress adapter
 * (fluxfiles_storage_backend option = 'db'), built directly on `$wpdb` — no
 * ORM, matching the design doc's explicit choice. Every method here is a
 * direct semantic port of \FluxFiles\Db\DbMetadataHandler (and, one level
 * further back, StorageMetadataHandler) — those classes are the reference
 * implementation for exact field names and edge-case behavior. WordPress is
 * always MySQL/MariaDB, so unlike core/Laravel there is no dialect branching.
 *
 * The plugin's floor is WP 6.0, but $wpdb->prepare() only correctly
 * substitutes SQL NULL for a PHP null bound to a %s/%d placeholder as of
 * WP 6.2+. buildValueClause() below works around this by emitting the
 * literal NULL token for null columns instead of relying on prepare().
 */
class WpDbMetadataHandler implements MetadataRepositoryInterface, MigrationImportInterface
{
    private $wpdb;
    private DiskManager $diskManager;
    private string $tFileMeta;
    private string $tDirs;
    private string $tTrash;
    private string $tAudit;
    private string $tHolds;

    public function __construct(DiskManager $diskManager)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->diskManager = $diskManager;
        $this->tFileMeta = $wpdb->prefix . 'fluxfiles_file_metadata';
        $this->tDirs = $wpdb->prefix . 'fluxfiles_directories';
        $this->tTrash = $wpdb->prefix . 'fluxfiles_trash';
        $this->tAudit = $wpdb->prefix . 'fluxfiles_audit_log';
        $this->tHolds = $wpdb->prefix . 'fluxfiles_legal_holds';
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

    /**
     * Builds a NULL-safe "col1, col2" / "%s, NULL" / [bind values] triple for
     * an INSERT/UPDATE value list — $wpdb->prepare() cannot be trusted to turn
     * a bound PHP null into SQL NULL before WP 6.2, so null-valued columns get
     * the literal NULL token inlined instead of a placeholder.
     */
    private function buildValueClause(array $cols): array
    {
        $names = [];
        $placeholders = [];
        $bind = [];
        foreach ($cols as $name => $value) {
            $names[] = $name;
            if ($value === null) {
                $placeholders[] = 'NULL';
            } else {
                $placeholders[] = is_int($value) ? '%d' : '%s';
                $bind[] = $value;
            }
        }
        return [implode(', ', $names), implode(', ', $placeholders), $bind];
    }

    /** Same NULL-safety as buildValueClause(), for an "col = %s, col2 = NULL" UPDATE list. */
    private function buildAssignmentClause(array $cols): array
    {
        $parts = [];
        $bind = [];
        foreach ($cols as $name => $value) {
            if ($value === null) {
                $parts[] = "{$name} = NULL";
            } else {
                $parts[] = $name . ' = ' . (is_int($value) ? '%d' : '%s');
                $bind[] = $value;
            }
        }
        return [implode(', ', $parts), $bind];
    }

    private function query(string $sql, array $bind = [])
    {
        if ($bind !== []) {
            $sql = $this->wpdb->prepare($sql, $bind);
        }
        return $this->wpdb->query($sql);
    }

    private function getRow(string $sql, array $bind = []): ?array
    {
        $sql = $bind !== [] ? $this->wpdb->prepare($sql, $bind) : $sql;
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        return $row === null ? null : $row;
    }

    private function getResults(string $sql, array $bind = []): array
    {
        $sql = $bind !== [] ? $this->wpdb->prepare($sql, $bind) : $sql;
        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    private function getVar(string $sql, array $bind = [])
    {
        $sql = $bind !== [] ? $this->wpdb->prepare($sql, $bind) : $sql;
        return $this->wpdb->get_var($sql);
    }

    private function fetchRow(string $disk, string $key): ?array
    {
        return $this->getRow(
            "SELECT * FROM {$this->tFileMeta} WHERE disk = %s AND path_hash = %s",
            [$disk, $this->pathHash($key)]
        );
    }

    /** Atomic upsert into file_metadata via ON DUPLICATE KEY UPDATE (disk, path_hash). */
    private function upsertFileMetadata(array $insertCols, array $updateColNames): void
    {
        [$colSql, $valSql, $bind] = $this->buildValueClause($insertCols);
        $updateSql = implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $updateColNames));
        $sql = "INSERT INTO {$this->tFileMeta} ({$colSql}) VALUES ({$valSql}) ON DUPLICATE KEY UPDATE {$updateSql}";
        $this->query($sql, $bind);
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
        $updateCols = array_keys($insertCols);
        $updateCols = array_values(array_diff($updateCols, ['disk', 'path', 'path_hash']));

        $this->upsertFileMetadata($insertCols, $updateCols);
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
        $updateCols = array_values(array_diff(array_keys($insertCols), ['disk', 'path', 'path_hash']));

        $this->upsertFileMetadata($insertCols, $updateCols);
        return true;
    }

    public function delete(string $disk, string $key): void
    {
        $this->query(
            "DELETE FROM {$this->tFileMeta} WHERE disk = %s AND path_hash = %s",
            [$disk, $this->pathHash($key)]
        );
    }

    public function deleteChildren(string $disk, string $prefix): int
    {
        $like = $this->escapeLike($prefix) . '/%';
        return (int) $this->query(
            "DELETE FROM {$this->tFileMeta} WHERE disk = %s AND (path = %s OR path LIKE %s ESCAPE '\\\\')",
            [$disk, $prefix, $like]
        );
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $like = $this->escapeLike($oldPrefix) . '/%';
        $candidates = $this->getResults(
            "SELECT id, path FROM {$this->tFileMeta} WHERE disk = %s AND (path = %s OR path LIKE %s ESCAPE '\\\\')",
            [$disk, $oldPrefix, $like]
        );

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

        $this->wpdb->query('START TRANSACTION');
        try {
            foreach ($matches as $row) {
                $newKey = $newPrefix . substr($row['path'], strlen($oldPrefix));
                $newHash = $this->pathHash($newKey);
                // Source wins on collision: clear whatever's already at the destination.
                $this->query(
                    "DELETE FROM {$this->tFileMeta} WHERE disk = %s AND path_hash = %s",
                    [$disk, $newHash]
                );
                $this->query(
                    "UPDATE {$this->tFileMeta} SET path = %s, path_hash = %s WHERE id = %d",
                    [$newKey, $newHash, (int) $row['id']]
                );
            }
            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
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

        $sql = "SELECT * FROM {$this->tFileMeta} WHERE disk = %s";
        $bind = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = %s OR path LIKE %s ESCAPE '\\\\')";
            $bind[] = $prefix;
            $bind[] = $this->escapeLike($prefix) . '/%';
        }
        $sql .= " AND (LOWER(path) LIKE LOWER(%s) ESCAPE '\\\\' OR LOWER(title) LIKE LOWER(%s) ESCAPE '\\\\'"
              . " OR LOWER(alt_text) LIKE LOWER(%s) ESCAPE '\\\\' OR LOWER(caption) LIKE LOWER(%s) ESCAPE '\\\\'"
              . " OR LOWER(tags) LIKE LOWER(%s) ESCAPE '\\\\')";
        array_push($bind, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ);
        $sql .= ' ORDER BY id ASC LIMIT ' . (int) $cap;

        $rows = $this->getResults($sql, $bind);

        $results = [];
        foreach ($rows as $row) {
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
        $this->upsertFileMetadata($insertCols, ['file_hash']);
    }

    public function findByHash(string $disk, string $hash, string $pathPrefix = '', ?string $ownerUserId = null): ?array
    {
        $prefix = trim($pathPrefix, '/');
        $rows = $this->getResults(
            "SELECT * FROM {$this->tFileMeta} WHERE disk = %s AND file_hash = %s ORDER BY id ASC",
            [$disk, $hash]
        );

        foreach ($rows as $row) {
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

    /** No-op: metadata already lives in the wp_fluxfiles_file_metadata table. */
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
        $hash = $this->pathHash($dirKey);
        $exists = $this->getVar(
            "SELECT 1 FROM {$this->tDirs} WHERE disk = %s AND path_hash = %s",
            [$disk, $hash]
        );
        if ($exists !== null) {
            return;
        }
        $this->query(
            "INSERT INTO {$this->tDirs} (disk, path, path_hash, created_at) VALUES (%s, %s, %s, %d)",
            [$disk, $dirKey, $hash, time()]
        );
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
        $rows = $this->getResults(
            "SELECT path, created_at FROM {$this->tDirs} WHERE disk = %s",
            [$disk]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['path']] = $row['created_at'] !== null ? (int) $row['created_at'] : null;
        }
        return $out;
    }

    public function insertDirectoriesPreservingTimestamp(string $disk, array $dirs): int
    {
        $inserted = 0;
        foreach ($dirs as $path => $createdAt) {
            $path = trim((string) $path, '/');
            if ($path === '' || $path === '.' || $this->isReservedPath($path)) {
                continue;
            }
            $affected = (int) $this->query(
                "INSERT IGNORE INTO {$this->tDirs} (disk, path, path_hash, created_at) VALUES (%s, %s, %s, %d)",
                [$disk, $path, $this->pathHash($path), $createdAt]
            );
            $inserted += $affected;
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

        $like = $this->escapeLike($oldPrefix) . '/%';
        $candidates = $this->getResults(
            "SELECT path, path_hash FROM {$this->tDirs} WHERE disk = %s AND (path = %s OR path LIKE %s ESCAPE '\\\\')",
            [$disk, $oldPrefix, $like]
        );

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

        $this->wpdb->query('START TRANSACTION');
        try {
            foreach ($matches as $row) {
                $newKey = $newPrefix . substr($row['path'], strlen($oldPrefix));
                $newHash = $this->pathHash($newKey);
                $this->query(
                    "DELETE FROM {$this->tDirs} WHERE disk = %s AND path_hash = %s",
                    [$disk, $row['path_hash']]
                );
                // Destination-existing wins on collision (matches the JSON backend's
                // `$dirs + $updated` array-union semantics — left operand wins).
                $existsAtDest = $this->getVar(
                    "SELECT 1 FROM {$this->tDirs} WHERE disk = %s AND path_hash = %s",
                    [$disk, $newHash]
                );
                if ($existsAtDest === null) {
                    $this->query(
                        "INSERT INTO {$this->tDirs} (disk, path, path_hash, created_at) VALUES (%s, %s, %s, %d)",
                        [$disk, $newKey, $newHash, time()]
                    );
                }
            }
            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
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
        return (int) $this->query(
            "DELETE FROM {$this->tDirs} WHERE disk = %s AND (path = %s OR path LIKE %s ESCAPE '\\\\')",
            [$disk, $prefix, $like]
        );
    }

    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';

        $sql = "SELECT path, created_at FROM {$this->tDirs} WHERE disk = %s";
        $bind = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = %s OR path LIKE %s ESCAPE '\\\\')";
            $bind[] = $prefix;
            $bind[] = $this->escapeLike($prefix) . '/%';
        }
        $sql .= " AND LOWER(path) LIKE LOWER(%s) ESCAPE '\\\\'";
        $bind[] = $likeQ;
        $sql .= ' ORDER BY path ASC LIMIT ' . (int) $cap;

        $rows = $this->getResults($sql, $bind);

        $results = [];
        foreach ($rows as $row) {
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
        $sql = "SELECT * FROM {$this->tAudit} WHERE disk = %s";
        $bind = [$disk];
        if ($userId !== null) {
            $sql .= ' AND owner = %s';
            $bind[] = $userId;
        }
        $sql .= ' ORDER BY id ASC';
        $rows = $this->getResults($sql, $bind);

        $entries = [];
        foreach ($rows as $row) {
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
            [$colSql, $valSql, $bind] = $this->buildValueClause([
                'disk' => $disk,
                'owner' => $context['user_id'] ?? null,
                'action' => $action,
                'file_key' => $context['file_key'] ?? null,
                'ip' => $context['ip'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'detail' => $context['detail'] ?? null,
                'created_at' => time(),
            ]);
            $this->query("INSERT INTO {$this->tAudit} ({$colSql}) VALUES ({$valSql})", $bind);
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
        $affected = (int) $this->query(
            "DELETE FROM {$this->tAudit} WHERE disk = %s AND created_at < %d",
            [$disk, $beforeTs]
        );
        return ['archives_deleted' => 0, 'live_lines_removed' => $affected];
    }

    public function insertAuditEntries(string $disk, array $entries): int
    {
        $inserted = 0;
        foreach ($entries as $entry) {
            $detail = $entry['detail'] ?? null;
            if ($detail !== null && !is_scalar($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            [$colSql, $valSql, $bind] = $this->buildValueClause([
                'disk' => $disk,
                'owner' => $entry['user_id'] ?? null,
                'action' => $entry['action'],
                'file_key' => $entry['file_key'] ?? null,
                'ip' => $entry['ip'] ?? null,
                'user_agent' => $entry['user_agent'] ?? null,
                'detail' => $detail,
                'created_at' => $entry['created_at'],
                'content_hash' => $entry['content_hash'],
            ]);
            $affected = (int) $this->query("INSERT IGNORE INTO {$this->tAudit} ({$colSql}) VALUES ({$valSql})", $bind);
            $inserted += $affected;
        }
        return $inserted;
    }

    public function existingAuditContentHashes(string $disk, array $contentHashes): array
    {
        if ($contentHashes === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($contentHashes), '%s'));
        $rows = $this->getResults(
            "SELECT content_hash FROM {$this->tAudit} WHERE disk = %s AND content_hash IN ({$placeholders})",
            [$disk, ...$contentHashes]
        );
        return array_column($rows, 'content_hash');
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
        $rows = $this->getResults("SELECT * FROM {$this->tTrash} WHERE disk = %s", [$disk]);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['id']] = $this->rowToTrashEntry($row);
        }
        return $out;
    }

    public function getTrash(string $disk, string $id): ?array
    {
        $row = $this->getRow(
            "SELECT * FROM {$this->tTrash} WHERE disk = %s AND id = %s",
            [$disk, $id]
        );
        return $row === null ? null : $this->rowToTrashEntry($row);
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
        $updateCols = array_values(array_diff(array_keys($insertCols), ['disk', 'id']));

        [$colSql, $valSql, $bind] = $this->buildValueClause($insertCols);
        $updateSql = implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $updateCols));
        $sql = "INSERT INTO {$this->tTrash} ({$colSql}) VALUES ({$valSql}) ON DUPLICATE KEY UPDATE {$updateSql}";
        $this->query($sql, $bind);
    }

    public function removeTrash(string $disk, string $id): void
    {
        $this->query(
            "DELETE FROM {$this->tTrash} WHERE disk = %s AND id = %s",
            [$disk, $id]
        );
    }

    // ---------------------------------------------------------------------
    // Legal hold (retention) — direct port of FluxFiles\Db\DbMetadataHandler's
    // legal_holds table, same field shape. Free/core storage primitives;
    // enforcement lives in FileManager::assertNoActiveHold(), not here.
    // ---------------------------------------------------------------------

    private function rowToHoldEntry(array $row): array
    {
        return [
            'path'           => $row['path'],
            'is_dir'         => (bool) $row['is_dir'],
            'disk'           => $row['disk'],
            'reason'         => $row['reason'],
            'placed_by'      => $row['placed_by'],
            'placed_at'      => $row['placed_at'] !== null ? (int) $row['placed_at'] : null,
            'released_at'    => $row['released_at'] !== null ? (int) $row['released_at'] : null,
            'released_by'    => $row['released_by'],
            'release_reason' => $row['release_reason'],
        ];
    }

    public function allHolds(string $disk): array
    {
        $rows = $this->getResults("SELECT * FROM {$this->tHolds} WHERE disk = %s", [$disk]);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['id']] = $this->rowToHoldEntry($row);
        }
        return $out;
    }

    public function getHold(string $disk, string $id): ?array
    {
        $row = $this->getRow(
            "SELECT * FROM {$this->tHolds} WHERE disk = %s AND id = %s",
            [$disk, $id]
        );
        return $row === null ? null : $this->rowToHoldEntry($row);
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
        $updateCols = array_values(array_diff(array_keys($insertCols), ['disk', 'id']));

        [$colSql, $valSql, $bind] = $this->buildValueClause($insertCols);
        $updateSql = implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $updateCols));
        $sql = "INSERT INTO {$this->tHolds} ({$colSql}) VALUES ({$valSql}) ON DUPLICATE KEY UPDATE {$updateSql}";
        $this->query($sql, $bind);
    }

    public function releaseHold(string $disk, string $id, array $releaseInfo): void
    {
        $existing = $this->getHold($disk, $id);
        if ($existing === null) {
            return; // caller already validated existence before calling
        }
        $this->addHold($disk, $id, array_merge($existing, $releaseInfo));
    }

    public function countActiveHolds(string $disk): int
    {
        return (int) $this->getVar(
            "SELECT COUNT(*) FROM {$this->tHolds} WHERE disk = %s AND released_at IS NULL",
            [$disk]
        );
    }

    public function holdCovering(string $disk, string $scopedPath): ?array
    {
        return $this->findOverlappingHold($disk, $scopedPath, false);
    }

    public function holdBlocking(string $disk, string $scopedPath): ?array
    {
        return $this->findOverlappingHold($disk, $scopedPath, true);
    }

    /**
     * Same prefix-overlap semantics as StorageMetadataHandler/Db\DbMetadataHandler's
     * findOverlappingHold() — kept as a plain PHP scan (not SQL LIKE) so all
     * backends can never silently diverge on this security-relevant comparison.
     */
    private function findOverlappingHold(string $disk, string $scopedPath, bool $bidirectional): ?array
    {
        $scopedPath = trim($scopedPath, '/');
        if ($scopedPath === '') {
            return null;
        }
        foreach ($this->allHolds($disk) as $id => $entry) {
            if ($entry['released_at'] !== null) {
                continue; // released holds never block/cover
            }
            $holdPath = trim((string) ($entry['path'] ?? ''), '/');
            if ($holdPath === '') {
                continue;
            }
            $overlaps = $holdPath === $scopedPath
                || strpos($scopedPath, $holdPath . '/') === 0
                || ($bidirectional && strpos($holdPath, $scopedPath . '/') === 0);
            if ($overlaps) {
                return ['hold_id' => $id] + $entry;
            }
        }
        return null;
    }
}

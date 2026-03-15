<?php

declare(strict_types=1);

namespace FluxFiles;

use PDO;

class MetadataRepository
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $this->db = new PDO("sqlite:{$dbPath}");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->migrate();
    }

    public function get(string $disk, string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT title, alt_text, caption, tags FROM file_meta WHERE disk = ? AND file_key = ?'
        );
        $stmt->execute([$disk, $key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function save(string $disk, string $key, array $data): void
    {
        $now = time();
        $stmt = $this->db->prepare(
            'INSERT INTO file_meta (disk, file_key, title, alt_text, caption, tags, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(disk, file_key) DO UPDATE SET
                title = excluded.title,
                alt_text = excluded.alt_text,
                caption = excluded.caption,
                tags = excluded.tags,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            $disk,
            $key,
            $data['title'] ?? null,
            $data['alt_text'] ?? null,
            $data['caption'] ?? null,
            $data['tags'] ?? null,
            $now,
            $now,
        ]);

        // Sync to FTS index
        $this->syncFts($key, $data['title'] ?? null, $data['alt_text'] ?? null, $data['caption'] ?? null, $data['tags'] ?? null);
    }

    public function delete(string $disk, string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM file_meta WHERE disk = ? AND file_key = ?');
        $stmt->execute([$disk, $key]);
        $this->deleteFts($key);
    }

    public function getBulk(string $disk, array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare(
            "SELECT file_key, title, alt_text, caption, tags FROM file_meta WHERE disk = ? AND file_key IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$disk], $keys));

        $result = [];
        while ($row = $stmt->fetch()) {
            $fileKey = $row['file_key'];
            unset($row['file_key']);
            $result[$fileKey] = $row;
        }

        return $result;
    }

    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void
    {
        if (!in_array($disk, ['s3', 'r2'], true)) {
            return;
        }

        try {
            $client = $diskManager->s3Client($disk);
            $config = $diskManager->config($disk);
            $bucket = $config['bucket'] ?? '';

            $tagSet = [];
            if (!empty($data['title'])) {
                $tagSet[] = ['Key' => 'fm:title', 'Value' => substr($data['title'], 0, 256)];
            }
            if (!empty($data['alt_text'])) {
                $tagSet[] = ['Key' => 'fm:alt', 'Value' => substr($data['alt_text'], 0, 256)];
            }
            if (!empty($data['caption'])) {
                $tagSet[] = ['Key' => 'fm:caption', 'Value' => substr($data['caption'], 0, 256)];
            }

            if (!empty($tagSet)) {
                $client->putObjectTagging([
                    'Bucket'  => $bucket,
                    'Key'     => $key,
                    'Tagging' => ['TagSet' => $tagSet],
                ]);
            }
        } catch (\Exception $e) {
            // Silently fail — S3 tag sync failure should not crash the operation
            error_log("FluxFiles: S3 tag sync failed for {$disk}:{$key} — " . $e->getMessage());
        }
    }

    // --- Full-text Search ---

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = ''): array
    {
        $prefix = trim($pathPrefix, '/');
        $sql = 'SELECT fm.file_key, fm.title, fm.alt_text, fm.caption, fm.tags,
                    highlight(file_fts, 1, \'<mark>\', \'</mark>\') as title_hl,
                    highlight(file_fts, 2, \'<mark>\', \'</mark>\') as alt_hl,
                    highlight(file_fts, 3, \'<mark>\', \'</mark>\') as caption_hl,
                    highlight(file_fts, 4, \'<mark>\', \'</mark>\') as tags_hl
             FROM file_fts
             JOIN file_meta fm ON fm.disk = ? AND fm.file_key = file_fts.file_key
             WHERE file_fts MATCH ?
             AND fm.is_trashed = 0';
        $params = [$disk, $query];

        if ($prefix !== '') {
            $sql .= ' AND (fm.file_key = ? OR fm.file_key LIKE ?)';
            $params[] = $prefix;
            $params[] = $prefix . '/%';
        }

        $sql .= ' ORDER BY rank LIMIT ?';
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function syncFts(string $key, ?string $title, ?string $altText, ?string $caption, ?string $tags = null): void
    {
        // Delete existing entry
        $this->db->prepare('DELETE FROM file_fts WHERE file_key = ?')->execute([$key]);

        // Insert new entry
        $stmt = $this->db->prepare(
            'INSERT INTO file_fts (file_key, title, alt_text, caption, tags) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$key, $title ?? '', $altText ?? '', $caption ?? '', $tags ?? '']);
    }

    public function deleteFts(string $key): void
    {
        $this->db->prepare('DELETE FROM file_fts WHERE file_key = ?')->execute([$key]);
    }

    // --- Duplicate Detection ---

    public function saveHash(string $disk, string $key, string $hash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE file_meta SET file_hash = ?, updated_at = ? WHERE disk = ? AND file_key = ?'
        );
        $stmt->execute([$hash, time(), $disk, $key]);

        if ($stmt->rowCount() === 0) {
            $now = time();
            $stmt = $this->db->prepare(
                'INSERT OR IGNORE INTO file_meta (disk, file_key, file_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$disk, $key, $hash, $now, $now]);
        }
    }

    public function findByHash(string $disk, string $hash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT file_key, title, alt_text, caption, tags FROM file_meta WHERE disk = ? AND file_hash = ? AND is_trashed = 0 LIMIT 1'
        );
        $stmt->execute([$disk, $hash]);
        return $stmt->fetch() ?: null;
    }

    // --- Trash / Soft Delete ---

    public function trash(string $disk, string $key): void
    {
        $now = time();
        $stmt = $this->db->prepare(
            'UPDATE file_meta SET is_trashed = 1, trashed_at = ? WHERE disk = ? AND file_key = ?'
        );
        $stmt->execute([$now, $disk, $key]);

        // If no row existed, create one
        if ($stmt->rowCount() === 0) {
            $stmt = $this->db->prepare(
                'INSERT OR IGNORE INTO file_meta (disk, file_key, is_trashed, trashed_at, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([$disk, $key, $now, $now, $now]);
        }
    }

    /**
     * Trash all children whose file_key starts with "$prefix/".
     */
    public function trashChildren(string $disk, string $prefix): int
    {
        $now = time();
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '/%';
        $stmt = $this->db->prepare(
            "UPDATE file_meta SET is_trashed = 1, trashed_at = ? WHERE disk = ? AND file_key LIKE ? ESCAPE '\\' AND is_trashed = 0"
        );
        $stmt->execute([$now, $disk, $like]);
        return $stmt->rowCount();
    }

    public function restore(string $disk, string $key): void
    {
        $stmt = $this->db->prepare(
            'UPDATE file_meta SET is_trashed = 0, trashed_at = NULL WHERE disk = ? AND file_key = ?'
        );
        $stmt->execute([$disk, $key]);
    }

    /**
     * Restore all children whose file_key starts with "$prefix/".
     */
    public function restoreChildren(string $disk, string $prefix): int
    {
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '/%';
        $stmt = $this->db->prepare(
            "UPDATE file_meta SET is_trashed = 0, trashed_at = NULL WHERE disk = ? AND file_key LIKE ? ESCAPE '\\' AND is_trashed = 1"
        );
        $stmt->execute([$disk, $like]);
        return $stmt->rowCount();
    }

    public function getTrashed(string $disk): array
    {
        $stmt = $this->db->prepare(
            'SELECT file_key, title, alt_text, caption, tags, trashed_at FROM file_meta WHERE disk = ? AND is_trashed = 1 ORDER BY trashed_at DESC'
        );
        $stmt->execute([$disk]);
        return $stmt->fetchAll();
    }

    public function isTrashed(string $disk, string $key): bool
    {
        $stmt = $this->db->prepare(
            'SELECT is_trashed FROM file_meta WHERE disk = ? AND file_key = ?'
        );
        $stmt->execute([$disk, $key]);
        $row = $stmt->fetch();
        return $row && (bool) $row['is_trashed'];
    }

    /**
     * Get keys of files trashed more than $days days ago.
     */
    public function getExpiredTrash(string $disk, int $days = 30): array
    {
        $cutoff = time() - ($days * 86400);
        $stmt = $this->db->prepare(
            'SELECT file_key FROM file_meta WHERE disk = ? AND is_trashed = 1 AND trashed_at < ?'
        );
        $stmt->execute([$disk, $cutoff]);
        return array_column($stmt->fetchAll(), 'file_key');
    }

    public function purge(string $disk, string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM file_meta WHERE disk = ? AND file_key = ? AND is_trashed = 1');
        $stmt->execute([$disk, $key]);
    }

    /**
     * Permanently delete all children whose file_key starts with "$prefix/".
     */
    public function purgeChildren(string $disk, string $prefix): int
    {
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '/%';
        // Also delete FTS entries for children
        $stmt = $this->db->prepare(
            "SELECT file_key FROM file_meta WHERE disk = ? AND file_key LIKE ? ESCAPE '\\' AND is_trashed = 1"
        );
        $stmt->execute([$disk, $like]);
        $keys = array_column($stmt->fetchAll(), 'file_key');
        foreach ($keys as $k) {
            $this->deleteFts($k);
        }

        $stmt = $this->db->prepare(
            "DELETE FROM file_meta WHERE disk = ? AND file_key LIKE ? ESCAPE '\\' AND is_trashed = 1"
        );
        $stmt->execute([$disk, $like]);
        return $stmt->rowCount();
    }

    /**
     * Count children whose file_key starts with "$prefix/".
     */
    public function countChildren(string $disk, string $prefix): int
    {
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '/%';
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM file_meta WHERE disk = ? AND file_key LIKE ? ESCAPE '\\' AND is_trashed = 0"
        );
        $stmt->execute([$disk, $like]);
        return (int) $stmt->fetchColumn();
    }

    private function migrateFts(): void
    {
        // Check if file_fts exists
        $exists = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='file_fts'"
        )->fetch();

        if (!$exists) {
            // Fresh install — create with tags column
            $this->db->exec(
                'CREATE VIRTUAL TABLE file_fts USING fts5(file_key, title, alt_text, caption, tags)'
            );
            return;
        }

        // Check if tags column exists in FTS table
        $info = $this->db->query("PRAGMA table_info(file_fts)")->fetchAll();
        $hasTagsCol = false;
        foreach ($info as $col) {
            if (($col['name'] ?? '') === 'tags') {
                $hasTagsCol = true;
                break;
            }
        }

        if ($hasTagsCol) {
            return; // Already migrated
        }

        // Rebuild FTS table with tags column
        $this->db->exec('DROP TABLE IF EXISTS file_fts');
        $this->db->exec(
            'CREATE VIRTUAL TABLE file_fts USING fts5(file_key, title, alt_text, caption, tags)'
        );

        // Re-index existing metadata rows
        $stmt = $this->db->query('SELECT file_key, title, alt_text, caption, tags FROM file_meta');
        $insert = $this->db->prepare(
            'INSERT INTO file_fts (file_key, title, alt_text, caption, tags) VALUES (?, ?, ?, ?, ?)'
        );
        while ($row = $stmt->fetch()) {
            $insert->execute([
                $row['file_key'],
                $row['title'] ?? '',
                $row['alt_text'] ?? '',
                $row['caption'] ?? '',
                $row['tags'] ?? '',
            ]);
        }
    }

    private function migrate(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS file_meta (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                disk         TEXT    NOT NULL,
                file_key     TEXT    NOT NULL,
                title        TEXT    DEFAULT NULL,
                alt_text     TEXT    DEFAULT NULL,
                caption      TEXT    DEFAULT NULL,
                file_hash    TEXT    DEFAULT NULL,
                is_trashed   INTEGER DEFAULT 0,
                trashed_at   INTEGER DEFAULT NULL,
                created_at   INTEGER NOT NULL,
                updated_at   INTEGER NOT NULL,
                UNIQUE(disk, file_key)
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_file_meta_key ON file_meta(disk, file_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_file_meta_trash ON file_meta(disk, is_trashed)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_file_meta_hash ON file_meta(disk, file_hash)');

        // Migration: add columns if they don't exist (for existing databases)
        try {
            $this->db->exec('ALTER TABLE file_meta ADD COLUMN is_trashed INTEGER DEFAULT 0');
        } catch (\PDOException $e) {
            // Column already exists
        }
        try {
            $this->db->exec('ALTER TABLE file_meta ADD COLUMN trashed_at INTEGER DEFAULT NULL');
        } catch (\PDOException $e) {
            // Column already exists
        }
        try {
            $this->db->exec('ALTER TABLE file_meta ADD COLUMN file_hash TEXT DEFAULT NULL');
        } catch (\PDOException $e) {
            // Column already exists
        }
        try {
            $this->db->exec('ALTER TABLE file_meta ADD COLUMN tags TEXT DEFAULT NULL');
        } catch (\PDOException $e) {
            // Column already exists
        }

        // FTS5 virtual table for full-text search (with tags)
        // Check if existing FTS table has the tags column; if not, rebuild it
        $this->migrateFts();
    }
}

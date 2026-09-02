<?php

declare(strict_types=1);

namespace FluxFiles\Db;

/**
 * Streams file_metadata rows for backup/restore of the DB storage backend
 * (docs/DB-STORAGE-MIGRATION-DESIGN.md §7). Reads directly against the SQL
 * table rather than through MetadataRepositoryInterface: this tool's format
 * IS the DB row shape (incl. object_uuid), unlike the generic get()/getBulk()
 * API every other caller uses, and there is no JSON-backend equivalent.
 */
class MetadataExporter
{
    public const COLUMNS = [
        'disk', 'path', 'title', 'alt_text', 'caption', 'tags', 'mime',
        'size', 'width', 'height', 'file_hash', 'owner', 'created_at',
        'modified_at', 'object_uuid',
    ];

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @return \Generator<array<string, mixed>> one row per yield — never
     *   buffers the full result set, so this is safe for arbitrarily large disks.
     */
    public function rows(string $disk, string $prefix = '', ?string $owner = null): \Generator
    {
        $prefix = trim($prefix, '/');
        $esc = $this->db->dialect()->likeEscapeClause();
        $sql = 'SELECT disk, path, title, alt_text, caption, tags, mime, size, width, height, file_hash, owner, created_at, modified_at, object_uuid FROM file_metadata WHERE disk = ?';
        $params = [$disk];
        if ($prefix !== '') {
            $sql .= " AND (path = ? OR path LIKE ? {$esc})";
            $params[] = $prefix;
            $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '/%';
        }
        if ($owner !== null) {
            $sql .= ' AND owner = ?';
            $params[] = $owner;
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * Writes every matching row to $handle as NDJSON or CSV and returns the
     * row count. Used by both the HTTP route (php://output) and the CLI
     * mirror (a plain fopen()'d file) — one implementation, two entry points.
     */
    public function streamTo($handle, string $disk, string $format, string $prefix = '', ?string $owner = null): int
    {
        $count = 0;
        if ($format === 'csv') {
            fputcsv($handle, self::COLUMNS);
        }
        foreach ($this->rows($disk, $prefix, $owner) as $row) {
            if ($format === 'csv') {
                $ordered = [];
                foreach (self::COLUMNS as $col) {
                    $ordered[] = $row[$col] ?? '';
                }
                fputcsv($handle, $ordered);
            } else {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
            $count++;
        }
        return $count;
    }
}

<?php

declare(strict_types=1);

namespace FluxFiles\Db;

/**
 * Restores file_metadata rows previously produced by MetadataExporter
 * (docs/DB-STORAGE-MIGRATION-DESIGN.md §7). All-or-nothing: every entry's
 * path is validated against the caller's own scope before any row is
 * written, and the whole batch runs inside one transaction — a single bad
 * row aborts the entire import rather than leaving a partial write.
 */
class MetadataImporter
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    private function pathHash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param callable $isPathAllowed function(string $path): bool — the caller's Claims::isPathInScope
     * @return array{imported:int, errors: array<int, array{row:int, error:string}>}
     */
    public function import(string $disk, array $entries, callable $isPathAllowed): array
    {
        $errors = [];
        foreach ($entries as $i => $entry) {
            $path = (string) ($entry['path'] ?? '');
            if ($path === '') {
                $errors[] = ['row' => $i, 'error' => 'missing_path'];
                continue;
            }
            if (!$isPathAllowed($path)) {
                $errors[] = ['row' => $i, 'error' => 'path_out_of_scope'];
            }
        }
        if ($errors !== []) {
            return ['imported' => 0, 'errors' => $errors];
        }

        $cols = [
            'disk', 'owner', 'path', 'path_hash', 'title', 'alt_text', 'caption',
            'tags', 'mime', 'size', 'width', 'height', 'file_hash', 'object_uuid',
            'created_at', 'modified_at',
        ];
        $updateCols = array_values(array_diff($cols, ['disk', 'path', 'path_hash']));
        $sql = $this->db->dialect()->upsert('file_metadata', $cols, ['disk', 'path_hash'], $updateCols);

        $this->db->beginExclusive();
        try {
            $stmt = $this->db->pdo()->prepare($sql);
            foreach ($entries as $entry) {
                $path = (string) $entry['path'];
                $stmt->execute([
                    $disk,
                    $entry['owner'] ?? null,
                    $path,
                    $this->pathHash($path),
                    $entry['title'] ?? null,
                    $entry['alt_text'] ?? null,
                    $entry['caption'] ?? null,
                    $entry['tags'] ?? null,
                    $entry['mime'] ?? null,
                    $entry['size'] ?? null,
                    $entry['width'] ?? null,
                    $entry['height'] ?? null,
                    $entry['file_hash'] ?? null,
                    $entry['object_uuid'] ?? null,
                    $entry['created_at'] ?? null,
                    $entry['modified_at'] ?? null,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return ['imported' => count($entries), 'errors' => []];
    }
}

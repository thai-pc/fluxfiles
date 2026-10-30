<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\DiskManager;

/**
 * The storage-agnostic half of the SQL metadata handlers.
 *
 * DbMetadataHandler (core, PDO), LaravelDbMetadataHandler (Illuminate DB) and
 * WpDbMetadataHandler ($wpdb) each carried byte-identical copies of these methods;
 * only the SQL differs. Everything in here is pure logic (path predicates, row →
 * entry mapping, hold overlap matching) or built purely on other handler methods —
 * nothing here issues a query or knows the DB driver, so a fix lands in all three
 * handlers at once instead of drifting.
 *
 * The using class provides: $this->diskManager (DiskManager), and the SQL-backed
 * methods fetchRow(), allHolds(), getHold(), addHold() and trackDir(). It keeps
 * everything that touches the database.
 */
trait MetadataRowLogic
{
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

    private function pathHash(string $key): string
    {
        return hash('sha256', $key);
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
        ] + (isset($row['placed_by_scope']) && $row['placed_by_scope'] !== null
            ? ['placed_by_scope' => (string) $row['placed_by_scope']]
            : []);
    }

    /**
     * Pure matcher shared by findOverlappingHold() (single lookup) and
     * holdsCoveringMany() (batch lookup against an already-loaded result set) —
     * decouples the query from the overlap logic so callers can choose how
     * many times they pay for the former.
     *
     * @param array<string,array> $holds id => entry, as returned by allHolds()
     */
    private static function matchOverlappingHold(array $holds, string $scopedPath, bool $bidirectional): ?array
    {
        $scopedPath = trim($scopedPath, '/');
        if ($scopedPath === '') {
            return null;
        }
        foreach ($holds as $id => $entry) {
            if ($entry['released_at'] !== null) {
                continue; // released holds never block/cover
            }
            if (\FluxFiles\StorageMetadataHandler::holdPathOverlaps((string) ($entry['path'] ?? ''), $scopedPath, $bidirectional)) {
                // $id is a PHP array key — an all-digit hold id string gets
                // silently cast to int as an array key; cast back to string.
                return ['hold_id' => (string) $id] + $entry;
            }
        }
        return null;
    }

    /**
     * Same prefix-overlap semantics as StorageMetadataHandler::findOverlappingHold()
     * — kept as a plain PHP scan (not SQL LIKE) so the two backends can never
     * silently diverge on this security-relevant comparison.
     */
    private function findOverlappingHold(string $disk, string $scopedPath, bool $bidirectional): ?array
    {
        return self::matchOverlappingHold($this->allHolds($disk), $scopedPath, $bidirectional);
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
     * Batch counterpart to holdCovering() — runs the `SELECT * FROM legal_holds`
     * query ONCE for the whole page instead of once per item (mirrors
     * StorageMetadataHandler::holdsCoveringMany(); see its doc-comment for why).
     *
     * @param array<int,string> $scopedPaths
     * @return array<string, array<string,mixed>|null> keyed by the given scoped path
     */
    public function holdsCoveringMany(string $disk, array $scopedPaths): array
    {
        $holds = $this->allHolds($disk);
        $out = [];
        foreach ($scopedPaths as $scopedPath) {
            $out[$scopedPath] = self::matchOverlappingHold($holds, $scopedPath, false);
        }
        return $out;
    }

    public function releaseHold(string $disk, string $id, array $releaseInfo): void
    {
        $existing = $this->getHold($disk, $id);
        if ($existing === null) {
            return; // caller already validated existence before calling
        }
        $this->addHold($disk, $id, array_merge($existing, $releaseInfo));
    }

    public function get(string $disk, string $key): ?array
    {
        $row = $this->fetchRow($disk, $key);
        if ($row === null || ($row['title'] === null && $row['owner'] === null)) {
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

    /** No-op: metadata already lives in the `file_metadata` table. */
    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void
    {
    }

    /** Always empty: DB mode has no rotation/archive concept (no size cap to rotate against). */
    public function readAuditArchive(string $disk): array
    {
        return [];
    }

    // ---------------------------------------------------------------------
    // Audit-window shape — what readAuditWindow() needs, minus the driver.
    // ---------------------------------------------------------------------

    /**
     * SQL + bound params for readAuditWindow(): a disk's audit rows, optionally
     * narrowed to one actor (the `owner` column) and to entries at/after $since,
     * either the whole window oldest-first or the newest $limit rows (the caller
     * reverses those back to chronological order — see auditWindowEntries()). Uses
     * `?` placeholders; $limit is integer-cast and inlined because LIMIT placeholders
     * are not portable across MySQL (emulated prepares quote them), Postgres and SQLite.
     *
     * A handler adopts readAuditWindow() by running this against its own table
     * name and connection, then mapping the rows with auditWindowEntries().
     *
     * @return array{0:string,1:array<int,mixed>} [sql, params]
     */
    protected function auditWindowSql(string $table, string $disk, ?int $since, ?int $limit, ?string $userId): array
    {
        $sql = "SELECT * FROM {$table} WHERE disk = ?";
        $params = [$disk];
        if ($userId !== null) {
            $sql .= ' AND owner = ?';
            $params[] = $userId;
        }
        if ($since !== null) {
            $sql .= ' AND created_at >= ?';
            $params[] = $since;
        }
        if ($limit !== null) {
            $sql .= ' ORDER BY id DESC LIMIT ' . max(0, $limit);
        } else {
            $sql .= ' ORDER BY id ASC';
        }
        return [$sql, $params];
    }

    /**
     * Map audit_log rows (as fetched with auditWindowSql()) to the entry shape
     * readAudit() returns, restoring chronological order for a $limit query.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    protected function auditWindowEntries(array $rows, string $disk, ?int $limit): array
    {
        if ($limit !== null) {
            $rows = array_reverse($rows);
        }
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
                'prev_hash'  => $row['prev_hash'] ?? null,
                'entry_hash' => $row['entry_hash'] ?? null,
            ];
        }
        return $entries;
    }
}

<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use FluxFiles\DiskManager;
use FluxFiles\Db\MetadataRowLogic;
use FluxFiles\Db\MigrationImportInterface;
use FluxFiles\MetadataRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * SQL-backed metadata store for the Laravel adapter
 * (fluxfiles.storage_backend = db), built on Laravel's Query Builder rather
 * than core's raw-PDO Db\DbMetadataHandler — that gets connection pooling,
 * named connections, and RefreshDatabase/in-memory-SQLite test support for
 * free instead of fighting the framework. Every method here is a direct
 * semantic port of FluxFiles\Db\DbMetadataHandler — that class (and, one
 * level further back, StorageMetadataHandler) is the reference
 * implementation for exact field names and edge-case behavior.
 */
class LaravelDbMetadataHandler implements MetadataRepositoryInterface, MigrationImportInterface
{
    // Path predicates, row mapping, hold overlap matching and the audit-window
    // shape are shared with core's DbMetadataHandler (and WordPress's).
    use MetadataRowLogic;

    private DiskManager $diskManager;
    private ?string $connection;

    public function __construct(DiskManager $diskManager, ?string $connection = null)
    {
        $this->diskManager = $diskManager;
        $this->connection = $connection;
    }

    private function db()
    {
        return DB::connection($this->connection);
    }

    private function table(string $name)
    {
        return $this->db()->table($name);
    }

    /**
     * Every LIKE here is `<col> LIKE ? ESCAPE '!'`. Only MySQL (and Postgres)
     * treat a backslash as LIKE's escape by default; SQLite and SQL Server have
     * NO default escape character, so the old backslash-escaped pattern matched
     * literally there — a prefix containing `_` or `%` (`user_1`) never matched
     * its own children, and an unescaped `_` matched any character. `!` needs no
     * quoting in any dialect's string literal, unlike the backslash (see core's
     * PgsqlDialect::likeEscapeClause() for what a backslash costs on pdo_pgsql).
     */
    private const LIKE_ESCAPE = " ESCAPE '!'";

    /** Escape LIKE metacharacters in a bound value — never in the SQL string itself. */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function fetchRow(string $disk, string $key): ?array
    {
        $row = $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)
            ->where('path_hash', $this->pathHash($key))
            ->first();
        return $row === null ? null : (array) $row;
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

        $row = [
            'disk' => $disk, 'owner' => $owner, 'path' => $key, 'path_hash' => $this->pathHash($key),
            'title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'tags' => $tags,
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'created_at' => $created, 'modified_at' => $modified,
        ];

        $this->table('fluxfiles_file_metadata')->updateOrInsert(
            ['disk' => $disk, 'path_hash' => $row['path_hash']],
            $row
        );
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

        $row = [
            'disk' => $disk, 'owner' => $owner, 'path' => $key, 'path_hash' => $this->pathHash($key),
            'title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'tags' => $tags,
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'created_at' => $created, 'modified_at' => $modified, 'file_hash' => $fileHash,
        ];

        $this->table('fluxfiles_file_metadata')->updateOrInsert(
            ['disk' => $disk, 'path_hash' => $row['path_hash']],
            $row
        );
        return true;
    }

    /**
     * Carry the whole index row (owner, mime/size/dims, created/modified, hash and
     * the exact null-vs-"" of the text columns) after a rename/move/copy. Called by
     * core's FileManager::copyMetadata() when present; no-op without a source row.
     * (No S3 breadcrumb here — this adapter has none; $objectMoved is accepted for
     * signature parity with core's DB handler.) A COPY keeps the destination's owner
     * (as save() just decided); a MOVE ($objectMoved) carries the source's, so a
     * leftover destination row never decides. $fresh (a copy) stamps created/modified now.
     */
    public function copyIndexEntry(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey, bool $objectMoved = false, bool $fresh = false): void
    {
        $src = $this->fetchRow($srcDisk, $srcKey);
        if ($src === null) {
            return;
        }
        $dst = $this->fetchRow($dstDisk, $dstKey);
        $now = time();
        $row = [
            'disk' => $dstDisk, 'owner' => $dst !== null && !$objectMoved ? $dst['owner'] : $src['owner'], 'path' => $dstKey, 'path_hash' => $this->pathHash($dstKey),
            'title' => $src['title'], 'alt_text' => $src['alt_text'], 'caption' => $src['caption'], 'tags' => $src['tags'],
            'mime' => $src['mime'], 'size' => $src['size'], 'width' => $src['width'], 'height' => $src['height'],
            'created_at' => $fresh ? $now : $src['created_at'], 'modified_at' => $fresh ? $now : $src['modified_at'], 'file_hash' => $src['file_hash'],
        ];

        $this->table('fluxfiles_file_metadata')->updateOrInsert(
            ['disk' => $dstDisk, 'path_hash' => $row['path_hash']],
            $row
        );
    }

    public function delete(string $disk, string $key): void
    {
        $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)
            ->where('path_hash', $this->pathHash($key))
            ->delete();
    }

    public function deleteChildren(string $disk, string $prefix): int
    {
        $like = $this->escapeLike($prefix) . '/%';
        return $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)
            ->where(function ($q) use ($prefix, $like) {
                $q->where('path', $prefix)->orWhereRaw('path LIKE ?' . self::LIKE_ESCAPE, [$like]);
            })
            ->delete();
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $like = $this->escapeLike($oldPrefix) . '/%';
        $candidates = $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)
            ->where(function ($q) use ($oldPrefix, $like) {
                $q->where('path', $oldPrefix)->orWhereRaw('path LIKE ?' . self::LIKE_ESCAPE, [$like]);
            })
            ->get(['id', 'path']);

        $matches = [];
        foreach ($candidates as $row) {
            $k = $row->path;
            if ($k === $oldPrefix || strpos($k, $oldPrefix . '/') === 0) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return 0;
        }

        $this->db()->transaction(function () use ($disk, $oldPrefix, $newPrefix, $matches) {
            foreach ($matches as $row) {
                $newKey = $newPrefix . substr($row->path, strlen($oldPrefix));
                $newHash = $this->pathHash($newKey);
                // Source wins on collision: clear whatever's already at the destination.
                $this->table('fluxfiles_file_metadata')
                    ->where('disk', $disk)->where('path_hash', $newHash)->delete();
                $this->table('fluxfiles_file_metadata')
                    ->where('id', $row->id)
                    ->update(['path' => $newKey, 'path_hash' => $newHash]);
            }
        });

        return count($matches);
    }

    public function getBulk(string $disk, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = null;
        }
        // One IN (...) query per chunk of keys instead of a SELECT per key.
        $byHash = [];
        foreach (array_keys($result) as $key) {
            $byHash[$this->pathHash((string) $key)][] = $key;
        }
        foreach (array_chunk(array_keys($byHash), 500) as $hashes) {
            $rows = $this->table('fluxfiles_file_metadata')
                ->where('disk', $disk)
                ->whereIn('path_hash', $hashes)
                ->get();
            foreach ($rows as $row) {
                $row = (array) $row;
                foreach ($byHash[$row['path_hash']] ?? [] as $key) {
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
            }
        }
        return $result;
    }

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';

        $q = $this->table('fluxfiles_file_metadata')->where('disk', $disk);
        if ($prefix !== '') {
            $like = $this->escapeLike($prefix) . '/%';
            $q->where(function ($w) use ($prefix, $like) {
                $w->where('path', $prefix)->orWhereRaw('path LIKE ?' . self::LIKE_ESCAPE, [$like]);
            });
        }
        $q->where(function ($w) use ($likeQ) {
            $w->whereRaw('LOWER(path) LIKE LOWER(?)' . self::LIKE_ESCAPE, [$likeQ])
                ->orWhereRaw('LOWER(title) LIKE LOWER(?)' . self::LIKE_ESCAPE, [$likeQ])
                ->orWhereRaw('LOWER(alt_text) LIKE LOWER(?)' . self::LIKE_ESCAPE, [$likeQ])
                ->orWhereRaw('LOWER(caption) LIKE LOWER(?)' . self::LIKE_ESCAPE, [$likeQ])
                ->orWhereRaw('LOWER(tags) LIKE LOWER(?)' . self::LIKE_ESCAPE, [$likeQ]);
        });
        $rows = $q->orderBy('id', 'asc')->limit($cap)->get();

        $results = [];
        foreach ($rows as $row) {
            $row = (array) $row;
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
        $existing = $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)->where('path_hash', $pathHash)->exists();

        if ($existing) {
            $this->table('fluxfiles_file_metadata')
                ->where('disk', $disk)->where('path_hash', $pathHash)
                ->update(['file_hash' => $hash]);
            return;
        }

        $this->table('fluxfiles_file_metadata')->insert([
            'disk' => $disk, 'owner' => null, 'path' => $key, 'path_hash' => $pathHash,
            'title' => null, 'alt_text' => null, 'caption' => null, 'tags' => null,
            'mime' => null, 'size' => null, 'width' => null, 'height' => null,
            'created_at' => null, 'modified_at' => null, 'file_hash' => $hash,
        ]);
    }

    public function findByHash(string $disk, string $hash, string $pathPrefix = '', ?string $ownerUserId = null): ?array
    {
        $prefix = trim($pathPrefix, '/');
        $rows = $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)->where('file_hash', $hash)
            ->orderBy('id', 'asc')->get();

        foreach ($rows as $row) {
            $row = (array) $row;
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
        $hash = $this->pathHash($dirKey);
        $exists = $this->table('fluxfiles_directories')
            ->where('disk', $disk)->where('path_hash', $hash)->exists();
        if ($exists) {
            return;
        }
        $this->table('fluxfiles_directories')->insert([
            'disk' => $disk, 'path' => $dirKey, 'path_hash' => $hash, 'created_at' => time(),
        ]);
    }

    public function dirsCreated(string $disk): array
    {
        $rows = $this->table('fluxfiles_directories')
            ->where('disk', $disk)->get(['path', 'created_at']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->path] = $row->created_at !== null ? (int) $row->created_at : null;
        }
        return $out;
    }

    public function insertDirectoriesPreservingTimestamp(string $disk, array $dirs): int
    {
        $rows = [];
        foreach ($dirs as $path => $createdAt) {
            $path = trim((string) $path, '/');
            if ($path === '' || $path === '.' || $this->isReservedPath($path)) {
                continue;
            }
            $rows[] = ['disk' => $disk, 'path' => $path, 'path_hash' => $this->pathHash($path), 'created_at' => $createdAt];
        }
        if ($rows === []) {
            return 0;
        }
        return $this->table('fluxfiles_directories')->insertOrIgnore($rows);
    }

    public function renameDirPrefix(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = trim($oldPrefix, '/');
        $newPrefix = trim($newPrefix, '/');
        if ($oldPrefix === '' || $oldPrefix === '_fluxfiles') {
            return 0;
        }

        $like = $this->escapeLike($oldPrefix) . '/%';
        $candidates = $this->table('fluxfiles_directories')
            ->where('disk', $disk)
            ->where(function ($q) use ($oldPrefix, $like) {
                $q->where('path', $oldPrefix)->orWhereRaw('path LIKE ?' . self::LIKE_ESCAPE, [$like]);
            })
            ->get(['path', 'path_hash']);

        $matches = [];
        foreach ($candidates as $row) {
            $k = $row->path;
            if ($k === $oldPrefix || str_starts_with($k, $oldPrefix . '/')) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return 0;
        }

        $this->db()->transaction(function () use ($disk, $oldPrefix, $newPrefix, $matches) {
            foreach ($matches as $row) {
                $newKey = $newPrefix . substr($row->path, strlen($oldPrefix));
                $newHash = $this->pathHash($newKey);
                $this->table('fluxfiles_directories')
                    ->where('disk', $disk)->where('path_hash', $row->path_hash)->delete();
                // Destination-existing wins on collision (matches the JSON backend's
                // `$dirs + $updated` array-union semantics — left operand wins).
                $existsAtDest = $this->table('fluxfiles_directories')
                    ->where('disk', $disk)->where('path_hash', $newHash)->exists();
                if (!$existsAtDest) {
                    $this->table('fluxfiles_directories')->insert([
                        'disk' => $disk, 'path' => $newKey, 'path_hash' => $newHash, 'created_at' => time(),
                    ]);
                }
            }
        });

        return count($matches);
    }

    public function deleteDirPrefix(string $disk, string $prefix): int
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '' || $prefix === '_fluxfiles') {
            return 0;
        }
        $like = $this->escapeLike($prefix) . '/%';
        return $this->table('fluxfiles_directories')
            ->where('disk', $disk)
            ->where(function ($q) use ($prefix, $like) {
                $q->where('path', $prefix)->orWhereRaw('path LIKE ?' . self::LIKE_ESCAPE, [$like]);
            })
            ->delete();
    }

    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $prefix = trim($pathPrefix, '/');
        $cap = max($limit * 20, 500);
        $likeQ = '%' . $this->escapeLike($query) . '%';

        $q = $this->table('fluxfiles_directories')->where('disk', $disk);
        if ($prefix !== '') {
            $like = $this->escapeLike($prefix) . '/%';
            $q->where(function ($w) use ($prefix, $like) {
                $w->where('path', $prefix)->orWhereRaw('path LIKE ?' . self::LIKE_ESCAPE, [$like]);
            });
        }
        $q->whereRaw('LOWER(path) LIKE LOWER(?)' . self::LIKE_ESCAPE, [$likeQ]);
        $rows = $q->orderBy('path', 'asc')->limit($cap)->get(['path', 'created_at']);

        $results = [];
        foreach ($rows as $row) {
            $dirKey = $row->path;
            if ($this->isReservedPath($dirKey)) {
                continue;
            }
            if (!$includeHidden && $this->isHiddenPath($dirKey)) {
                continue;
            }
            $results[] = [
                'dir_key' => $dirKey,
                'name'    => basename($dirKey),
                'created' => $row->created_at !== null ? (int) $row->created_at : null,
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
     * readAudit() narrowed in SQL — the audit table has no rotation cap, so a
     * caller that only needs a window (a page of /audit, a fixity window) must not
     * load the whole table. Twin of core's Db\DbMetadataHandler::readAuditWindow().
     *
     * @param ?int $since Only rows with created_at >= $since.
     * @param ?int $limit Only the NEWEST $limit matching rows (still oldest-first).
     */
    public function readAuditWindow(string $disk, ?int $since = null, ?int $limit = null, ?string $userId = null): array
    {
        // Raw SQL bypasses the query builder, so add the connection's table prefix (and quoting) by hand.
        $auditTable = $this->db()->getQueryGrammar()->wrapTable('fluxfiles_audit_log');
        [$sql, $params] = $this->auditWindowSql($auditTable, $disk, $since, $limit, $userId);
        $rows = array_map(static fn ($r) => (array) $r, $this->db()->select($sql, $params));
        return $this->auditWindowEntries($rows, $disk, $limit);
    }

    public function audit(string $disk, string $action, array $context = []): void
    {
        $row = [
            'disk'       => $disk,
            'owner'      => $context['user_id'] ?? null,
            'action'     => $action,
            'file_key'   => $context['file_key'] ?? null,
            'ip'         => $context['ip'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'detail'     => $context['detail'] ?? null,
            'created_at' => time(),
        ];
        $key = \FluxFiles\AuditChain::key();
        if ($key === null) {
            try {
                $this->table('fluxfiles_audit_log')->insert($row);
            } catch (\Throwable $e) {
                // Silent fail — matches StorageMetadataHandler::audit()'s best-effort contract.
            }
            return;
        }
        // Tamper-evident chain (core's DbMetadataHandler::audit() twin): advance the
        // per-disk head under lockForUpdate so concurrent writers can't fork it; the
        // first write per disk races on inserting the head row, so retry once.
        $entry = [
            'ts' => $row['created_at'], 'action' => $action, 'user_id' => $row['owner'],
            'file_key' => $row['file_key'], 'ip' => $row['ip'],
            'user_agent' => $row['user_agent'], 'detail' => $row['detail'],
        ];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->db()->transaction(function () use ($disk, $row, $entry, $key) {
                    $head = $this->table('fluxfiles_audit_chain_head')->where('disk', $disk)->lockForUpdate()->value('head_hash');
                    $prev = $head;
                    if ($prev === null) {
                        $prev = $this->table('fluxfiles_audit_log')->where('disk', $disk)
                            ->whereNotNull('entry_hash')->orderBy('id', 'desc')->value('entry_hash');
                    }
                    $prev = is_string($prev) ? $prev : '';
                    $hash = \FluxFiles\AuditChain::hash($key, $prev, $entry);
                    $this->table('fluxfiles_audit_log')->insert($row + ['prev_hash' => $prev, 'entry_hash' => $hash]);
                    if ($head === null) {
                        $this->table('fluxfiles_audit_chain_head')->insert(['disk' => $disk, 'head_hash' => $hash]);
                    } else {
                        $this->table('fluxfiles_audit_chain_head')->where('disk', $disk)->update(['head_hash' => $hash]);
                    }
                });
                return;
            } catch (\Throwable $e) {
                // retry once, then give up silently (best-effort contract)
            }
        }
    }

    public function purgeAuditBefore(string $disk, int $beforeTs): array
    {
        $removed = $this->table('fluxfiles_audit_log')
            ->where('disk', $disk)->where('created_at', '<', $beforeTs)->delete();
        return ['archives_deleted' => 0, 'live_lines_removed' => $removed];
    }

    public function insertAuditEntries(string $disk, array $entries): int
    {
        $rows = [];
        foreach ($entries as $entry) {
            $detail = $entry['detail'] ?? null;
            if ($detail !== null && !is_scalar($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $rows[] = [
                'disk'         => $disk,
                'owner'        => $entry['user_id'] ?? null,
                'action'       => $entry['action'],
                'file_key'     => $entry['file_key'] ?? null,
                'ip'           => $entry['ip'] ?? null,
                'user_agent'   => $entry['user_agent'] ?? null,
                'detail'       => $detail,
                'created_at'   => $entry['created_at'],
                'content_hash' => $entry['content_hash'],
                'prev_hash'    => $entry['prev_hash'] ?? null,
                'entry_hash'   => $entry['entry_hash'] ?? null,
            ];
        }
        if ($rows === []) {
            return 0;
        }
        return $this->table('fluxfiles_audit_log')->insertOrIgnore($rows);
    }

    public function existingAuditContentHashes(string $disk, array $contentHashes): array
    {
        if ($contentHashes === []) {
            return [];
        }
        return $this->table('fluxfiles_audit_log')
            ->where('disk', $disk)
            ->whereIn('content_hash', $contentHashes)
            ->pluck('content_hash')
            ->all();
    }

    // ---------------------------------------------------------------------
    // Trash index (soft-delete)
    // ---------------------------------------------------------------------

    public function allTrash(string $disk): array
    {
        $rows = $this->table('fluxfiles_trash')->where('disk', $disk)->get();
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[$row['id']] = $this->rowToTrashEntry($row);
        }
        return $out;
    }

    public function getTrash(string $disk, string $id): ?array
    {
        $row = $this->table('fluxfiles_trash')->where('disk', $disk)->where('id', $id)->first();
        return $row === null ? null : $this->rowToTrashEntry((array) $row);
    }

    public function addTrash(string $disk, string $id, array $entry): void
    {
        $row = [
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

        $this->table('fluxfiles_trash')->updateOrInsert(
            ['disk' => $disk, 'id' => $id],
            $row
        );
    }

    public function removeTrash(string $disk, string $id): void
    {
        $this->table('fluxfiles_trash')->where('disk', $disk)->where('id', $id)->delete();
    }

    // ---------------------------------------------------------------------
    // Legal hold (retention) — direct port of FluxFiles\Db\DbMetadataHandler's
    // legal_holds table, same field shape. Free/core storage primitives;
    // enforcement lives in FileManager::assertNoActiveHold(), not here.
    // ---------------------------------------------------------------------

    public function allHolds(string $disk): array
    {
        $rows = $this->table('fluxfiles_legal_holds')->where('disk', $disk)->get();
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[$row['id']] = $this->rowToHoldEntry($row);
        }
        return $out;
    }

    public function getHold(string $disk, string $id): ?array
    {
        $row = $this->table('fluxfiles_legal_holds')->where('disk', $disk)->where('id', $id)->first();
        return $row === null ? null : $this->rowToHoldEntry((array) $row);
    }

    public function addHold(string $disk, string $id, array $entry): void
    {
        $row = [
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
        // The placing token's scope (a narrower token may not release the hold).
        // The 2026_10_23 migration adds the column; until it has run the hold is
        // still written — without the scope — rather than refused.
        if (array_key_exists('placed_by_scope', $entry)) {
            $row['placed_by_scope'] = $entry['placed_by_scope'];
        }
        try {
            $this->table('fluxfiles_legal_holds')->updateOrInsert(['disk' => $disk, 'id' => $id], $row);
        } catch (\Throwable $e) {
            if (!array_key_exists('placed_by_scope', $row)) {
                throw $e;
            }
            error_log('FluxFiles: fluxfiles_legal_holds.placed_by_scope is missing — run `php artisan migrate` (' . $e->getMessage() . ')');
            unset($row['placed_by_scope']);
            $this->table('fluxfiles_legal_holds')->updateOrInsert(['disk' => $disk, 'id' => $id], $row);
        }
    }

    public function countActiveHolds(string $disk): int
    {
        return $this->table('fluxfiles_legal_holds')
            ->where('disk', $disk)->whereNull('released_at')->count();
    }

}

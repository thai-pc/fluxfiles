<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use FluxFiles\DiskManager;
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
        $row = $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)
            ->where('path_hash', $this->pathHash($key))
            ->first();
        return $row === null ? null : (array) $row;
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
                $q->where('path', $prefix)->orWhere('path', 'like', $like);
            })
            ->delete();
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $like = $this->escapeLike($oldPrefix) . '/%';
        $candidates = $this->table('fluxfiles_file_metadata')
            ->where('disk', $disk)
            ->where(function ($q) use ($oldPrefix, $like) {
                $q->where('path', $oldPrefix)->orWhere('path', 'like', $like);
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

        $q = $this->table('fluxfiles_file_metadata')->where('disk', $disk);
        if ($prefix !== '') {
            $like = $this->escapeLike($prefix) . '/%';
            $q->where(function ($w) use ($prefix, $like) {
                $w->where('path', $prefix)->orWhere('path', 'like', $like);
            });
        }
        $q->where(function ($w) use ($likeQ) {
            $w->whereRaw('LOWER(path) LIKE LOWER(?)', [$likeQ])
                ->orWhereRaw('LOWER(title) LIKE LOWER(?)', [$likeQ])
                ->orWhereRaw('LOWER(alt_text) LIKE LOWER(?)', [$likeQ])
                ->orWhereRaw('LOWER(caption) LIKE LOWER(?)', [$likeQ])
                ->orWhereRaw('LOWER(tags) LIKE LOWER(?)', [$likeQ]);
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

    /** No-op: metadata already lives in the fluxfiles_file_metadata table. */
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
        $exists = $this->table('fluxfiles_directories')
            ->where('disk', $disk)->where('path_hash', $hash)->exists();
        if ($exists) {
            return;
        }
        $this->table('fluxfiles_directories')->insert([
            'disk' => $disk, 'path' => $dirKey, 'path_hash' => $hash, 'created_at' => time(),
        ]);
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
                $q->where('path', $oldPrefix)->orWhere('path', 'like', $like);
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
                $q->where('path', $prefix)->orWhere('path', 'like', $like);
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
                $w->where('path', $prefix)->orWhere('path', 'like', $like);
            });
        }
        $q->whereRaw('LOWER(path) LIKE LOWER(?)', [$likeQ]);
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
        $q = $this->table('fluxfiles_audit_log')->where('disk', $disk);
        if ($userId !== null) {
            $q->where('owner', $userId);
        }
        $rows = $q->orderBy('id', 'asc')->get();

        $entries = [];
        foreach ($rows as $row) {
            $row = (array) $row;
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
            $this->table('fluxfiles_audit_log')->insert([
                'disk'       => $disk,
                'owner'      => $context['user_id'] ?? null,
                'action'     => $action,
                'file_key'   => $context['file_key'] ?? null,
                'ip'         => $context['ip'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'detail'     => $context['detail'] ?? null,
                'created_at' => time(),
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

        $this->table('fluxfiles_legal_holds')->updateOrInsert(
            ['disk' => $disk, 'id' => $id],
            $row
        );
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
        return $this->table('fluxfiles_legal_holds')
            ->where('disk', $disk)->whereNull('released_at')->count();
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
     * findOverlappingHold() — kept as a plain PHP scan (not SQL LIKE) so all three
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

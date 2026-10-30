<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Metadata stored directly in the user's own storage (S3/R2/Local) — no SQLite.
 *
 * - S3/R2: Object Metadata (x-amz-meta-*) + index file _fluxfiles/index.json
 * - Local: Sidecar at _fluxfiles/meta/{key}.json + index file _fluxfiles/index.json
 * - Audit: _fluxfiles/audit.jsonl
 */
class StorageMetadataHandler implements MetadataRepositoryInterface
{
    private const INDEX_KEY = '_fluxfiles/index.json';
    private const DIRS_KEY  = '_fluxfiles/dirs.json';
    private const TRASH_KEY = '_fluxfiles/trash.json';
    private const HOLDS_KEY = '_fluxfiles/holds.json';
    private const AUDIT_KEY = '_fluxfiles/audit.jsonl';
    private const AUDIT_ARCHIVE_DIR = '_fluxfiles/audit/archive/';
    private const MAX_AUDIT_BYTES = 5 * 1024 * 1024; // 5MB rotation threshold
    private const AUDIT_KEEP_LINES = 5000; // Keep last N entries after rotation

    /**
     * Flysystem write config for FluxFiles' own bookkeeping under `_fluxfiles/`
     * (sidecars, index, audit, trash/hold manifests, fixity…). A native-AWS disk
     * marked `visibility=public` defaults every write to a public-read ACL
     * (DiskManager), which would publish the audit log and manifests at a
     * predictable key. The S3 adapter takes an explicit `ACL` option over the
     * filesystem default; the local and SFTP adapters ignore the key, so their
     * file modes are untouched (a `visibility` option would chmod them 0600, and
     * SFTP reports a refused chmod as a failed write). Disks that send no ACL at
     * all (private AWS, R2/MinIO) strip it in DiskManager's middleware.
     */
    public const INTERNAL_WRITE = ['ACL' => 'private'];

    private DiskManager $diskManager;

    /** @var array<string, resource> Active file locks keyed by disk name */
    private array $indexLocks = [];
    private array $auditLocks = [];

    /**
     * Index lock nesting per disk. While it is held, the index is read once and
     * kept here, and saveIndex() only updates this copy — the file is written
     * once, when the outermost holder releases. Nothing else can write the index
     * meanwhile (we hold its lock), so the copy is authoritative.
     *
     * @var array<string,int>
     */
    private array $indexDepth = [];
    /** @var array<string,array> */
    private array $heldIndex = [];
    /** @var array<string,bool> */
    private array $heldIndexDirty = [];
    /**
     * Same held-copy treatment for dirs.json and trash.json: a bulk operation
     * (extract, restore of a folder, empty-trash, retention) otherwise rewrote
     * them once per file. Same lock, a dirty flag, flushed on the outermost end.
     *
     * @var array<string,array>
     */
    private array $heldDirs = [];
    /** @var array<string,bool> */
    private array $heldDirsDirty = [];
    /** @var array<string,array> */
    private array $heldTrash = [];
    /** Disks whose dirs.json was last read in the legacy list shape (re-written as a map on the next track). */
    private array $dirsLegacyShape = [];
    /** @var array<string,bool> */
    private array $heldTrashDirty = [];

    public function __construct(DiskManager $diskManager)
    {
        $this->diskManager = $diskManager;
    }

    public function get(string $disk, string $key): ?array
    {
        if ($this->isS3Compatible($disk)) {
            return $this->getFromS3($disk, $key);
        }
        return $this->getFromLocal($disk, $key);
    }

    public function save(string $disk, string $key, array $data): void
    {
        // Locked so a read-merge-write against the same key from two concurrent
        // requests can't interleave and lose one side's fields (acquireIndexLock is
        // re-entrant-safe, so the nested updateIndex() call below sharing the lock
        // is fine — see its own docblock).
        $this->acquireIndexLock($disk);
        try {
            // Merge with existing so partial updates (e.g. {uploaded_by} right after upload,
            // or {title, alt_text} from the metadata edit form) don't wipe unrelated fields.
            $existing = $this->get($disk, $key) ?? [];
            $merged = array_merge($existing, $data);

            if ($this->isS3Compatible($disk)) {
                $this->saveToS3($disk, $key, $merged);
            } else {
                $this->saveToLocal($disk, $key, $merged);
            }
            $this->updateIndex($disk, $key, $merged);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /**
     * Add or update only the searchable FluxFiles index without changing the
     * source object or creating local sidecar metadata.
     */
    public function indexFile(string $disk, string $key, array $data, bool $overwrite = false): bool
    {
        $this->acquireIndexLock($disk);
        try {
            $index = $this->loadIndex($disk);
            if (!$overwrite && isset($index[$key])) {
                return false;
            }

            $existing = $index[$key] ?? [];
            $index[$key] = array_merge($existing, [
                'title' => $data['title'] ?? $existing['title'] ?? null,
                'alt_text' => $data['alt_text'] ?? $existing['alt_text'] ?? null,
                'caption' => $data['caption'] ?? $existing['caption'] ?? null,
                'tags' => $data['tags'] ?? $existing['tags'] ?? null,
                'uploaded_by' => $data['uploaded_by'] ?? $existing['uploaded_by'] ?? null,
                'mime' => $data['mime'] ?? $existing['mime'] ?? null,
                'width' => $data['width'] ?? $existing['width'] ?? null,
                'height' => $data['height'] ?? $existing['height'] ?? null,
                // size + modified let search results sort by size/date, not just name.
                'size' => $data['size'] ?? $existing['size'] ?? null,
                'modified' => $data['modified'] ?? $existing['modified'] ?? null,
                // created is the immutable first-seen time (existing wins on re-save).
                'created' => $existing['created'] ?? $data['created'] ?? null,
            ]);

            if (isset($data['file_hash'])) {
                $index[$key]['file_hash'] = $data['file_hash'];
            }

            $this->saveIndex($disk, $index);
            return true;
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /**
     * Carry the complete index entry of $srcKey to $dstKey verbatim (hash, mime,
     * size, dimensions, created/modified, and the exact null-vs-"" of the text
     * fields). A rename/move/copy keeps the bytes, so every one of those still
     * holds; save() alone only carries the sidecar fields and used to leave the
     * destination with blanks. No-op when the source has no index entry.
     */
    public function copyIndexEntry(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $entry = $this->loadIndex($srcDisk)[$srcKey] ?? null;
        if (!is_array($entry)) {
            return;
        }
        $this->acquireIndexLock($dstDisk);
        try {
            $index = $this->loadIndex($dstDisk);
            $index[$dstKey] = $entry;
            $this->saveIndex($dstDisk, $index);
        } finally {
            $this->releaseIndexLock($dstDisk);
        }
    }

    public function delete(string $disk, string $key): void
    {
        if ($this->isS3Compatible($disk)) {
            // Object metadata disappears with the object; just drop the index entry.
        } else {
            $fs = $this->diskManager->disk($disk);
            foreach ([$this->sidecarPath($key), $this->legacySidecarPath($key)] as $p) {
                try {
                    if ($fs->fileExists($p)) {
                        $fs->delete($p);
                    }
                } catch (\Throwable $e) {
                    // best-effort
                }
            }
        }
        $this->removeFromIndex($disk, $key);
    }

    public function deleteChildren(string $disk, string $prefix): int
    {
        // One index write for the whole folder, not one per child.
        return $this->withIndexBatch($disk, function () use ($disk, $prefix): int {
            $index = $this->loadIndex($disk);
            $count = 0;
            foreach (array_keys($index) as $k) {
                // loadIndex() can hand back an int key for a decimal-integer-looking
                // file name (PHP array-key coercion, see loadIndex()'s docblock).
                $k = (string) $k;
                if ($k === $prefix || strpos($k, $prefix . '/') === 0) {
                    $this->delete($disk, $k);
                    $count++;
                }
            }
            return $count;
        });
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $this->acquireIndexLock($disk);
        try {
            $index = $this->loadIndex($disk);
            $count = 0;
            $updated = [];
            foreach ($index as $k => $meta) {
                // loadIndex() can hand back an int key for a decimal-integer-looking
                // file name (PHP array-key coercion, see loadIndex()'s docblock).
                $k = (string) $k;
                if ($k === $oldPrefix || strpos($k, $oldPrefix . '/') === 0) {
                    $newKey = $newPrefix . substr($k, strlen($oldPrefix));
                    $updated[$newKey] = $meta;
                    unset($index[$k]);
                    // Move the sidecar (new location, and legacy if present) for local disks.
                    if (!$this->isS3Compatible($disk)) {
                        $fs = $this->diskManager->disk($disk);
                        $moves = [
                            [$this->sidecarPath($k), $this->sidecarPath($newKey)],
                            [$this->legacySidecarPath($k), $this->sidecarPath($newKey)],
                        ];
                        foreach ($moves as [$from, $to]) {
                            try {
                                if ($fs->fileExists($from) && !$fs->fileExists($to)) {
                                    $dir = dirname($to);
                                    if ($dir !== '.' && !$fs->directoryExists($dir)) {
                                        $fs->createDirectory($dir);
                                    }
                                    $fs->move($from, $to);
                                }
                            } catch (\Throwable $e) {
                                // Silent
                            }
                        }
                    }
                    $count++;
                }
            }
            if ($count > 0) {
                $index = array_merge($index, $updated);
                $this->saveIndex($disk, $index);
            }
            return $count;
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    public function getBulk(string $disk, array $keys): array
    {
        $result = [];
        $index = $this->loadIndex($disk);
        foreach ($keys as $key) {
            $meta = $index[$key] ?? null;
            if ($meta !== null) {
                unset($meta['file_hash']);
                $result[$key] = $meta;
            } else {
                // Fallback: fetch from S3/Local for files not in index
                $m = $this->get($disk, $key);
                $result[$key] = $m;
            }
        }
        return $result;
    }

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $index = $this->loadIndex($disk);
        $prefix = trim($pathPrefix, '/');
        $q = mb_strtolower($query);
        $qAscii = preg_match('/[\x80-\xFF]/', $q) !== 1;
        $results = [];

        foreach ($index as $fileKey => $meta) {
            // loadIndex() can hand back an int key for a decimal-integer-looking
            // file name (PHP array-key coercion, see loadIndex()'s docblock).
            $fileKey = (string) $fileKey;
            // Never surface metadata sidecars or image variants as search hits.
            if ($this->isReservedPath($fileKey)) {
                continue;
            }
            // Dotfiles (.env, .gitignore, …) are hidden from search unless opted in.
            if (!$includeHidden && $this->isHiddenPath($fileKey)) {
                continue;
            }
            if ($prefix !== '' && $fileKey !== $prefix && strpos($fileKey, $prefix . '/') !== 0) {
                continue;
            }
            $searchable = implode(' ', array_filter([
                $fileKey,
                $meta['title'] ?? '',
                $meta['alt_text'] ?? '',
                $meta['caption'] ?? '',
                $meta['tags'] ?? '',
            ]));
            // ASCII query: a plain stripos() hit is always a real hit; only a miss on
            // a haystack with non-ASCII bytes needs the full mb_strtolower() (it
            // folds e.g. the Kelvin sign to "k"), so ASCII-only rows skip it.
            $hit = $qAscii && stripos($searchable, $q) !== false;
            if (!$hit && (!$qAscii || preg_match('/[\x80-\xFF]/', $searchable) === 1)) {
                $hit = strpos(mb_strtolower($searchable), $q) !== false;
            }
            if ($hit) {
                $row = array_merge(['file_key' => $fileKey], $meta);
                unset($row['file_hash']);
                $row['title_hl'] = $this->highlight($row['title'] ?? '', $query);
                $row['alt_hl'] = $this->highlight($row['alt_text'] ?? '', $query);
                $row['caption_hl'] = $this->highlight($row['caption'] ?? '', $query);
                $row['tags_hl'] = $this->highlight($row['tags'] ?? '', $query);
                $results[] = $row;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }
        return $results;
    }

    // ---------------------------------------------------------------------
    // Directory index (folder search)
    // ---------------------------------------------------------------------

    /**
     * True when any path segment is a reserved namespace (metadata sidecars in
     * _fluxfiles/ or image variants in _variants/). These must never enter the
     * folder index or surface in folder search at any depth.
     *
     * Delegates to FileManager::isReservedKey() — the one case-insensitive
     * implementation of the rule (see its docblock for why the fold matters).
     */
    private function isReservedPath(string $key): bool
    {
        return FileManager::isReservedKey($key);
    }

    /**
     * True when any path segment is a dotfile (starts with '.', e.g. .env,
     * .gitignore, or a file inside a .git/ folder). Such paths are hidden from
     * search/folder-search unless the caller opts in (see the $includeHidden args).
     */
    private function isHiddenPath(string $key): bool
    {
        foreach (explode('/', trim($key, '/')) as $seg) {
            if ($seg !== '' && $seg[0] === '.') {
                return true;
            }
        }
        return false;
    }

    public function trackDir(string $disk, string $dirKey): void
    {
        $dirKey = trim($dirKey, '/');
        if ($dirKey === '' || $dirKey === '.' || $this->isReservedPath($dirKey)) {
            return;
        }

        $this->acquireIndexLock($disk);
        try {
            $dirs = $this->loadDirsIndex($disk);
            if (!array_key_exists($dirKey, $dirs)) {
                $dirs[$dirKey] = time(); // record the folder's created time once
                $this->saveDirsIndex($disk, $dirs);
            } elseif (!empty($this->dirsLegacyShape[$disk])) {
                $this->saveDirsIndex($disk, $dirs); // one-time migration of a legacy list-shaped file
            }
        } finally {
            $this->releaseIndexLock($disk);
        }
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

        $parts = explode('/', $dir);
        $acc = [];

        $this->acquireIndexLock($disk);
        try {
            $dirs = $this->loadDirsIndex($disk);
            $changed = false;
            foreach ($parts as $p) {
                if ($p === '' || $p === '.' || $p === '..') continue;
                $acc[] = $p;
                $d = implode('/', $acc);
                if ($this->isReservedPath($d)) continue;
                if (!array_key_exists($d, $dirs)) {
                    $dirs[$d] = time();
                    $changed = true;
                }
            }
            if ($changed || !empty($this->dirsLegacyShape[$disk])) {
                $this->saveDirsIndex($disk, $dirs);
            }
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /**
     * Folder created timestamps (our own metadata, so it works on S3/R2 prefixes
     * too where storage has no folder mtime). Returns dirKey => ?int.
     *
     * @return array<string,?int>
     */
    public function dirsCreated(string $disk): array
    {
        return $this->loadDirsIndex($disk);
    }

    public function renameDirPrefix(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = trim($oldPrefix, '/');
        $newPrefix = trim($newPrefix, '/');
        if ($oldPrefix === '' || $oldPrefix === '_fluxfiles') return 0;

        $this->acquireIndexLock($disk);
        try {
            $dirs = $this->loadDirsIndex($disk);
            $count = 0;
            $updated = [];
            foreach ($dirs as $k => $_true) {
                if ($k === $oldPrefix || str_starts_with($k, $oldPrefix . '/')) {
                    $newKey = $newPrefix . substr($k, strlen($oldPrefix));
                    $updated[$newKey] = $_true; // preserve the folder's created time
                    unset($dirs[$k]);
                    $count++;
                }
            }
            if ($count > 0) {
                $dirs = $dirs + $updated;
                $this->saveDirsIndex($disk, $dirs);
            }
            return $count;
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    public function deleteDirPrefix(string $disk, string $prefix): int
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '' || $prefix === '_fluxfiles') return 0;

        $this->acquireIndexLock($disk);
        try {
            $dirs = $this->loadDirsIndex($disk);
            $count = 0;
            foreach (array_keys($dirs) as $k) {
                if ($k === $prefix || str_starts_with($k, $prefix . '/')) {
                    unset($dirs[$k]);
                    $count++;
                }
            }
            if ($count > 0) {
                $this->saveDirsIndex($disk, $dirs);
            }
            return $count;
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /**
     * Search folders across disk using directory index.
     * Returns rows: { dir_key, name }
     */
    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array
    {
        $dirs = $this->loadDirsIndex($disk);
        $prefix = trim($pathPrefix, '/');
        $q = mb_strtolower($query);
        $results = [];

        foreach ($dirs as $dirKey => $created) {
            // Defend against indexes polluted before reserved dirs were filtered.
            if ($this->isReservedPath($dirKey)) {
                continue;
            }
            // Hidden folders (.git/, …) are excluded from folder search unless opted in.
            if (!$includeHidden && $this->isHiddenPath($dirKey)) {
                continue;
            }
            if ($prefix !== '' && $dirKey !== $prefix && strpos($dirKey, $prefix . '/') !== 0) {
                continue;
            }
            $name = basename($dirKey);
            $searchable = $dirKey . ' ' . $name;
            if (strpos(mb_strtolower($searchable), $q) !== false) {
                $results[] = [
                    'dir_key' => $dirKey,
                    'name'    => $name,
                    'created' => $created !== null ? (int) $created : null,
                ];
                if (count($results) >= $limit) break;
            }
        }

        return $results;
    }

    private function highlight(string $text, string $query): ?string
    {
        if ($text === '' || $query === '') {
            return null;
        }
        // Escape HTML first to prevent XSS, then apply highlight marks
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $q = preg_quote(htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/');
        return preg_replace('/(' . $q . ')/iu', '<mark>$1</mark>', $escaped) ?: null;
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

    public function saveHash(string $disk, string $key, string $hash): void
    {
        $this->acquireIndexLock($disk);
        try {
            $index = $this->loadIndex($disk);
            $existing = $index[$key] ?? [];
            $existing['file_hash'] = $hash;
            $index[$key] = $existing;
            $this->saveIndex($disk, $index);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    public function findByHash(string $disk, string $hash, string $pathPrefix = '', ?string $ownerUserId = null): ?array
    {
        $index = $this->loadIndex($disk);
        $prefix = trim($pathPrefix, '/');
        foreach ($index as $fileKey => $meta) {
            // loadIndex() can hand back an int key for a decimal-integer-looking
            // file name (PHP array-key coercion, see loadIndex()'s docblock).
            $fileKey = (string) $fileKey;
            if (($meta['file_hash'] ?? '') !== $hash) {
                continue;
            }
            // Never surface internal paths as duplicates — they're hidden from
            // listing, so the user would see a "file already exists" message
            // pointing at a file they can't see.
            if ($this->isReservedPath($fileKey)) {
                continue;
            }
            // Never leak a file outside the caller's path scope — otherwise the
            // duplicate response reveals existence and metadata of files the
            // user cannot list or access.
            if ($prefix !== '' && $fileKey !== $prefix && strpos($fileKey, $prefix . '/') !== 0) {
                continue;
            }
            // When owner_only is in effect, only the original uploader can be
            // shown the duplicate; everyone else uploads a fresh copy.
            if ($ownerUserId !== null) {
                $owner = $meta['uploaded_by'] ?? null;
                if ($owner !== null && $owner !== $ownerUserId) {
                    continue;
                }
            }
            $row = ['file_key' => $fileKey];
            foreach (['title', 'alt_text', 'caption', 'tags'] as $k) {
                if (isset($meta[$k])) {
                    $row[$k] = $meta[$k];
                }
            }
            return $row;
        }
        return null;
    }

    /**
     * No-op: metadata is already stored in S3 object metadata.
     */
    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void
    {
        // Already handled in save()
    }

    public function readAudit(string $disk, ?string $userId = null): array
    {
        return $this->readAuditWindow($disk, null, null, $userId);
    }

    /**
     * Mirrors DbMetadataHandler::readAuditWindow() (same contract, so a caller
     * can use either backend). The live log is already capped by rotation, so
     * here $since/$limit are plain filters.
     *
     * @param ?int $since Only entries with created_at >= $since.
     * @param ?int $limit Only the NEWEST $limit matching entries (still oldest-first).
     */
    public function readAuditWindow(string $disk, ?int $since = null, ?int $limit = null, ?string $userId = null): array
    {
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists(self::AUDIT_KEY)) {
            return [];
        }
        try {
            $content = $fs->read(self::AUDIT_KEY);
            $lines = array_filter(explode("\n", $content));
            $entries = [];
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) continue;
                $ctx = $row['context'] ?? [];
                if ($userId !== null && ($ctx['user_id'] ?? '') !== $userId) {
                    continue;
                }
                if ($since !== null && (int) ($row['ts'] ?? 0) < $since) {
                    continue;
                }
                $entries[] = [
                    'user_id'   => $ctx['user_id'] ?? '',
                    'action'    => $row['action'] ?? '',
                    'disk'      => $disk,
                    'file_key'  => $ctx['file_key'] ?? '',
                    'ip'        => $ctx['ip'] ?? null,
                    'user_agent' => $ctx['user_agent'] ?? null,
                    'detail'    => $ctx['detail'] ?? null,
                    'created_at' => $row['ts'] ?? 0,
                    'prev_hash' => $row['prev'] ?? null,
                    'entry_hash' => $row['hash'] ?? null,
                ];
            }
            if ($limit !== null) {
                return $limit > 0 ? array_slice($entries, -$limit) : [];
            }
            return $entries;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function audit(string $disk, string $action, array $context = []): void
    {
        // JSON_INVALID_UTF8_SUBSTITUTE is load-bearing, not tidiness: $context
        // carries request-controlled strings (User-Agent, IP, detail), and one
        // invalid byte made json_encode() return false. `false . "\n"` is just
        // "\n", so `User-Agent: A\xFFB` wrote a blank line and made any
        // destructive action unloggable while it still succeeded.
        $row = ['ts' => time(), 'action' => $action, 'context' => $context];
        if (json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE) === false) {
            // Nothing request-controlled can reach here any more (recursion and
            // NAN/INF are the remaining causes), but an audit entry we cannot
            // encode must still leave a trace rather than a blank line.
            $row['context'] = ['encode_error' => json_last_error_msg()];
            if (json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE) === false) {
                $row = ['ts' => $row['ts'], 'action' => 'audit_encode_failed', 'context' => []];
            }
        }
        $fs = $this->diskManager->disk($disk);
        // Unlocked read-modify-write here would drop entries under concurrent
        // writers (two requests both read the same content, each appends one line,
        // the second write clobbers the first's line) — same race updateIndex()
        // already guards against with acquireIndexLock(). Uses its own lock file
        // so a busy audit log can't stall unrelated metadata index writes.
        $this->acquireAuditLock($disk);
        try {
            $content = '';
            if ($fs->fileExists(self::AUDIT_KEY)) {
                $content = $fs->read(self::AUDIT_KEY);

                // Rotate if audit log exceeds size threshold
                if (strlen($content) > self::MAX_AUDIT_BYTES) {
                    $lines = array_filter(explode("\n", $content));
                    $kept = array_slice($lines, -self::AUDIT_KEEP_LINES);
                    $dropped = array_slice($lines, 0, count($lines) - count($kept));
                    // Archive the dropped prefix before it's gone for good — a
                    // rotation must never be the only copy of compliance history.
                    if ($dropped !== []) {
                        $archiveKey = self::AUDIT_ARCHIVE_DIR . 'audit-' . time() . '-' . bin2hex(random_bytes(3)) . '.jsonl';
                        $fs->write($archiveKey, implode("\n", $dropped) . "\n", self::INTERNAL_WRITE);
                    }
                    $content = implode("\n", $kept) . "\n";
                }
            }
            // Tamper-evidence: chain onto the newest chained entry, computed under
            // the same lock as the append so two writers can't fork the chain.
            $key = AuditChain::key();
            if ($key !== null) {
                $prev = self::lastChainHash($content);
                $row['prev'] = $prev;
                $row['hash'] = AuditChain::hash($key, $prev, self::chainFields($row));
            }
            $entry = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($entry === false) {
                $entry = '{"ts":' . time() . ',"action":"audit_encode_failed","context":{}}';
            }
            $content .= $entry . "\n";
            $fs->write(self::AUDIT_KEY, $content, self::INTERNAL_WRITE);
        } catch (\Throwable $e) {
            // Silent fail
        } finally {
            $this->releaseAuditLock($disk);
        }
    }

    /** Hash of the newest CHAINED line in the live log ('' = start a new chain). */
    private static function lastChainHash(string $content): string
    {
        $lines = explode("\n", rtrim($content, "\n"));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] === '') {
                continue;
            }
            $r = json_decode($lines[$i], true);
            if (is_array($r) && is_string($r['hash'] ?? null) && $r['hash'] !== '') {
                return $r['hash'];
            }
        }
        return '';
    }

    /** @param array{ts:int,action:string,context:array} $row */
    private static function chainFields(array $row): array
    {
        $c = $row['context'];
        return [
            'ts' => $row['ts'], 'action' => $row['action'],
            'user_id' => $c['user_id'] ?? null, 'file_key' => $c['file_key'] ?? null,
            'ip' => $c['ip'] ?? null, 'user_agent' => $c['user_agent'] ?? null,
            'detail' => $c['detail'] ?? null,
        ];
    }

    /**
     * Every archived rotation file for a disk, parsed with the same row shape as
     * readAudit() — so callers can merge live + archived transparently.
     */
    public function readAuditArchive(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        $entries = [];
        try {
            if (!$fs->directoryExists(self::AUDIT_ARCHIVE_DIR)) {
                return [];
            }
            foreach ($fs->listContents(self::AUDIT_ARCHIVE_DIR, false) as $item) {
                if (!$item->isFile() || !str_ends_with($item->path(), '.jsonl')) {
                    continue;
                }
                $content = $fs->read($item->path());
                foreach (array_filter(explode("\n", $content)) as $line) {
                    $row = json_decode($line, true);
                    if (!is_array($row)) continue;
                    $ctx = $row['context'] ?? [];
                    $entries[] = [
                        'user_id'    => $ctx['user_id'] ?? '',
                        'action'     => $row['action'] ?? '',
                        'disk'       => $disk,
                        'file_key'   => $ctx['file_key'] ?? '',
                        'ip'         => $ctx['ip'] ?? null,
                        'user_agent' => $ctx['user_agent'] ?? null,
                        'detail'     => $ctx['detail'] ?? null,
                        'created_at' => $row['ts'] ?? 0,
                        'prev_hash'  => $row['prev'] ?? null,
                        'entry_hash' => $row['hash'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return $entries;
        }
        return $entries;
    }

    /**
     * Deletes archive files that are entirely older than $beforeTs, and trims the
     * live audit log of lines older than $beforeTs. Pure storage primitive — no
     * Claims/license awareness, that gating happens at the calling route since this
     * is destructive and per-disk (not per-tenant).
     *
     * @return array{archives_deleted:int, live_lines_removed:int}
     */
    public function purgeAuditBefore(string $disk, int $beforeTs): array
    {
        $fs = $this->diskManager->disk($disk);
        $archivesDeleted = 0;
        $liveLinesRemoved = 0;
        $this->acquireAuditLock($disk);
        try {
            if ($fs->directoryExists(self::AUDIT_ARCHIVE_DIR)) {
                foreach ($fs->listContents(self::AUDIT_ARCHIVE_DIR, false) as $item) {
                    if (!$item->isFile() || !str_ends_with($item->path(), '.jsonl')) {
                        continue;
                    }
                    $content = $fs->read($item->path());
                    $lines = array_filter(explode("\n", $content));
                    $allOld = true;
                    foreach ($lines as $line) {
                        $row = json_decode($line, true);
                        if (!is_array($row) || (int) ($row['ts'] ?? 0) >= $beforeTs) {
                            $allOld = false;
                            break;
                        }
                    }
                    if ($allOld) {
                        $fs->delete($item->path());
                        $archivesDeleted++;
                    }
                }
            }

            if ($fs->fileExists(self::AUDIT_KEY)) {
                $content = $fs->read(self::AUDIT_KEY);
                $lines = array_filter(explode("\n", $content));
                $kept = [];
                foreach ($lines as $line) {
                    $row = json_decode($line, true);
                    if (is_array($row) && (int) ($row['ts'] ?? 0) < $beforeTs) {
                        $liveLinesRemoved++;
                        continue;
                    }
                    $kept[] = $line;
                }
                if ($liveLinesRemoved > 0) {
                    $fs->write(self::AUDIT_KEY, $kept === [] ? '' : implode("\n", $kept) . "\n", self::INTERNAL_WRITE);
                }
            }
        } catch (\Throwable $e) {
            // Unlike audit()'s best-effort logging path, this is a destructive,
            // admin-only operation — a caller must be able to tell "purge failed"
            // from "purge succeeded, nothing matched the cutoff". Log for the
            // operator and surface a proper error instead of returning fake
            // success counts for a possibly-partial purge.
            error_log("FluxFiles audit purge failed on disk '{$disk}': " . $e->getMessage());
            throw new ApiException('Audit purge failed', 500, 'audit_purge_failed');
        } finally {
            $this->releaseAuditLock($disk);
        }

        return ['archives_deleted' => $archivesDeleted, 'live_lines_removed' => $liveLinesRemoved];
    }

    // --- Private ---

    private function isS3Compatible(string $disk): bool
    {
        $config = $this->diskManager->config($disk);
        return ($config['driver'] ?? '') === 's3';
    }

    private function getFromS3(string $disk, string $key): ?array
    {
        try {
            $client = $this->diskManager->s3Client($disk);
            $config = $this->diskManager->config($disk);
            $bucket = $config['bucket'] ?? '';
            $result = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            $meta = $result['Metadata'] ?? [];
            return $this->metaFromS3Headers($meta);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function saveToS3(string $disk, string $key, array $data): void
    {
        $client = $this->diskManager->s3Client($disk);
        $config = $this->diskManager->config($disk);
        $bucket = $config['bucket'] ?? '';

        $metadata = [
            'fm-title' => substr($data['title'] ?? '', 0, 1024),
            'fm-alt' => substr($data['alt_text'] ?? '', 0, 1024),
            'fm-caption' => substr($data['caption'] ?? '', 0, 1024),
            'fm-tags' => substr($data['tags'] ?? '', 0, 1024),
            'fm-uploaded-by' => substr((string) ($data['uploaded_by'] ?? ''), 0, 1024),
        ];

        // Metadata lives in the object's headers, so saving it is a self-copy with
        // MetadataDirective=REPLACE — which replaces ALL of them: without carrying
        // the content headers over, an image came back as binary/octet-stream, and
        // without an ACL a public AWS disk's object turned private (its URL 403s).
        $params = [
            'Bucket' => $bucket,
            'Key' => $key,
            // CopySource is a URL path: each key segment must be percent-encoded, or
            // a key with a space/`+`/`?` copies the wrong object or fails.
            'CopySource' => $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key))),
            'Metadata' => $metadata,
            'MetadataDirective' => 'REPLACE',
        ];
        try {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            foreach (['ContentType', 'ContentDisposition', 'ContentEncoding', 'ContentLanguage', 'CacheControl', 'Expires'] as $h) {
                if (!empty($head[$h])) {
                    $params[$h] = $head[$h];
                }
            }
        } catch (\Throwable $e) {
            // No head → the copy below fails the same way; nothing to carry over.
        }
        // Mirrors DiskManager: only a native AWS disk marked public writes public-read
        // (its client strips ACL for every other disk anyway).
        if (empty($config['endpoint']) && ($config['visibility'] ?? 'private') === 'public') {
            $params['ACL'] = 'public-read';
        }
        $client->copyObject($params);
    }

    private function metaFromS3Headers(array $meta): array
    {
        $uploadedBy = $meta['fm-uploaded-by'] ?? null;
        return [
            'title' => $meta['fm-title'] ?? null,
            'alt_text' => $meta['fm-alt'] ?? null,
            'caption' => $meta['fm-caption'] ?? null,
            'tags' => $meta['fm-tags'] ?? null,
            'uploaded_by' => ($uploadedBy === null || $uploadedBy === '') ? null : $uploadedBy,
        ];
    }

    private function getFromLocal(string $disk, string $key): ?array
    {
        $fs = $this->diskManager->disk($disk);
        $metaPath = $this->sidecarPath($key);
        if ($fs->fileExists($metaPath)) {
            return $this->decodeSidecar($fs, $metaPath);
        }

        // Backward compatibility: a legacy sidecar in the user namespace
        // ({key}.meta.json). Migrate it to the new protected location on first read.
        $legacy = $this->legacySidecarPath($key);
        if ($fs->fileExists($legacy)) {
            $data = $this->decodeSidecar($fs, $legacy);
            try {
                $this->writeSidecar($fs, $metaPath, $data ?? []);
                $fs->delete($legacy);
            } catch (\Throwable $e) {
                // best-effort migration; reading still succeeds
            }
            return $data;
        }
        return null;
    }

    private function saveToLocal(string $disk, string $key, array $data): void
    {
        $fs = $this->diskManager->disk($disk);
        $this->writeSidecar($fs, $this->sidecarPath($key), $data);
        // Remove any legacy sidecar left in the user namespace.
        $legacy = $this->legacySidecarPath($key);
        try {
            if ($fs->fileExists($legacy)) {
                $fs->delete($legacy);
            }
        } catch (\Throwable $e) {
            // best-effort cleanup
        }
    }

    /**
     * Where a local file's metadata sidecar lives. Sidecars are stored inside the
     * protected `_fluxfiles/` namespace, not the user's file namespace. A
     * `*.meta.json` filename is now a reserved name — FileManager::assertNotSystem()
     * rejects it on every write path (upload/rename/move/copy/extract), so a new
     * one can never be created there again. getFromLocal() below still reads (and
     * migrates) any legacy sidecar that predates that guard, for backward compat.
     */
    private function sidecarPath(string $key): string
    {
        return '_fluxfiles/meta/' . $key . '.json';
    }

    /**
     * Legacy sidecar location ({key}.meta.json next to the file) — read-only
     * fallback for files onboarded before `*.meta.json` became a reserved,
     * write-blocked filename shape. Only ever read + migrated, never written
     * fresh (see saveToLocal(), which only cleans one up after migration).
     */
    private function legacySidecarPath(string $key): string
    {
        return $key . '.meta.json';
    }

    /** @return array<string,mixed>|null */
    private function decodeSidecar($fs, string $path): ?array
    {
        try {
            $data = json_decode($fs->read($path), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function writeSidecar($fs, string $path, array $data): void
    {
        $dir = dirname($path);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }
        $fs->write($path, json_encode([
            'title' => $data['title'] ?? '',
            'alt_text' => $data['alt_text'] ?? '',
            'caption' => $data['caption'] ?? '',
            'tags' => $data['tags'] ?? '',
            'uploaded_by' => $data['uploaded_by'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), self::INTERNAL_WRITE);
    }

    /**
     * Acquire an exclusive lock for index operations — but ONLY for the local
     * driver, whose index lives on this server's filesystem. S3/R2 have no local
     * files, and an SFTP disk's `root` is a path on the REMOTE host (a local
     * mkdir/fopen on it would hit the app server's own filesystem — usually a
     * non-existent/unwritable `/var/www` — and falsely report "storage not
     * writable"). For those remote disks we skip the flock: index writes still
     * go through Flysystem (disk-aware), the lock is just best-effort and is
     * impossible to take locally anyway — same as S3 has always been.
     */
    /**
     * Re-entrant: nested holders (save() → updateIndex(), or a batch) only count.
     * Before this, the inner release dropped the outer holder's lock early.
     */
    private function acquireIndexLock(string $disk): void
    {
        $this->indexDepth[$disk] = ($this->indexDepth[$disk] ?? 0) + 1;
        if ($this->indexDepth[$disk] === 1) {
            try {
                $this->acquireLock($disk, 'index.lock', $this->indexLocks);
            } catch (\Throwable $e) {
                unset($this->indexDepth[$disk]);
                throw $e;
            }
        }
    }

    private function releaseIndexLock(string $disk): void
    {
        $depth = ($this->indexDepth[$disk] ?? 1) - 1;
        if ($depth > 0) {
            $this->indexDepth[$disk] = $depth;
            return;
        }
        unset($this->indexDepth[$disk]);
        try {
            $this->flushHeld($disk);
        } finally {
            unset(
                $this->heldIndex[$disk], $this->heldIndexDirty[$disk],
                $this->heldDirs[$disk], $this->heldDirsDirty[$disk],
                $this->heldTrash[$disk], $this->heldTrashDirty[$disk]
            );
            $this->releaseLock($disk, $this->indexLocks);
        }
    }

    /**
     * Write every held copy that changed. Each one is attempted even when an
     * earlier write throws (the first error is rethrown afterwards), so what
     * landed in memory is never silently dropped.
     */
    private function flushHeld(string $disk): void
    {
        $first = null;
        foreach ([
            [&$this->heldIndexDirty, &$this->heldIndex, fn (array $d) => $this->writeIndex($disk, $d)],
            [&$this->heldDirsDirty, &$this->heldDirs, fn (array $d) => $this->writeDirsIndex($disk, $d)],
            [&$this->heldTrashDirty, &$this->heldTrash, fn (array $d) => $this->saveTrashNow($disk, $d)],
        ] as [&$dirty, &$held, $write]) {
            if (empty($dirty[$disk])) {
                continue;
            }
            try {
                $write($held[$disk]);
                unset($dirty[$disk]);
            } catch (\Throwable $e) {
                $first ??= $e;
            }
        }
        if ($first !== null) {
            throw $first;
        }
    }

    /**
     * Write the held copies to storage now, keeping the lock. A long bulk
     * operation calls this every few hundred entries so a crash loses at most
     * one window and the lock isn't held across one giant final write.
     * No-op outside a batch.
     */
    public function checkpointIndexBatch(string $disk): void
    {
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            $this->flushHeld($disk);
        }
    }

    /**
     * Run several metadata writes for one disk as one index update: the index is
     * read once and written once, instead of once per call — an upload's hash +
     * metadata, or a rename's save + delete, each rewrote the whole disk-wide file.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withIndexBatch(string $disk, callable $fn)
    {
        $this->acquireIndexLock($disk);
        try {
            return $fn();
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /** Explicit form of withIndexBatch(); every begin needs an end (in a finally). */
    public function beginIndexBatch(string $disk): void
    {
        $this->acquireIndexLock($disk);
    }

    public function endIndexBatch(string $disk): void
    {
        $this->releaseIndexLock($disk);
    }

    private function acquireAuditLock(string $disk): void
    {
        $this->acquireLock($disk, 'audit.lock', $this->auditLocks);
    }

    private function releaseAuditLock(string $disk): void
    {
        $this->releaseLock($disk, $this->auditLocks);
    }

    /**
     * Shared file-lock plumbing for local-disk sidecar writes (index, audit log, …).
     * Same "local-only, best-effort flock, but an unwritable dir is a hard error"
     * contract as the original index-only version — see the class-level note above.
     *
     * @param resource[] &$locks Keyed by disk — the caller's own lock-slot array
     *        (e.g. $this->indexLocks), so index and audit locks stay independent
     *        (a slow audit write must not block metadata index reads/writes).
     */
    private function acquireLock(string $disk, string $lockFileName, array &$locks): void
    {
        $isLocal = ($this->diskManager->config($disk)['driver'] ?? '') === 'local';
        if (!$isLocal || isset($locks[$disk])) {
            return;
        }
        $config = $this->diskManager->config($disk);
        $root = $config['root'] ?? __DIR__ . '/../storage/uploads';
        $lockDir = $root . '/_fluxfiles';
        // Suppress the native warning: under Laravel, HandleExceptions promotes a
        // bare mkdir()/fopen() warning to a fatal ErrorException, so a non-writable
        // uploads dir would surface as a cryptic 500 instead of an actionable error.
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new ApiException(
                "Storage is not writable: cannot create '{$lockDir}'. Grant the web "
                . "server user write access to the uploads directory.",
                500,
                'storage_not_writable'
            );
        }
        FluxDirGuard::ensureDir($root, '_fluxfiles');
        $lockFile = $lockDir . '/' . $lockFileName;
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            throw new ApiException(
                "Storage is not writable: cannot open '{$lockFile}'. Grant the web "
                . "server user write access (chown/chmod) to the uploads directory.",
                500,
                'storage_not_writable'
            );
        }
        // flock can legitimately be unsupported on some filesystems — that stays
        // best-effort (we just don't hold a lock), but an unwritable dir does not.
        if (flock($fp, LOCK_EX)) {
            $locks[$disk] = $fp;
        } else {
            fclose($fp);
        }
    }

    /** @param resource[] &$locks */
    private function releaseLock(string $disk, array &$locks): void
    {
        if (isset($locks[$disk])) {
            flock($locks[$disk], LOCK_UN);
            fclose($locks[$disk]);
            unset($locks[$disk]);
        }
    }

    // ---------------------------------------------------------------------
    // Trash index (soft-delete) — id => manifest, file-locked like the others.
    // ---------------------------------------------------------------------

    /** @return array<string,array> */
    public function allTrash(string $disk): array
    {
        if (isset($this->heldTrash[$disk])) {
            return $this->heldTrash[$disk];
        }
        $all = $this->readTrash($disk);
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            $this->heldTrash[$disk] = $all;
        }
        return $all;
    }

    /** @return array<string,array> */
    private function readTrash(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists(self::TRASH_KEY)) {
            return [];
        }
        try {
            $data = json_decode($fs->read(self::TRASH_KEY), true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getTrash(string $disk, string $id): ?array
    {
        $all = $this->allTrash($disk);
        return $all[$id] ?? null;
    }

    public function addTrash(string $disk, string $id, array $entry): void
    {
        $this->acquireIndexLock($disk);
        try {
            $all = $this->allTrash($disk);
            $all[$id] = $entry;
            $this->saveTrash($disk, $all);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    public function removeTrash(string $disk, string $id): void
    {
        $this->acquireIndexLock($disk);
        try {
            $all = $this->allTrash($disk);
            unset($all[$id]);
            $this->saveTrash($disk, $all);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    private function saveTrash(string $disk, array $all): void
    {
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            $this->heldTrash[$disk] = $all;
            $this->heldTrashDirty[$disk] = true;
            return;
        }
        $this->saveTrashNow($disk, $all);
    }

    private function saveTrashNow(string $disk, array $all): void
    {
        $fs = $this->diskManager->disk($disk);
        $dir = dirname(self::TRASH_KEY);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }
        $fs->write(self::TRASH_KEY, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), self::INTERNAL_WRITE);
    }

    // ---------------------------------------------------------------------
    // Legal hold index (docs/design/RETENTION-LEGAL-HOLD-DESIGN.md §5) — id => manifest,
    // one JSON object per disk, file-locked under the SAME index lock as the
    // metadata index and trash (a hold is infrequent, so a fourth lock file
    // isn't warranted — unlike audit's own dedicated higher-volume lock).
    // ---------------------------------------------------------------------

    /**
     * Fails CLOSED: a manifest that exists but can't be read or decoded throws
     * instead of reading as "no holds". Every hold check sits in front of a
     * destructive operation, so an unreadable manifest must refuse the delete,
     * and addHold()/releaseHold() must never rewrite holds.json from a failed
     * read (that would replace every existing hold with just the new one).
     *
     * @return array<string,array>
     */
    public function allHolds(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        try {
            if (!$fs->fileExists(self::HOLDS_KEY)) {
                return [];
            }
            $data = json_decode($fs->read(self::HOLDS_KEY), true);
        } catch (\Throwable $e) {
            $data = null;
        }
        if (!is_array($data)) {
            error_log("FluxFiles: legal hold manifest on disk '{$disk}' is unreadable — refusing hold-guarded operations");
            // No dedicated code: "treat everything as held" is exactly the
            // fail-closed behaviour, and it reuses the translated message.
            throw new ApiException(
                'The legal hold manifest is unreadable; refusing the change until it is repaired',
                403,
                'legal_hold_active'
            );
        }
        return $data;
    }

    public function getHold(string $disk, string $id): ?array
    {
        $all = $this->allHolds($disk);
        return $all[$id] ?? null;
    }

    public function addHold(string $disk, string $id, array $entry): void
    {
        $this->acquireIndexLock($disk);
        try {
            $all = $this->allHolds($disk);
            $all[$id] = $entry;
            $this->saveHolds($disk, $all);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /**
     * Marks a hold released — the entry is NEVER removed (a "was this file ever
     * held, and when was it lifted" question must still be answerable long after
     * release, mirroring Intake's revoke-writes-a-tombstone pattern).
     */
    public function releaseHold(string $disk, string $id, array $releaseInfo): void
    {
        $this->acquireIndexLock($disk);
        try {
            $all = $this->allHolds($disk);
            if (!isset($all[$id])) {
                return; // caller already validated existence before calling
            }
            $all[$id] = array_merge($all[$id], $releaseInfo);
            $this->saveHolds($disk, $all);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    public function countActiveHolds(string $disk): int
    {
        $n = 0;
        foreach ($this->allHolds($disk) as $entry) {
            if (($entry['released_at'] ?? null) === null) {
                $n++;
            }
        }
        return $n;
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
     * Batch counterpart to holdCovering() — loads the holds manifest ONCE for
     * the whole page instead of once per item. FileManager::attachMetadata()'s
     * listing enrichment used to call holdCovering() per listed item, which
     * meant one full holds.json read+decode per item (O(N) manifest reads for
     * an N-item page instead of O(1)). Same overlap semantics as
     * holdCovering() (ancestor-or-self, non-bidirectional).
     *
     * @param array<int,string> $scopedPaths
     * @return array<string, array<string,mixed>|null> keyed by the given scoped path
     */
    public function holdsCoveringMany(string $disk, array $scopedPaths): array
    {
        // Listing badges only — every guard goes through holdBlocking(), which
        // still fails closed. An unreadable manifest must not take the whole
        // file listing down with it.
        try {
            $holds = $this->allHolds($disk);
        } catch (ApiException $e) {
            $holds = [];
        }
        $out = [];
        foreach ($scopedPaths as $scopedPath) {
            $out[$scopedPath] = self::matchOverlappingHold($holds, $scopedPath, false);
        }
        return $out;
    }

    /**
     * Shared path-prefix overlap check for holdCovering()/holdBlocking(). Hold
     * `H` and target `P` overlap when `H.path === P`, or `H.path` is a prefix of
     * `P` (ancestor-or-self — covers both flavors), or — bidirectional only —
     * `P` is a prefix of `H.path` (the target is an ancestor of the held path).
     * Only ACTIVE (released_at === null) entries are considered.
     */
    private function findOverlappingHold(string $disk, string $scopedPath, bool $bidirectional): ?array
    {
        return self::matchOverlappingHold($this->allHolds($disk), $scopedPath, $bidirectional);
    }

    /**
     * Pure matcher shared by findOverlappingHold() (single lookup) and
     * holdsCoveringMany() (batch lookup against an already-loaded manifest) —
     * keeps the manifest load and the overlap logic decoupled so callers can
     * choose how many times they pay for the former.
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
            if (($entry['released_at'] ?? null) !== null) {
                continue; // released holds never block/cover
            }
            if (self::holdPathOverlaps((string) ($entry['path'] ?? ''), $scopedPath, $bidirectional)) {
                // $id comes from a PHP array key (json_decode(..., true) on
                // holds.json casts any all-digit key to int) — cast back to
                // string or a numeric-looking hold id silently changes type.
                return ['hold_id' => (string) $id] + $entry;
            }
        }
        return null;
    }

    /**
     * Hold `H` and target `P` overlap when they are the same path, `H` is an
     * ancestor of `P`, or — bidirectional only — `P` is an ancestor of `H`.
     * Case-INSENSITIVE on purpose: on a case-insensitive filesystem (macOS/
     * Windows local disks) `Contracts/a.pdf` and `contracts/a.pdf` are one file,
     * so an exact compare would let a differently-cased path slip past the hold.
     * Over-blocking a same-letters sibling on a case-sensitive disk is the safe
     * side. Shared with the DB backend so the two can never diverge.
     */
    public static function holdPathOverlaps(string $holdPath, string $scopedPath, bool $bidirectional): bool
    {
        $holdPath = mb_strtolower(trim($holdPath, '/'), 'UTF-8');
        $scopedPath = mb_strtolower(trim($scopedPath, '/'), 'UTF-8');
        if ($holdPath === '' || $scopedPath === '') {
            return false;
        }
        return $holdPath === $scopedPath
            || strpos($scopedPath, $holdPath . '/') === 0
            || ($bidirectional && strpos($holdPath, $scopedPath . '/') === 0);
    }

    private function saveHolds(string $disk, array $all): void
    {
        $fs = $this->diskManager->disk($disk);
        $dir = dirname(self::HOLDS_KEY);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }
        $fs->write(self::HOLDS_KEY, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), self::INTERNAL_WRITE);
    }

    private function loadIndex(string $disk): array
    {
        if (isset($this->heldIndex[$disk])) {
            return $this->heldIndex[$disk];
        }
        $index = $this->readIndex($disk);
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            $this->heldIndex[$disk] = $index;
        }
        return $index;
    }

    private function readIndex(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists(self::INDEX_KEY)) {
            return [];
        }
        try {
            $json = $fs->read(self::INDEX_KEY);
            $data = json_decode($json, true);
            // Note: json_decode() coerces decimal-integer-looking string keys
            // (e.g. "5", "0", "-3") into real PHP int array keys — this is a
            // fundamental PHP array behaviour (any canonical-decimal string key
            // is normalized to int), not something re-keying here can undo; a
            // rebuilt array with `(string) $k` keys still collapses "5" back to
            // int(5). Every caller that iterates this index and forwards the key
            // into a string-typed parameter (isReservedPath()/isHiddenPath() in
            // search(), strpos() in deleteChildren()/renameChildren(),
            // str_starts_with() in findByHash()) must therefore cast the loop key
            // with `(string)` before using it, to avoid a TypeError under
            // strict_types without silently dropping the entry.
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function saveIndex(string $disk, array $index): void
    {
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            // Under the lock: keep it in memory, written once on the final release.
            $this->heldIndex[$disk] = $index;
            $this->heldIndexDirty[$disk] = true;
            return;
        }
        $this->writeIndex($disk, $index);
    }

    private function writeIndex(string $disk, array $index): void
    {
        $fs = $this->diskManager->disk($disk);
        $dir = dirname(self::INDEX_KEY);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }
        $json = (string) json_encode($index, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $fs->write(self::INDEX_KEY, $json, self::INTERNAL_WRITE);
        $this->warnIfIndexLarge($disk, strlen($json));
    }

    /**
     * Size of the disk-wide index in bytes, or null when there is none (or the
     * backend has no such file). The usage dashboard reports it to operators.
     */
    public function indexBytes(string $disk): ?int
    {
        try {
            $fs = $this->diskManager->disk($disk);
            return $fs->fileExists(self::INDEX_KEY) ? (int) $fs->fileSize(self::INDEX_KEY) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Index size past which the JSON backend stops scaling (`FLUXFILES_INDEX_WARN_MB`,
     * default 5; 0 = never warn). The index is one file per DISK: every list page
     * reads and decodes all of it, and every upload/rename/delete rewrites all of
     * it under a lock — ~300 ms and ~270 MB of PHP memory per page at 100k files.
     * The database backend (FLUXFILES_STORAGE_BACKEND=db) has no such file.
     */
    public static function indexWarnBytes(): int
    {
        $v = $_ENV['FLUXFILES_INDEX_WARN_MB'] ?? getenv('FLUXFILES_INDEX_WARN_MB');
        $mb = ($v === false || $v === null || $v === '') ? 5.0 : (float) $v;
        return $mb > 0 ? (int) ($mb * 1024 * 1024) : 0;
    }

    /** error_log() once an hour per disk (per server) while the index is over the limit. */
    private function warnIfIndexLarge(string $disk, int $bytes): void
    {
        $limit = self::indexWarnBytes();
        if ($limit === 0 || $bytes < $limit) {
            return;
        }
        $cfg = $this->diskManager->config($disk);
        $marker = sys_get_temp_dir() . '/fluxfiles-index-warn-' . substr(sha1($disk . '|' . ($cfg['bucket'] ?? '') . '|' . ($cfg['root'] ?? '')), 0, 16);
        $last = @filemtime($marker);
        if ($last !== false && time() - $last < 3600) {
            return;
        }
        @touch($marker);
        error_log(sprintf(
            "FluxFiles: the metadata index for disk '%s' is %.1f MB. Every list page reads it in full and every "
            . 'write rewrites it, so lists and uploads slow down as it grows. Switch to the database backend '
            . '(FLUXFILES_STORAGE_BACKEND=db, then php scripts/migrate-json-to-db.php) — see docs/design/DB-STORAGE-MIGRATION-DESIGN.md.',
            $disk,
            $bytes / 1048576
        ));
    }

    private function updateIndex(string $disk, string $key, array $data): void
    {
        $this->acquireIndexLock($disk);
        try {
            $index = $this->loadIndex($disk);
            $existing = $index[$key] ?? [];
            $index[$key] = array_merge($existing, [
                'title' => $data['title'] ?? $existing['title'] ?? null,
                'alt_text' => $data['alt_text'] ?? $existing['alt_text'] ?? null,
                'caption' => $data['caption'] ?? $existing['caption'] ?? null,
                'tags' => $data['tags'] ?? $existing['tags'] ?? null,
                'uploaded_by' => $data['uploaded_by'] ?? $existing['uploaded_by'] ?? null,
                'mime' => $data['mime'] ?? $existing['mime'] ?? null,
                'width' => $data['width'] ?? $existing['width'] ?? null,
                'height' => $data['height'] ?? $existing['height'] ?? null,
                // size + modified let search results sort by size/date, not just name.
                'size' => $data['size'] ?? $existing['size'] ?? null,
                'modified' => $data['modified'] ?? $existing['modified'] ?? null,
                // created is the immutable first-seen time (existing wins on re-save).
                'created' => $existing['created'] ?? $data['created'] ?? null,
            ]);
            $this->saveIndex($disk, $index);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    private function removeFromIndex(string $disk, string $key): void
    {
        $this->acquireIndexLock($disk);
        try {
            $index = $this->loadIndex($disk);
            unset($index[$key]);
            $this->saveIndex($disk, $index);
        } finally {
            $this->releaseIndexLock($disk);
        }
    }

    /**
     * @return array<string, true> Set of directory keys.
     */
    private function loadDirsIndex(string $disk): array
    {
        if (isset($this->heldDirs[$disk])) {
            return $this->heldDirs[$disk];
        }
        $dirs = $this->readDirsIndex($disk);
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            $this->heldDirs[$disk] = $dirs;
        }
        return $dirs;
    }

    /** @return array<string,?int> */
    private function readDirsIndex(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists(self::DIRS_KEY)) {
            return [];
        }
        try {
            $json = $fs->read(self::DIRS_KEY);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                return [];
            }
            // Two on-disk shapes: legacy list of keys `["a","b"]`, and the current
            // map `{"a": <created ts|null>}`. Normalize to key => (?int created).
            $isList = array_is_list($data);
            $this->dirsLegacyShape[$disk] = $isList;
            $set = [];
            foreach ($data as $kk => $vv) {
                $k = $isList ? $vv : $kk;
                if (!is_string($k)) continue;
                $k = trim($k, '/');
                if ($k === '' || $k === '_fluxfiles' || str_contains($k, '/_fluxfiles')) continue;
                $set[$k] = $isList ? null : (is_numeric($vv) ? (int) $vv : null);
            }
            return $set;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, true> $dirs
     */
    private function saveDirsIndex(string $disk, array $dirs): void
    {
        unset($this->dirsLegacyShape[$disk]);
        if (($this->indexDepth[$disk] ?? 0) > 0) {
            $this->heldDirs[$disk] = $dirs;
            $this->heldDirsDirty[$disk] = true;
            return;
        }
        $this->writeDirsIndex($disk, $dirs);
    }

    private function writeDirsIndex(string $disk, array $dirs): void
    {
        $fs = $this->diskManager->disk($disk);
        $dir = dirname(self::DIRS_KEY);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }

        ksort($dirs, SORT_STRING);

        try {
            // Map shape `{dirKey: <created ts|null>}` so folder created dates persist.
            $fs->write(self::DIRS_KEY, json_encode((object) $dirs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), self::INTERNAL_WRITE);
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

}

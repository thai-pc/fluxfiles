<?php

declare(strict_types=1);

namespace FluxFiles;

use Aws\S3\S3Client;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;

class FileManager
{
    /** @var DiskManager */
    private $disks;

    /** @var Claims */
    private $claims;

    private MetadataRepositoryInterface $meta;

    /** @var ImageOptimizer */
    private $imageOptimizer;

    /** @var QuotaManager|null */
    private $quotaManager = null;

    /** @var AiTagger|null */
    private $aiTagger = null;

    /** @var callable|null On-upload image optimizer: fn(string $bytes, int $quality): ?array{data,ext}.
     *            Set ONLY when the paid Optimize module is installed + licensed, so the
     *            free core never optimizes (the `auto_optimize` claim is inert without it). */
    private $uploadOptimizer = null;

    /** @var string Secret for minting per-file stream tokens (gated local media). '' = feature off. */
    private $streamSecret = '';

    /** @var string Base path prepended to minted stream/img URLs. Default matches standalone's
     *            and Laravel's real route mount (`/api/fm`, root-relative to the iframe's own
     *            origin). WordPress overrides this to its REST-namespaced absolute URL
     *            (`rest_url('fluxfiles/v1') . '/api/fm'`) via setApiBasePath(), since its
     *            REST API is never reachable at a bare root path. */
    private $apiBasePath = '/api/fm';

    /** @var callable|null Snapshot the current bytes of a file about to be overwritten,
     *            for the paid Versioning module. `fn(string $disk, string $scopedKey, $fs): void`.
     *            Set ONLY when the module is installed + licensed, so the free core keeps
     *            no versions. Called before an overwrite in putContent / upload. */
    private $versionKeeper = null;

    /** @var callable|null Erase all version history of a file being permanently deleted,
     *            for the paid Versioning module. `fn(string $disk, string $scopedKey, $fs): void`.
     *            Set ONLY when the module is installed + licensed. Without this, `/delete`
     *            would remove the live file but leave a restorable version behind — not
     *            actually permanent. Called from delete() for the single-file case. */
    private $versionPurger = null;

    /** @var callable|null Relocate the version history of a file whose key changes,
     *            for the paid Versioning module. `fn(string $disk, string $fromScopedKey,
     *            string $toScopedKey, $fs): void`. Set ONLY when the module is installed +
     *            licensed. Versions are stored under a hash of the scoped key, so without
     *            this a rename/move orphans the history under the OLD key forever — never
     *            listed, never purged. Called from rename() / move() / restore(). */
    private $versionMover = null;

    /** @var callable|null Virus scan of a locally-staged file, for the paid Virus module:
     *            `fn(string $localPath): array{clean:bool, threat:?string}`. Set ONLY when
     *            the `allow_virus_scan` claim is on — and the wiring layer resolves the
     *            module gate INSIDE the callback, so a tenant who asked for scanning but
     *            has no working scanner gets an error on write rather than silently
     *            unscanned files. Never set → no scan, no cost. */
    private $virusScanner = null;

    /** @var callable|null PII scan of a locally-staged file, for the paid DLP module:
     *            `fn(string $localPath, string $name): array{clean:bool, entities:string[]}`.
     *            Set ONLY when the `allow_dlp_scan` claim is on — same "resolve the
     *            module/licence gate INSIDE the callback" contract as $virusScanner,
     *            so a tenant who asked for scanning but has no working engine gets an
     *            error on write rather than silently unscanned files. Extension/size
     *            eligibility (§2.1 of docs/design/DLP-PII-REDACTION-DESIGN.md) is checked in
     *            assertNoPii() BEFORE this is ever invoked. Never set → no scan, no cost. */
    private $dlpScanner = null;

    public function __construct(
        DiskManager $disks,
        Claims $claims,
        MetadataRepositoryInterface $meta
    ) {
        $this->disks = $disks;
        $this->claims = $claims;
        $this->meta = $meta;
        // Per-tenant variant widths come from the token (null = built-in defaults).
        $this->imageOptimizer = new ImageOptimizer($claims->variants);
    }

    public function setQuotaManager(QuotaManager $qm): void
    {
        $this->quotaManager = $qm;
    }

    public function setAiTagger(AiTagger $ai): void
    {
        $this->aiTagger = $ai;
    }

    /**
     * Wire the on-upload image optimizer (paid Optimize module). The callback is
     * `fn(string $bytes, int $quality): ?array{data:string, ext:string}` — null to
     * leave the image untouched. The wiring layer (index.php / adapters) sets this
     * only when the module is installed + licensed, so the free core never optimizes.
     */
    public function setUploadOptimizer(callable $fn): void
    {
        $this->uploadOptimizer = $fn;
    }

    /**
     * Wire the version keeper (paid Versioning module). The callback snapshots the
     * CURRENT bytes of a file that's about to be overwritten, so a prior version can
     * be restored: `fn(string $disk, string $scopedKey, $fs): void`. Set only when the
     * module is installed + licensed, so the free core keeps no versions.
     */
    public function setVersionKeeper(callable $fn): void
    {
        $this->versionKeeper = $fn;
    }

    /**
     * Wire the version purger (paid Versioning module). The callback erases the full
     * version history of a file that's being permanently deleted:
     * `fn(string $disk, string $scopedKey, $fs): void`. Set only when the module is
     * installed + licensed, so the free core has nothing to purge.
     */
    public function setVersionPurger(callable $fn): void
    {
        $this->versionPurger = $fn;
    }

    /**
     * Wire the version mover (paid Versioning module). The callback relocates the
     * version history of a file whose scoped key changes (rename / move / restore
     * to a new path): `fn(string $disk, string $fromScopedKey, string $toScopedKey, $fs): void`.
     * Set only when the module is installed + licensed, so the free core has nothing
     * to relocate.
     */
    public function setVersionMover(callable $fn): void
    {
        $this->versionMover = $fn;
    }

    /**
     * Wire the virus scanner (paid Virus module). The callback scans a file staged on
     * local disk: `fn(string $localPath): array{clean:bool, threat:?string}`. Set only
     * when the token carries `allow_virus_scan`; the module/licence gate is resolved
     * inside the callback so its 501/402/403 surfaces on the write that needed it.
     */
    public function setVirusScanner(callable $fn): void
    {
        $this->virusScanner = $fn;
    }

    /**
     * Wire the PII scanner (paid DLP module). The callback scans a file staged on
     * local disk: `fn(string $localPath, string $name): array{clean:bool, entities:string[]}`.
     * Set only when the token carries `allow_dlp_scan`; the module/licence gate is
     * resolved inside the callback so its 501/402/403 surfaces on the write that
     * needed it. See docs/design/DLP-PII-REDACTION-DESIGN.md.
     */
    public function setDlpScanner(callable $fn): void
    {
        $this->dlpScanner = $fn;
    }

    /**
     * Reject $localPath if it looks like it contains PII per the tenant's configured
     * entity types/threshold. No scanner wired → no-op.
     *
     * The v1 scan-eligibility filter (§2.1/§9.3 of the design doc) runs FIRST, in
     * core, before the (possibly-down) engine is ever called: a file whose extension
     * isn't on `dlp_scan_extensions`, or whose size exceeds `dlp_max_scan_kb`, is
     * SKIPPED — not blocked, and not logged as scanned. This is the one structural
     * difference from assertNoVirus (which scans every file, format-agnostic).
     *
     * **Fail-closed on purpose** for anything that *is* eligible: anything the
     * scanner throws (no engine configured, licence expired, Presidio down)
     * propagates and the write is refused. A malformed verdict counts as detected,
     * same as assertNoVirus's "malformed verdict counts as infected."
     */
    private function assertNoPii(string $localPath, string $name): void
    {
        if ($this->dlpScanner === null) {
            return;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->claims->dlpScanExtensions, true)) {
            return; // not a text-bearing extension — skip, not block (§2.1)
        }
        $maxBytes = $this->claims->dlpMaxScanKb * 1024;
        $size = @filesize($localPath);
        if ($size === false) {
            // Couldn't stat the file at all — this is not a confirmed-oversized skip,
            // it's an unknown state, so fail closed rather than silently letting an
            // unscannable write through.
            throw new ApiException('Could not determine file size for DLP scanning', 500, 'dlp_failed', ['name' => $name]);
        }
        if ($size > $maxBytes) {
            return; // confirmed oversized — skip, not block (§9.3)
        }
        $verdict = ($this->dlpScanner)($localPath, $name);
        if (!is_array($verdict) || ($verdict['clean'] ?? false) !== true) {
            $entities = (is_array($verdict) && is_array($verdict['entities'] ?? null))
                ? array_values(array_map('strval', $verdict['entities']))
                : [];
            throw new ApiException(
                'This file appears to contain personal data (PII) the token is not allowed to store',
                422,
                'pii_detected',
                ['name' => $name, 'entities' => $entities]
            );
        }
    }

    /**
     * Reject $localPath if the scanner calls it infected. No scanner wired → no-op.
     *
     * **Fail-closed on purpose:** anything the scanner throws (no engine configured,
     * licence expired, ClamAV down, VirusTotal unreachable) propagates and the write
     * is refused. Swallowing it would store an unscanned file while the operator
     * believes scanning is on — the one failure mode a scanner must not have.
     */
    private function assertNoVirus(string $localPath, string $name): void
    {
        if ($this->virusScanner === null) {
            return;
        }
        $verdict = ($this->virusScanner)($localPath);
        if (!is_array($verdict) || ($verdict['clean'] ?? false) !== true) {
            $threat = is_array($verdict) ? (string) ($verdict['threat'] ?? '') : '';
            throw new ApiException(
                'This file was rejected by the virus scanner' . ($threat !== '' ? ": {$threat}" : ''),
                422,
                'virus_detected',
                ['name' => $name] + ($threat !== '' ? ['threat' => $threat] : [])
            );
        }
    }

    /** Snapshot the current bytes of $scopedKey before an overwrite (no-op if no keeper). */
    private function keepVersion(string $disk, string $scopedKey, $fs): void
    {
        if ($this->versionKeeper === null) {
            return;
        }
        try {
            ($this->versionKeeper)($disk, $scopedKey, $fs);
        } catch (\Throwable $e) {
            error_log('FluxFiles: version snapshot failed — ' . $e->getMessage());
        }
    }

    /** Erase version history of $scopedKey on permanent delete (no-op if no purger). */
    private function purgeVersions(string $disk, string $scopedKey, $fs): void
    {
        if ($this->versionPurger === null) {
            return;
        }
        try {
            ($this->versionPurger)($disk, $scopedKey, $fs);
        } catch (\Throwable $e) {
            error_log('FluxFiles: version purge failed — ' . $e->getMessage());
        }
    }

    /** Relocate version history from $fromKey to $toKey on rename/move (no-op if no mover). */
    private function moveVersions(string $disk, string $fromKey, string $toKey, $fs): void
    {
        if ($this->versionMover === null || $fromKey === $toKey) {
            return;
        }
        try {
            ($this->versionMover)($disk, $fromKey, $toKey, $fs);
        } catch (\Throwable $e) {
            error_log('FluxFiles: version move failed — ' . $e->getMessage());
        }
    }

    /**
     * Relocate the version history of every file inside a directory that has
     * ALREADY been moved from $fromDir to $toDir. Walks the destination (the
     * subtree now lives there) and maps each file back to its old key, so the
     * walk costs nothing unless a mover is wired. Variant files have no history.
     */
    private function moveVersionsDir(string $disk, string $fromDir, string $toDir, $fs): void
    {
        if ($this->versionMover === null) {
            return;
        }
        $fromDir = rtrim($fromDir, '/');
        $toDir   = rtrim($toDir, '/');
        try {
            foreach ($fs->listContents($toDir, true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $rel = substr($item->path(), strlen($toDir) + 1);
                if ($rel === '' || $rel === false || strpos($rel, '_variants/') !== false) {
                    continue;
                }
                $this->moveVersions($disk, $fromDir . '/' . $rel, $toDir . '/' . $rel, $fs);
            }
        } catch (\Throwable $e) {
            error_log('FluxFiles: version move (directory) failed — ' . $e->getMessage());
        }
    }

    /**
     * Purge the version history of every file inside a directory that is ABOUT
     * to be permanently deleted (walks it while it still exists). No-op unless a
     * purger is wired, so the free core pays for no extra listing.
     */
    private function purgeVersionsDir(string $disk, string $dir, $fs): void
    {
        if ($this->versionPurger === null) {
            return;
        }
        try {
            foreach ($fs->listContents(rtrim($dir, '/'), true) as $item) {
                if ($item->isFile() && strpos($item->path(), '_variants/') === false) {
                    $this->purgeVersions($disk, $item->path(), $fs);
                }
            }
        } catch (\Throwable $e) {
            error_log('FluxFiles: version purge (directory) failed — ' . $e->getMessage());
        }
    }

    /**
     * Purge the version history behind a trash entry being permanently deleted.
     * Versions are keyed by the ORIGINAL key (trash() never relocates them — the
     * payload moves to `_fluxfiles/trash/<id>/…` but the history stays under
     * dir(original_key), which is exactly why restore() to the original key keeps
     * the association intact). A trashed directory's manifest lists every file as
     * `files[].rel` under original_key, so the per-file keys are derivable here.
     */
    private function purgeTrashedVersions(string $disk, array $entry, $fs): void
    {
        if ($this->versionPurger === null) {
            return;
        }
        $origin = (string) ($entry['original_key'] ?? '');
        if ($origin === '') {
            return;
        }
        // History moved into the trash with the payload: purge exactly that.
        $id = (string) ($entry['_id'] ?? '');
        if ($id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            $trashDir = '_fluxfiles/trash/' . $id;
            if (empty($entry['is_dir'])) {
                $this->purgeVersions($disk, $trashDir . '/' . basename((string) ($entry['basename'] ?? '')), $fs);
            } else {
                foreach (($entry['files'] ?? []) as $f) {
                    $rel = $this->safeRel((string) ($f['rel'] ?? ''));
                    if ($rel !== '' && strpos($rel, '_variants/') === false) {
                        $this->purgeVersions($disk, $trashDir . '/payload/' . $rel, $fs);
                    }
                }
            }
        }
        // An entry trashed before history moved with it still has it under the
        // original key(s) — purge those too, unless a live file now owns that path.
        $keys = [];
        if (empty($entry['is_dir'])) {
            $keys[] = $origin;
        } else {
            foreach (($entry['files'] ?? []) as $f) {
                $rel = $this->safeRel((string) ($f['rel'] ?? '')); // strip any traversal
                if ($rel !== '' && strpos($rel, '_variants/') === false) {
                    $keys[] = rtrim($origin, '/') . '/' . $rel;
                }
            }
        }
        foreach ($keys as $key) {
            // History is keyed by path. If a NEW file has since been saved at the
            // trashed file's original path, the history there is that file's now —
            // purging this trash entry must not erase it (the versioning module
            // already hides the old owner's versions from the new file).
            try {
                if ($fs->fileExists($key)) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue; // can't tell → keep history rather than destroy someone else's
            }
            $this->purgeVersions($disk, $key, $fs);
        }
    }

    /**
     * Enable gated local media: when set, files on a local disk marked
     * `'private' => true` are served through a per-file /api/fm/stream token
     * instead of a static URL. Without a secret the feature stays off.
     */
    public function setStreamSecret(#[\SensitiveParameter] string $secret): void
    {
        $this->streamSecret = $secret;
    }

    /**
     * Override the base path minted stream/img URLs are prefixed with. Only WordPress
     * needs this — its REST API only ever resolves under `/wp-json/{namespace}/…`,
     * never at a bare root path, unlike standalone/Laravel where `/api/fm/*` really
     * is the API's mount point at the iframe's own origin. Pass an absolute URL
     * (e.g. `rest_url('fluxfiles/v1') . '/api/fm'`), no trailing slash.
     */
    public function setApiBasePath(string $base): void
    {
        $this->apiBasePath = rtrim($base, '/');
    }

    /** Sort keys list() accepts ('' = key order, the original API behaviour). */
    public const LIST_SORTS = ['', 'name', 'date', 'size', 'type'];
    /** File kinds list() can filter on — the same buckets as the UI's fileIcon(). */
    public const LIST_TYPES = ['all', 'image', 'video', 'audio', 'document', 'other'];
    /** Extension → kind. Mirrors fileIcon() in assets/fm.js; keep the two in step. */
    private const LIST_KIND_EXT = [
        'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
        'video'    => ['mp4', 'webm', 'mov', 'avi'],
        'audio'    => ['mp3', 'wav', 'ogg', 'flac'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
    ];

    /**
     * @param string $sort  '' (folders first, then files, by key — what S3 lists in)
     *                      or name|date|size|type, applied to the WHOLE folder before
     *                      it is cut into pages, so "newest first" means the newest
     *                      in the folder, not on the page. Folders always come first.
     *                      `date` is the storage modified time.
     * @param string $order asc|desc
     * @param string $type  all|image|video|audio|document|other — files only;
     *                      folders are always listed. `total` counts what matched.
     */
    public function list(string $disk, string $path, int $limit = 0, string $cursor = '',
        string $sort = '', string $order = 'asc', string $type = 'all'): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $order = $order === '' ? 'asc' : $order;
        $type = $type === '' ? 'all' : $type;
        if (!in_array($sort, self::LIST_SORTS, true)) {
            throw new ApiException('Invalid sort', 400, 'invalid_sort');
        }
        if ($order !== 'asc' && $order !== 'desc') {
            throw new ApiException('Invalid order', 400, 'invalid_order');
        }
        if (!in_array($type, self::LIST_TYPES, true)) {
            throw new ApiException('Invalid type filter', 400, 'invalid_type');
        }

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        // The per-entry dotfile filter below only hides a dot-NAMED child; listing
        // a dot-directory by path (`.ssh`) would still enumerate its contents.
        $this->assertNotHidden($scoped);
        $fs = $this->disks->disk($disk);

        // S3/R2, a later page that resumes among the FILES (folders sort first, so
        // they were all on earlier pages): ask the bucket for the keys after the
        // cursor instead of re-listing the whole folder — 1000 keys per LIST call,
        // so a 50k-file folder cost ~50 calls for every page. `total` is only
        // known from a full listing, so it is null on these pages (the UI keeps
        // the first page's count).
        // Only in key order: StartAfter resumes in the bucket's byte order.
        if ($limit > 0 && $cursor !== '' && $sort === '' && $order === 'asc') {
            $tail = $this->listS3FilesAfter($disk, $fs, $scoped, $this->scopedPath($cursor), $limit, $type);
            if ($tail !== null) {
                [$page, $hasMore] = $tail;
                $this->attachMetadata($disk, $page);
                return [
                    'items'       => $this->unscopeItems($page),
                    'next_cursor' => $hasMore ? $this->outKey($page[count($page) - 1]['key']) : null,
                    'total'       => null,
                    'disk_driver' => 's3',
                ];
            }
        }

        $all = [];

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($scoped, false) as $item) {
            $name = basename($item->path());
            if ($this->hiddenFromListing($name, $item->isFile(), $item->path(), $fs)) {
                continue;
            }

            if ($item->isFile()) {
                /** @var FileAttributes $item */
                $entry = $this->fileListEntry($item->path(), $item->fileSize(), $item->lastModified());
            } else {
                $entry = ['key' => $item->path(), 'name' => $name, 'type' => 'dir'];
                // Surface the folder mtime when the adapter provides one (local
                // does; S3/R2 prefixes don't) so the UI can sort folders by date.
                try {
                    $mtime = $item->lastModified();
                    if ($mtime !== null) {
                        $entry['modified'] = $mtime;
                    }
                } catch (\Throwable $e) { /* prefix without a timestamp */ }
            }

            $all[] = $entry;
        }

        if ($type !== 'all') {
            $all = array_values(array_filter($all, fn (array $e): bool =>
                $e['type'] === 'dir' || self::listKind($e['name']) === $type));
        }

        // Deterministic order for cursor pagination: folders first, then files, by
        // the requested sort with the key as the final tie-break, so a cursor (the
        // last key handed out) always resumes at the same place.
        $all = $this->sortListing($disk, $all, $sort, $order);

        $total = count($all);

        if ($limit > 0) {
            // Skip past cursor (exclusive). The client echoes the relative cursor
            // we handed out, so re-scope it to the absolute key space first.
            if ($cursor !== '') {
                $absCursor = $this->scopedPath($cursor);
                $start = null;
                foreach ($all as $i => $it) {
                    if ($it['key'] === $absCursor) {
                        $start = $i + 1;
                        break;
                    }
                }
                if ($start === null && $sort !== '') {
                    // The cursor row is gone (deleted, renamed, or no longer matching
                    // the filter). In a sorted listing its place can't be recovered
                    // from the key alone, and restarting at row 0 would silently
                    // re-walk the folder — tell the client to reload instead.
                    throw new ApiException('The listing changed; reload it', 409, 'cursor_stale');
                }
                // Key order (the original API): unchanged — an unknown cursor restarts.
                $all = array_slice($all, $start ?? 0);
            }

            $page = array_slice($all, 0, $limit);
            $nextCursor = (count($page) === $limit && $limit < count($all))
                ? $this->outKey($page[count($page) - 1]['key'])
                : null;

            $this->attachMetadata($disk, $page);

            return [
                'items'       => $this->unscopeItems($page),
                'next_cursor' => $nextCursor,
                'total'       => $total,
                // Lets the UI show driver-specific actions (e.g. chmod on SFTP).
                'disk_driver' => $this->disks->config($disk)['driver'] ?? '',
            ];
        }

        // Legacy mode: return flat array with all items + metadata.
        $this->attachMetadata($disk, $all);
        return $this->unscopeItems($all);
    }

    /** image|video|audio|document|other for a file name — fileIcon() in assets/fm.js. */
    private static function listKind(string $name): string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        foreach (self::LIST_KIND_EXT as $kind => $exts) {
            if (in_array($ext, $exts, true)) {
                return $kind;
            }
        }
        return 'other';
    }

    /**
     * Folders first, then files, in the requested order — the same order the UI's
     * comparators (_compareItems / filteredFolders in assets/fm.js) produce:
     *  - names: ICU root collation with numeric ordering (ext-intl), which is what
     *    the browser's localeCompare(…, {numeric: true}) uses — "img2" before
     *    "img10", and "Ảnh", "éclair" where a reader expects them, not after "Z".
     *    Without ext-intl, strnatcasecmp() (byte order for non-ASCII).
     *  - date: files by storage mtime; folders by their tracked creation time,
     *    else mtime (S3/R2 prefixes have none). Size/type don't apply to folders.
     *  - ties: a FILE tie under date/size/type breaks by name ascending (like the
     *    UI); name sorts and folder ties follow the direction; the key is last.
     * Sort keys are computed once per row, not per comparison: a page re-sorts the
     * whole folder, so this is the cost that grows with folder size.
     *
     * @param array<int,array<string,mixed>> $all
     * @return array<int,array<string,mixed>>
     */
    private function sortListing(string $disk, array $all, string $sort, string $order): array
    {
        $dir = $order === 'desc' ? -1 : 1;
        if ($sort === '') {
            usort($all, static function (array $a, array $b) use ($dir): int {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'dir' ? -1 : 1;
                }
                return strcmp($a['key'], $b['key']) * $dir;
            });
            return $all;
        }

        $coll = self::listCollator();
        $created = [];
        if ($sort === 'date') {
            try {
                $created = $this->meta->dirsCreated($disk);
            } catch (\Throwable $e) {
                $created = [];
            }
        }
        $rows = [];
        foreach ($all as $e) {
            $isDir = $e['type'] === 'dir';
            $name = (string) $e['name'];
            $nameKey = $coll !== null ? $coll->getSortKey($name) : false;
            $v = 0;
            if ($sort === 'date') {
                $v = $isDir
                    ? (int) ($created[$e['key']] ?? $e['modified'] ?? 0)
                    : (int) ($e['modified'] ?? 0);
            } elseif ($sort === 'size' && !$isDir) {
                $v = (int) ($e['size'] ?? 0);
            } elseif ($sort === 'type' && !$isDir) {
                // The UI's icon name for "other" is 'file' — sort on the same strings.
                $kind = self::listKind($name);
                $v = $kind === 'other' ? 'file' : $kind;
            }
            $rows[] = ['e' => $e, 'd' => $isDir, 'n' => $nameKey !== false ? $nameKey : $name,
                'nat' => $nameKey === false, 'v' => $v, 'k' => (string) $e['key']];
        }
        usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
            if ($a['d'] !== $b['d']) {
                return $a['d'] ? -1 : 1;
            }
            if ($sort !== 'name' && ($sort === 'date' || !$a['d'])) {
                $p = $a['v'] <=> $b['v'];
                if ($p !== 0) {
                    return $p * $dir;
                }
            }
            $nameDir = ($sort === 'name' || $a['d']) ? $dir : 1;
            $n = ($a['nat'] || $b['nat']) ? strnatcasecmp((string) $a['e']['name'], (string) $b['e']['name']) : strcmp($a['n'], $b['n']);
            if ($n !== 0) {
                return $n * $nameDir;
            }
            return strcmp($a['k'], $b['k']) * $nameDir;
        });
        return array_column($rows, 'e');
    }

    /** ICU root collation with numeric ordering, or null without ext-intl. */
    private static function listCollator(): ?\Collator
    {
        static $coll = false;
        if ($coll === false) {
            $coll = null;
            if (class_exists(\Collator::class)) {
                $c = new \Collator('root');
                $c->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
                $coll = $c;
            }
        }
        return $coll;
    }

    /** Entries list() never shows: system folders, dotfiles (unless show_hidden), legacy sidecars. */
    private function hiddenFromListing(string $name, bool $isFile, string $path, $fs): bool
    {
        // Hide internal system directories.
        if ($name === '_fluxfiles' || $name === '_variants') {
            return true;
        }
        // Hide dotfiles (.env, .gitignore, .git/…) unless the token opts in —
        // matches Finder / cPanel / Nextcloud, and keeps a `.env` out of sight.
        if (!$this->claims->showHidden && $name !== '' && $name[0] === '.') {
            return true;
        }
        // Hide a LEGACY sidecar ({file}.meta.json next to its file) but show genuine
        // user-uploaded *.meta.json files (no matching base file). New sidecars live
        // under _fluxfiles/meta/ and are already hidden by the check above.
        return substr($name, -10) === '.meta.json' && $isFile && $fs->fileExists(substr($path, 0, -10));
    }

    /**
     * A file row as list() scans it. No URL yet: on S3/R2 that is a presign per
     * file, and the scan covers the WHOLE folder while only one page is returned —
     * attachMetadata() adds it to the page's rows only. (Signing every row first
     * cost ~10k presigns, 10+ s, per 200-item page of a 10k-file folder.)
     *
     * @return array<string,mixed>
     */
    private function fileListEntry(string $path, ?int $size, ?int $mtime): array
    {
        return ['key' => $path, 'name' => basename($path), 'type' => 'file', 'size' => $size, 'modified' => $mtime];
    }

    /**
     * The next page of FILES after $absCursor in folder $scoped, straight from an
     * S3/R2 bucket (ListObjectsV2 StartAfter), or null when this shortcut doesn't
     * apply — not an S3 disk, the cursor is a folder (still among the folders,
     * which sort first) or gone, or the bucket call failed — and list() falls
     * back to a full listing. Same filters and entry shape as the full listing;
     * keys come back in byte order, which is what list()'s strcmp sort uses.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: bool}|null [page, has more]
     */
    private function listS3FilesAfter(string $disk, $fs, string $scoped, string $absCursor, int $limit, string $type = 'all'): ?array
    {
        $cfg = $this->disks->config($disk);
        if (($cfg['driver'] ?? '') !== 's3') {
            return null;
        }
        $prefix = trim($scoped, '/') === '' ? '' : trim($scoped, '/') . '/';
        if (strpos($absCursor, $prefix) !== 0 || strpos(substr($absCursor, strlen($prefix)), '/') !== false) {
            return null;
        }
        try {
            if (!$fs->fileExists($absCursor)) {
                return null;
            }
            $client = $this->disks->s3Client($disk);
            $params = ['Bucket' => (string) ($cfg['bucket'] ?? ''), 'Prefix' => $prefix, 'Delimiter' => '/',
                'StartAfter' => $absCursor, 'MaxKeys' => min(1000, $limit + 1)];
            $out = [];
            do {
                $res = $client->listObjectsV2($params);
                foreach ((array) ($res['Contents'] ?? []) as $o) {
                    $key = (string) ($o['Key'] ?? '');
                    if ($key === '' || substr($key, -1) === '/') {
                        continue; // folder marker object
                    }
                    if ($this->hiddenFromListing(basename($key), true, $key, $fs)) {
                        continue;
                    }
                    if ($type !== 'all' && self::listKind(basename($key)) !== $type) {
                        continue;
                    }
                    $mtime = $o['LastModified'] ?? null;
                    $out[] = $this->fileListEntry($key, isset($o['Size']) ? (int) $o['Size'] : null,
                        $mtime instanceof \DateTimeInterface ? $mtime->getTimestamp() : null);
                    if (count($out) > $limit) {
                        return [array_slice($out, 0, $limit), true];
                    }
                }
                $token = !empty($res['IsTruncated']) ? (string) ($res['NextContinuationToken'] ?? '') : '';
                unset($params['StartAfter']);
                $params['ContinuationToken'] = $token;
                $params['MaxKeys'] = 1000;
            } while ($token !== '');
            return [$out, false];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Merge metadata + variants into file entries in place.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function attachMetadata(string $disk, array &$items): void
    {
        $fileKeys = [];
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'file') {
                $fileKeys[] = $it['key'];
            }
        }
        $metaMap = !empty($fileKeys) ? $this->meta->getBulk($disk, $fileKeys) : [];
        // Folder created timestamps live in our own dirs index (works on S3/R2 too).
        $dirsCreated = $this->meta->dirsCreated($disk);

        // Legal hold enrichment — free/core, unconditional (docs/RETENTION-LEGAL-HOLD-
        // DESIGN.md §4.2). holdsCoveringMany() loads the holds manifest/table ONCE
        // for the whole page and returns a path => hold map, so a page of N items
        // costs a single manifest read + N in-memory lookups — not N separate
        // holdCovering() calls, each of which used to re-read+re-parse the entire
        // manifest (O(N) redundant reads for an N-item page).
        $holdMap = $this->meta->holdsCoveringMany($disk, array_column($items, 'key'));
        $canSeeHoldDetail = $this->claims->hasPerm('audit');
        // Which variants/posters exist, from ONE listing per `_variants/` folder
        // instead of 3–4 fileExists() per file — on S3/R2/SFTP each of those is a
        // round trip, so a 200-image page cost ~600 requests before it rendered.
        $variantSet = $this->claims->allowDownload ? $this->existingVariants($disk, $items) : [];

        foreach ($items as &$item) {
            $hold = $holdMap[$item['key']] ?? null;
            $item['on_hold'] = $hold !== null;
            if ($hold !== null && $canSeeHoldDetail) {
                $item['hold_id']        = $hold['hold_id'] ?? null;
                $item['hold_reason']    = $hold['reason'] ?? null;
                $item['hold_placed_by'] = $hold['placed_by'] ?? null;
                $item['hold_placed_at'] = $hold['placed_at'] ?? null;
            }

            if (($item['type'] ?? '') === 'dir') {
                $c = $dirsCreated[$item['key']] ?? null;
                if ($c !== null) {
                    $item['created'] = (int) $c;
                }
                continue;
            }
            if (($item['type'] ?? '') === 'file') {
                // Preview-only tokens (allow_download=false) get no clean download URL.
                $url = $this->downloadUrl($disk, $item['key']);
                if ($url !== null) {
                    $item['url'] = $url;
                }
                $item['meta'] = $metaMap[$item['key']] ?? null;
                // Preview-only tokens get no stable URL and no clean WebP variants —
                // the (watermarked) img_base is the only image view they receive.
                if ($this->claims->allowDownload) {
                    $perm = $this->permanentUrl($disk, $item['key']);
                    if ($perm !== null) {
                        $item['permanent_url'] = $perm;
                    }
                }
                if (is_array($item['meta'])) {
                    foreach (['mime', 'width', 'height', 'created'] as $attr) {
                        if (isset($item['meta'][$attr]) && $item['meta'][$attr] !== null) {
                            $item[$attr] = $item['meta'][$attr];
                        }
                    }
                }
                $item['variants'] = $this->downloadVariants($disk, $item['key'], $variantSet);
                // Poster for a PDF/video (page 1 / a frame). Withheld from
                // preview-only tokens like the clean variants above — it is an
                // un-watermarked render of the content.
                if ($this->claims->allowDownload && MediaPoster::kindFor($item['key']) !== null) {
                    $pk = $this->variantKey($item['key'], 'thumb');
                    try {
                        if ($this->variantExists($disk, $pk, $variantSet)) {
                            $item['poster'] = $this->fileUrl($disk, $pk);
                        }
                    } catch (\Throwable $e) {
                        // no poster
                    }
                }
                $imgBase = $this->imgBaseUrl($disk, $item['key']);
                if ($imgBase !== null) {
                    $item['img_base'] = $imgBase;
                    $natural = isset($item['width']) ? (int) $item['width'] : 0;
                    $srcset = $this->buildSrcset($imgBase, $natural);
                    if ($srcset !== '') {
                        $item['img_srcset'] = $srcset;
                        if ($this->claims->srcsetSizes !== '') {
                            $item['img_sizes'] = $this->claims->srcsetSizes;
                        }
                    }
                }
            }
        }
        unset($item);
    }

    public function upload(string $disk, string $path, array $file, bool $forceUpload = false): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $name = $file['name'] ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->assertExt($ext);
        $this->assertSafeFilename($name);
        // Same dangerous-character blacklist rename() enforces — assertSafeFilename()
        // only blocks dangerous EXTENSIONS, so without this an uploaded name could
        // still carry <>:"|?* or control chars through to storage/metadata/UI.
        if (preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $name)) {
            throw new ApiException('Name contains invalid characters', 400, 'name_invalid');
        }

        $sizeMb = ($file['size'] ?? 0) / (1024 * 1024);
        $this->assertUploadSize($sizeMb);

        // Quota check
        if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0) {
            $this->quotaManager->assertQuota(
                $disk,
                $this->claims->pathPrefix,
                (int) ($file['size'] ?? 0),
                $this->claims->maxStorageMb
            );
        }

        // File-count limit (total files under the prefix)
        if ($this->quotaManager !== null && $this->claims->maxFiles > 0) {
            $this->quotaManager->assertFileCount(
                $disk,
                $this->claims->pathPrefix,
                1,
                $this->claims->maxFiles
            );
        }

        // Virus scan (paid Virus module) — BEFORE anything reads or rewrites the
        // bytes, so an infected upload costs only the cheap guards above and no
        // infected byte ever reaches storage. Scans the ORIGINAL upload: optimize
        // below derives from it, so scanning the original covers both.
        $this->assertNoVirus($file['tmp_name'], $name);

        // PII scan (paid DLP module), same "before anything reads or rewrites the
        // bytes" placement as the virus scan above. Scans the ORIGINAL upload.
        $this->assertNoPii($file['tmp_name'], $name);

        // Privacy: drop EXIF/GPS/XMP before the bytes are hashed, measured, given
        // variants or written — so nothing downstream ever sees the location.
        // Lossless (container-level) and keeps Orientation; see ExifStripper.
        $exifStripped = false;
        if ($this->claims->stripExif && (int) ($file['size'] ?? 0) <= self::STRIP_EXIF_MAX) {
            $raw = @file_get_contents($file['tmp_name']);
            $res = is_string($raw) ? ExifStripper::strip($raw) : null;
            if ($res !== null && $res['removed'] && @file_put_contents($file['tmp_name'], $res['data']) !== false) {
                $file['size'] = strlen($res['data']);
                $exifStripped = true;
            }
            unset($raw, $res);
        }

        // Auto-optimize on upload (paid Optimize module). Recompress the image in
        // the temp file BEFORE anything downstream (hash, dims, write, variants) so
        // they all operate on the optimized bytes + the new .webp name. The hook is
        // only set when the module is installed + licensed; the `auto_optimize` claim
        // gates per tenant. Quota was checked on the (larger) original — still safe.
        $autoSaved = 0;
        if ($this->claims->autoOptimize
            && $this->uploadOptimizer !== null
            && $this->imageOptimizer->isImage($name)
            && (int) ($file['size'] ?? 0) <= self::AUTO_OPTIMIZE_MAX
        ) {
            $bytes = @file_get_contents($file['tmp_name']);
            if ($bytes !== false && $bytes !== '') {
                $quality = $this->claims->optimizeQuality > 0 ? $this->claims->optimizeQuality : 82;
                $opt = ($this->uploadOptimizer)($bytes, $quality);
                if (is_array($opt) && isset($opt['data']) && strlen($opt['data']) < strlen($bytes)) {
                    $newName = preg_replace('/\.[^.\/]+$/', '', $name) . '.' . ($opt['ext'] ?? 'webp');
                    // Re-apply the same upload guards to the new name (allowed_ext / dangerous-ext).
                    $newExt = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
                    $this->assertExt($newExt);
                    $this->assertSafeFilename($newName);
                    if (@file_put_contents($file['tmp_name'], $opt['data']) !== false) {
                        $autoSaved = strlen($bytes) - strlen($opt['data']);
                        $name = $newName;
                        $ext = $newExt;
                        $file['size'] = strlen($opt['data']);
                    }
                }
            }
        }

        $scoped = $this->scopedPath(rtrim($path, '/') . '/' . $name);
        $this->assertNotSystem($scoped, $disk);
        // Every name check above ran on $name, but the write goes to $scoped:
        // scopedPath() drops `.`/`..`/empty segments, so a name of `..` (or '')
        // collapses and the LAST segment of `path` becomes the file name
        // unchecked (`path=shell.php` + `name=..` → shell.php). The final key's
        // leaf must be the very name that was validated, and is re-checked.
        if (self::leafOf($scoped) !== $name) {
            throw new ApiException('Name contains invalid characters', 400, 'name_invalid');
        }
        $this->assertFileLeaf($scoped);
        $this->assertNotHidden($scoped);
        $fs = $this->disks->disk($disk);

        // Content dedup (SHA-256) — OPT-IN via the `dedupe_uploads` claim. Off by
        // default so an identical re-upload is kept as a copy (handled by the
        // name-collision policy below), matching Finder / Google Drive / Dropbox.
        // When enabled it refuses byte-for-byte duplicates to save storage. Only
        // reports a duplicate that actually exists on disk and isn't internal.
        $hash = hash_file('sha256', $file['tmp_name']);
        if ($hash !== false && !$forceUpload && $this->claims->dedupeUploads) {
            $ownerFilter = $this->claims->ownerOnly ? $this->claims->userId : null;
            $existing = $this->meta->findByHash($disk, $hash, $this->claims->pathPrefix, $ownerFilter);
            if ($existing) {
                $existingKey = $existing['file_key'];
                $isSystem = self::isReservedKey($existingKey);
                if (!$isSystem && $fs->fileExists($existingKey)) {
                    return $this->unscopeItems([[
                        'key'       => $existingKey,
                        'url'       => $this->downloadUrl($disk, $existingKey),
                        'name'      => basename($existingKey),
                        'duplicate' => true,
                        'message'   => 'File already exists. Use force_upload to override.',
                        'variants'  => $this->downloadVariants($disk, $existingKey),
                    ]])[0];
                }
                // Stale / invalid hash entry — purge so it doesn't keep blocking uploads.
                $this->meta->delete($disk, $existingKey);
            }
        }

        // Name-collision policy. By this point the content is NOT a hash-duplicate
        // (that returned above), so a name clash means a DIFFERENT file sharing the
        // name. Default 'rename' keeps both (like Drive/Dropbox/WordPress); 'reject'
        // 409s for the host to prompt; 'overwrite' falls through to replace in place.
        // $forceUpload (force_upload / import-url `overwrite`) only skips the dedup
        // check above — it must not override the operator's collision policy, or a
        // `reject`/`rename` token could still clobber files in place.
        if ($this->claims->uploadCollision !== 'overwrite' && $fs->fileExists($scoped)) {
            if ($this->claims->uploadCollision === 'reject') {
                throw new ApiException('A file with this name already exists', 409, 'name_conflict', ['name' => $name]);
            }
            // 'rename' → append -1, -2, … to the base name (extension preserved).
            $name   = $this->uniqueName($fs, rtrim($path, '/'), $name);
            $scoped = $this->scopedPath(rtrim($path, '/') . '/' . $name);
            $this->assertNotSystem($scoped, $disk);
        }

        // Track parent directories for global folder search (best-effort)
        $this->meta->trackParents($disk, $scoped);

        // Overwriting an existing file (collision=overwrite or force_upload) →
        // honour owner_only like delete/rename/move/crop/watermark/putContent do
        // (it was missing here, letting a different tenant overwrite another
        // user's file in place), then snapshot the current bytes (Versioning
        // module) before replacing it.
        if ($fs->fileExists($scoped)) {
            $this->assertOwner($disk, $scoped);
            $this->assertNoActiveHold($disk, $scoped);
            $this->keepVersion($disk, $scoped, $fs);
        }

        $stream = fopen($file['tmp_name'], 'r');
        if ($stream === false) {
            throw new ApiException('Failed to read uploaded file', 500, 'upload_failed');
        }

        try {
            $fs->writeStream($scoped, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Hash (duplicate detection) + metadata below land in one index write.
        $this->beginIndexBatch($disk);
        try {
            if ($hash !== false) {
                $this->meta->saveHash($disk, $scoped, $hash);
            }

            // Store ownership + MIME, and image dimensions (so listings and the
            // select payload can carry them without re-opening the object).
            // size + modified are indexed too, so search results can sort by date/size.
            $attrs = [
                'uploaded_by' => $this->claims->userId,
                'size'        => (int) ($file['size'] ?? 0),
                'modified'    => time(),
                'created'     => time(), // first-upload time; kept immutable by the index merge
            ];
            $mime = @mime_content_type($file['tmp_name']);
            if ($mime === false || $mime === null || $mime === '') {
                $mime = $file['type'] ?? null;
            }
            if ($mime) {
                $attrs['mime'] = $mime;
            }
            if ($this->imageOptimizer->isImage($name)) {
                $dims = @getimagesize($file['tmp_name']);
                if (is_array($dims) && isset($dims[0], $dims[1])) {
                    $attrs['width']  = (int) $dims[0];
                    $attrs['height'] = (int) $dims[1];
                }
            }
            $this->meta->save($disk, $scoped, $attrs);
        } finally {
            $this->endIndexBatch($disk);
        }

        // Record the on-upload optimization savings into the tenant's counter, and
        // surface them on the upload result so the UI can toast "saved N".
        $result = [
            'key'      => $scoped,
            'url'      => $this->downloadUrl($disk, $scoped),
            'size'     => $file['size'],
            'name'     => $name,
            'variants' => null,
        ];
        if ($exifStripped) {
            $result['exif_stripped'] = true;
        }
        // assertNoVirus() above is fail-closed, so reaching here with a scanner
        // wired means these exact bytes were scanned and came back clean.
        if ($this->virusScanner !== null) {
            $result['virus_scanned'] = true;
        }
        if ($autoSaved > 0) {
            OptimizeStats::record($fs, $this->claims->pathPrefix, $autoSaved, 1);
            $result['optimized'] = true;
            $result['saved_bytes'] = $autoSaved;
        }

        // Generate image variants (thumb, medium, large as WebP)
        if ($this->imageOptimizer->isImage($name)) {
            try {
                $variants = $this->imageOptimizer->process($fs, $scoped, $file['tmp_name']);
                $result['variants'] = $this->variantUrls($disk, $variants);
            } catch (\Throwable $e) {
                error_log('FluxFiles: Image optimization failed — ' . $e->getMessage());
            }
        } elseif ($this->claims->mediaPosters && MediaPoster::kindFor($name) !== null) {
            // PDF page 1 / video frame → the same `_thumb.webp` variant an image gets.
            // Optional tools, best-effort: a missing gs/ffmpeg or a failed render just
            // leaves the generic icon.
            $raster = MediaPoster::render($file['tmp_name'], $name);
            $poster = $raster !== null ? $this->imageOptimizer->writePosterThumb($fs, $scoped, $raster) : null;
            if ($poster !== null && $this->claims->allowDownload) {
                $result['poster'] = $this->fileUrl($disk, $poster['key']);
            }
        }

        // Auto-tag with AI if enabled. The token's `ai_auto_tag` claim overrides the
        // server default per tenant (true/false); when unset, inherit the env flag.
        $autoTag = $this->claims->aiAutoTag ?? (($_ENV['FLUXFILES_AI_AUTO_TAG'] ?? '') === 'true');
        if ($this->aiTagger !== null
            && $this->imageOptimizer->isImage($name)
            && $autoTag
        ) {
            try {
                $imageData = file_get_contents($file['tmp_name']);
                $mime = mime_content_type($file['tmp_name']) ?: 'image/jpeg';
                $aiResult = $this->aiTagger->analyze($imageData, $mime);

                if ($aiResult) {
                    $this->meta->save($disk, $scoped, [
                        'title'    => $aiResult['title'] ?? null,
                        'alt_text' => $aiResult['alt_text'] ?? null,
                        'caption'  => $aiResult['caption'] ?? null,
                        'tags'     => implode(', ', $aiResult['tags'] ?? []),
                    ]);
                    $result['ai_tags'] = $aiResult;
                }
            } catch (\Throwable $e) {
                error_log('FluxFiles: AI auto-tag failed — ' . $e->getMessage());
            }
        }

        return $this->unscopeItems([$result])[0];
    }

    public function delete(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');

        return $this->deleteScoped($disk, $this->scopedPath($path));
    }

    /**
     * delete() for an already-scoped key, with every check delete() applies.
     * For internal callers holding a real storage key (runRetention): sending
     * it back through outKey()→scopedPath() is not an identity when a subfolder
     * repeats the prefix's last segment, and would act on a different file.
     */
    private function deleteScoped(string $disk, string $scoped): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $this->assertScopedKeyInScope($scoped);
        $this->assertNotSystem($scoped, $disk);
        $this->assertOwner($disk, $scoped);
        $this->assertNoActiveHold($disk, $scoped);
        $fs = $this->disks->disk($disk);

        $isDir = false;
        $isFile = false;
        try {
            $isDir = $fs->directoryExists($scoped);
            if (!$isDir) {
                $isFile = $fs->fileExists($scoped);
            }
        } catch (\Throwable $e) {
            // Existence check failure is treated as not-found below.
        }

        if (!$isDir && !$isFile) {
            throw new ApiException('File or directory not found', 404, 'not_found');
        }

        if ($isDir) {
            $this->assertOwnsTree($disk, $scoped);
        }

        try {
            if ($isDir) {
                $this->deleteVariantsDir($disk, $scoped);
                $this->purgeVersionsDir($disk, $scoped, $fs); // walk BEFORE the tree is gone
                $fs->deleteDirectory($scoped);
                $this->meta->deleteChildren($disk, $scoped);
                $this->meta->deleteDirPrefix($disk, $scoped);
            } else {
                $this->deleteVariants($disk, $scoped);
                $this->purgeVersions($disk, $scoped, $fs);
                $fs->delete($scoped);
                $this->meta->delete($disk, $scoped);
            }
        } catch (\Throwable $e) {
            throw new ApiException('Delete failed: ' . $e->getMessage(), 500, 'delete_failed');
        }

        return ['deleted' => true];
    }

    /**
     * Soft-delete a file or directory: move it (and image variants) into the
     * reserved trash namespace, snapshot metadata, and record a restorable
     * entry. Directories move the whole subtree (variants included).
     */
    public function trash(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');

        return $this->trashScoped($disk, $this->scopedPath($path));
    }

    /** trash() for an already-scoped key — see deleteScoped() for why. */
    /**
     * On a public native-AWS disk every object is written public-read, and a move
     * into the trash keeps that ACL — so trashed bytes stayed fetchable by URL
     * under `_fluxfiles/trash/<id>/…`. Flip them private while trashed and back to
     * the disk default on restore. Best-effort; a no-op on every other disk.
     */
    private function setTrashVisibility(string $disk, $fs, string $path, bool $isDir, string $visibility): void
    {
        $cfg = $this->disks->config($disk);
        if (($cfg['driver'] ?? '') !== 's3' || !empty($cfg['endpoint']) || ($cfg['visibility'] ?? 'private') !== 'public') {
            return;
        }
        try {
            if (!$isDir) {
                $fs->setVisibility($path, $visibility);
                return;
            }
            foreach ($fs->listContents($path, true) as $item) {
                if ($item->isFile()) {
                    $fs->setVisibility($item->path(), $visibility);
                }
            }
        } catch (\Throwable $e) {
            error_log('FluxFiles: trash visibility change failed — ' . $e->getMessage());
        }
    }

    private function trashScoped(string $disk, string $scoped): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $this->assertScopedKeyInScope($scoped);
        $this->assertNotSystem($scoped, $disk);
        $this->assertOwner($disk, $scoped);
        // Covers both the file and directory branches below (trashDirectory() is a
        // private helper only ever reached through here).
        $this->assertNoActiveHold($disk, $scoped);

        $fs = $this->disks->disk($disk);
        $isDir = false;
        try { $isDir = $fs->directoryExists($scoped); } catch (\Throwable $e) { /* not a dir */ }

        $id       = bin2hex(random_bytes(8));
        $trashDir = '_fluxfiles/trash/' . $id;

        if ($isDir) {
            return $this->trashDirectory($disk, $scoped, $id, $trashDir, $fs);
        }
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('File not found', 404, 'not_found');
        }

        $basename = basename($scoped);
        $size     = 0;
        try { $size = (int) $fs->fileSize($scoped); } catch (\Throwable $e) { /* best effort */ }

        // Snapshot metadata before it is removed from the active index.
        $metaSnapshot = $this->meta->get($disk, $scoped) ?: [];

        try {
            $fs->move($scoped, $trashDir . '/' . $basename);
        } catch (\Throwable $e) {
            throw new ApiException('Trash failed: ' . $e->getMessage(), 500, 'trash_failed');
        }
        $this->setTrashVisibility($disk, $fs, $trashDir . '/' . $basename, false, 'private');

        $movedVariants = [];
        foreach (['thumb', 'medium', 'large'] as $vsize) {
            $vk = $this->variantKey($scoped, $vsize);
            try {
                if ($fs->fileExists($vk)) {
                    $fs->move($vk, $trashDir . '/v/' . $vsize . '.webp');
                    $movedVariants[] = $vsize;
                }
            } catch (\Throwable $e) { /* best effort */ }
        }

        // Version history travels with the file into the trash, so a new file saved
        // at the original path starts its own history and purging this entry can't
        // touch it. (No flag on the entry — the DB backends only persist fixed
        // columns — so restore/purge simply try the trash key AND the legacy
        // original key; the versioning module ignores a key with no history and
        // never takes history from under a live file.)
        $this->moveVersions($disk, $scoped, $trashDir . '/' . $basename, $fs);

        // Drop the active metadata; the trash entry (written last) is the commit point.
        $this->meta->delete($disk, $scoped);
        $this->meta->addTrash($disk, $id, [
            'original_key' => $scoped,
            'disk'         => $disk,
            'basename'     => $basename,
            'size'         => $size,
            'deleted_at'   => time(),
            'deleted_by'   => $this->claims->userId,
            'variants'     => $movedVariants,
            'meta'         => $metaSnapshot,
        ]);

        return ['trashed' => true, 'trash_id' => $id];
    }

    /**
     * Soft-delete a whole directory subtree (variants included) into trash.
     *
     * The manifest records directories as well as files: a subdirectory holding
     * no files has nothing in `files[]` to imply it, so without `dirs[]` restore
     * could not bring it back — and on an object store a folder whose entire
     * content is subfolders would trash to an empty manifest.
     */
    private function trashDirectory(string $disk, string $scoped, string $id, string $trashDir, $fs): array
    {
        $this->assertOwnsTree($disk, $scoped);

        // Walk first: the relocation below would disturb a recursive listing, and
        // per-file metadata has to be snapshotted while it is still in the index.
        $prefix = rtrim($scoped, '/') . '/';
        $files  = [];
        $dirs   = [];
        $total  = 0;
        try {
            foreach ($fs->listContents($scoped, true) as $item) {
                $rel = substr($item->path(), strlen($prefix));
                if ($rel === '' || $rel === false) {
                    continue;
                }
                if (!$item->isFile()) {
                    $dirs[] = $rel;
                    continue;
                }
                // Snapshot metadata for real files (variant files have none).
                $meta = (strpos($rel, '_variants/') === false) ? ($this->meta->get($disk, $item->path()) ?: []) : [];
                try { $total += (int) $fs->fileSize($item->path()); } catch (\Throwable $e) { /* best effort */ }
                $files[] = ['rel' => $rel, 'meta' => $meta];
            }
        } catch (\Throwable $e) {
            throw new ApiException('Trash failed: ' . $e->getMessage(), 500, 'trash_failed');
        }

        // Same driver-aware relocation the rename path uses — one native move on
        // local/SFTP, a marker-creating walk on object stores — so empty
        // subdirectories travel into the payload and the source is only dropped
        // once the payload is in place.
        try {
            $this->moveDirectoryTree($disk, $fs, $scoped, $trashDir . '/payload');
        } catch (\Throwable $e) {
            throw new ApiException('Trash failed: ' . $e->getMessage(), 500, 'trash_failed');
        }
        $this->setTrashVisibility($disk, $fs, $trashDir . '/payload', true, 'private');

        $this->moveVersionsDir($disk, $scoped, $trashDir . '/payload', $fs); // see the file case

        $this->meta->deleteChildren($disk, $scoped);
        $this->meta->deleteDirPrefix($disk, $scoped);

        $this->meta->addTrash($disk, $id, [
            'original_key' => $scoped,
            'disk'         => $disk,
            'basename'     => basename($scoped),
            'is_dir'       => true,
            'size'         => $total,
            'deleted_at'   => time(),
            'deleted_by'   => $this->claims->userId,
            'files'        => $files,
            'dirs'         => $dirs,
        ]);

        return ['trashed' => true, 'trash_id' => $id];
    }

    /** Restore a trashed file or directory to its original key (or $newPath). */
    public function restore(string $disk, string $id, ?string $newPath = null): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        // A restore puts bytes back on the disk, so it is a write as much as it
        // is an undo — without this a delete-only token could land a file.
        $this->assertPerm('write');
        $this->assertSafeTrashId($id);

        $entry = $this->meta->getTrash($disk, $id);
        if ($entry === null) {
            throw new ApiException('Trash item not found', 404, 'not_found');
        }
        $this->assertTrashScope($entry);

        $origin = (string) ($entry['original_key'] ?? '');
        $target = ($newPath !== null && $newPath !== '')
            ? $this->scopedPath($newPath)
            : $origin;
        $this->assertNotSystem($target, $disk);
        // show_hidden=false: a trashed dotfile must not come back under a
        // visible name (nor anything be restored as a dotfile).
        $this->assertNotHidden($origin);
        $this->assertNotHidden($target);
        // A hold binds to the tenant-visible path and outlives a soft-delete
        // (purgeTrash() checks the same key) — restoring is a relocation of the
        // held record, refused like move()'s source. Source-side only, as in
        // move(): the target can't exist yet (assertTargetAvailable below).
        $this->assertNoActiveHold($disk, $origin);

        // Restore is a relocation out of the trash, so it gets the same
        // extension rules as rename/move/copy. Without them a caller could
        // trash a.jpg and restore it as a.php: `path` is caller-supplied, and
        // the manifest's original_key is attacker-writable on a BYOB disk.
        // Directories have no extension (mirroring rename), but their name is
        // still checked for a dangerous double extension.
        if (!empty($entry['is_dir'])) {
            $this->assertSafeFilename(basename($target));
        } else {
            $this->assertRelocationExt((string) ($entry['original_key'] ?? $target), $target);
        }

        $fs = $this->disks->disk($disk);
        $this->assertTargetAvailable($fs, $target);

        $trashDir = '_fluxfiles/trash/' . $id;
        $this->assertRestoreFits($disk, $entry, $trashDir, $fs);

        if (!empty($entry['is_dir'])) {
            return $this->restoreDirectory($disk, $id, $entry, $target, $trashDir, $fs);
        }

        // basename() defends against a tampered manifest (BYOB) carrying a path.
        $payloadName = basename((string) ($entry['basename'] ?? ''));
        try {
            $fs->move($trashDir . '/' . $payloadName, $target);
        } catch (\Throwable $e) {
            throw new ApiException('Restore failed: ' . $e->getMessage(), 500, 'restore_failed');
        }
        $this->setTrashVisibility($disk, $fs, $target, false, 'public');

        foreach (($entry['variants'] ?? []) as $vsize) {
            $src = $trashDir . '/v/' . $vsize . '.webp';
            try {
                if ($fs->fileExists($src)) {
                    $fs->move($src, $this->variantKey($target, (string) $vsize));
                }
            } catch (\Throwable $e) { /* best effort */ }
        }

        if (!empty($entry['meta'])) {
            $this->meta->save($disk, $target, $entry['meta']);
        }
        // History travelled into the trash next to the payload — bring it back. An
        // entry trashed before that still has it under the original key: re-key it
        // too when restoring somewhere new (a no-op when there is none, and the
        // module refuses to take it from under a live file).
        $this->moveVersions($disk, $trashDir . '/' . $payloadName, $target, $fs);
        $this->moveVersions($disk, (string) ($entry['original_key'] ?? $target), $target, $fs);
        $this->meta->trackParents($disk, $target);

        try { $fs->deleteDirectory($trashDir); } catch (\Throwable $e) { /* best effort */ }
        $this->meta->removeTrash($disk, $id);

        return ['restored' => true, 'key' => $this->outKey($target)];
    }

    /**
     * Restore a trashed directory subtree to $target.
     *
     * The payload moves back whole (driver-aware, like rename), then `dirs[]` is
     * re-created so a subdirectory that holds no files comes back even where a
     * marker didn't survive, and `files[]` replays metadata + the folder index.
     * Entries written before `dirs[]` existed simply carry none: their payload
     * only ever held files, so they restore exactly as they used to — the folders
     * implied by the file paths.
     */
    private function restoreDirectory(string $disk, string $id, array $entry, string $target, string $trashDir, $fs): array
    {
        $target  = rtrim($target, '/');
        $payload = $trashDir . '/payload';

        $hasPayload = false;
        try { $hasPayload = $fs->directoryExists($payload); } catch (\Throwable $e) { /* treat as empty */ }

        if ($hasPayload) {
            try {
                $this->moveDirectoryTree($disk, $fs, $payload, $target);
            } catch (\Throwable $e) {
                throw new ApiException('Restore failed: ' . $e->getMessage(), 500, 'restore_failed');
            }
            $this->setTrashVisibility($disk, $fs, $target, true, 'public');
        } else {
            try {
                $fs->createDirectory($target); // an entirely empty folder has no payload
            } catch (\Throwable $e) { /* best effort */ }
        }

        foreach (($entry['dirs'] ?? []) as $d) {
            $rel = $this->safeRel((string) $d); // strip any traversal
            if ($rel === '') {
                continue;
            }
            try { $fs->createDirectory($target . '/' . $rel); } catch (\Throwable $e) { /* best effort */ }
            // trackDir ignores reserved segments, so `_variants/` stays out of the index.
            $this->meta->trackDir($disk, $target . '/' . $rel);
        }

        foreach (($entry['files'] ?? []) as $f) {
            $rel = $this->safeRel((string) ($f['rel'] ?? '')); // strip any traversal
            if ($rel === '') {
                continue;
            }
            $dst = $target . '/' . $rel;
            if (!empty($f['meta'])) {
                $this->meta->save($disk, $dst, $f['meta']);
            }
            // Rebuild the folder tree in the dirs index for real files.
            if (strpos($rel, '_variants/') === false) {
                $this->meta->trackParents($disk, $dst);
            }
        }
        $this->meta->trackDir($disk, $target);
        // Same as the file case: history comes back from the trash payload, and an
        // older entry's history is re-keyed from the original path when restoring
        // somewhere new.
        $this->moveVersionsDir($disk, $payload, $target, $fs);
        $origin = rtrim((string) ($entry['original_key'] ?? $target), '/');
        if ($origin !== $target) {
            $this->moveVersionsDir($disk, $origin, $target, $fs);
        }

        try { $fs->deleteDirectory($trashDir); } catch (\Throwable $e) { /* best effort */ }
        $this->meta->removeTrash($disk, $id);

        return ['restored' => true, 'key' => $this->outKey($target)];
    }

    /**
     * Quota + max_files for a restore. The trash lives at the disk root
     * (`_fluxfiles/trash/`), OUTSIDE a tenant prefix, so trashed bytes stop
     * counting against max_storage/max_files — without this, trash-then-restore
     * lands files past both limits. Measured from the payload actually on disk,
     * not the manifest's `size`. With no prefix the trash sits inside the
     * counted root already, so only the file count can change.
     */
    private function assertRestoreFits(string $disk, array $entry, string $trashDir, $fs): void
    {
        if ($this->quotaManager === null || ($this->claims->maxStorageMb <= 0 && $this->claims->maxFiles <= 0)) {
            return;
        }
        $bytes = 0;
        $files = 0;
        if (!empty($entry['is_dir'])) {
            $payload = $trashDir . '/payload';
            try {
                foreach ($fs->listContents($payload, true) as $item) {
                    if (!$item->isFile()) {
                        continue;
                    }
                    $bytes += (int) ($item->fileSize() ?? 0);
                    if (!self::isReservedKey(substr($item->path(), strlen($payload) + 1))) {
                        $files++;
                    }
                }
            } catch (\Throwable $e) {
                $bytes = (int) ($entry['size'] ?? 0); // fall back to the manifest
                $files = count($entry['files'] ?? []);
            }
        } else {
            $payload = $trashDir . '/' . basename((string) ($entry['basename'] ?? ''));
            $bytes = $this->existingSize($fs, $payload) ?? (int) ($entry['size'] ?? 0);
            $files = 1;
        }
        if (trim($this->claims->pathPrefix, '/') === '') {
            $bytes = 0;
        }
        if ($this->claims->maxStorageMb > 0 && $bytes > 0) {
            $this->quotaManager->assertQuota($disk, $this->claims->pathPrefix, $bytes, $this->claims->maxStorageMb);
        }
        if ($this->claims->maxFiles > 0 && $files > 0) {
            $this->quotaManager->assertFileCount($disk, $this->claims->pathPrefix, $files, $this->claims->maxFiles);
        }
    }

    /** List trash entries the caller may see (scoped to prefix + owner). */
    public function listTrash(string $disk): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $out = [];
        foreach ($this->meta->allTrash($disk) as $id => $e) {
            if (!$this->trashVisible($e)) {
                continue;
            }
            $out[] = [
                'trash_id'     => $id,
                'name'         => $e['basename'] ?? basename((string) ($e['original_key'] ?? '')),
                'original_key' => $this->outKey((string) ($e['original_key'] ?? '')),
                'is_dir'       => !empty($e['is_dir']),
                'size'         => $e['size'] ?? 0,
                'deleted_at'   => $e['deleted_at'] ?? 0,
                'deleted_by'   => $e['deleted_by'] ?? '',
            ];
        }
        usort($out, fn($a, $b) => ($b['deleted_at'] ?? 0) <=> ($a['deleted_at'] ?? 0));
        return $out;
    }

    /** Permanently delete one trash item. */
    public function purgeTrash(string $disk, string $id): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $this->assertSafeTrashId($id);
        $entry = $this->meta->getTrash($disk, $id);
        if ($entry === null) {
            throw new ApiException('Trash item not found', 404, 'not_found');
        }
        $this->assertTrashScope($entry);
        // A hold binds to the tenant-visible path, so it must still apply after
        // the file has been soft-deleted — check the trash entry's ORIGINAL key,
        // not the internal _fluxfiles/trash/<id>/… path it currently lives at.
        $this->assertNoActiveHold($disk, (string) ($entry['original_key'] ?? ''));

        $fs = $this->disks->disk($disk);
        // Permanent delete → the version history (Versioning module) goes too,
        // same as `/delete`; otherwise "trash then purge" leaves the old bytes
        // restorable under `_fluxfiles/versions/` (erasure + storage leak).
        $this->purgeTrashedVersions($disk, ['_id' => $id] + $entry, $fs);
        try { $fs->deleteDirectory('_fluxfiles/trash/' . $id); } catch (\Throwable $e) { /* best effort */ }
        $this->meta->removeTrash($disk, $id);

        return ['purged' => true];
    }

    /**
     * Permanently delete every trash item the caller may see. Held entries are
     * SKIPPED, not aborted wholesale — a deliberate, visible trade-off (the
     * caller sees `held` in the response) rather than a silent partial success
     * or an all-or-nothing failure on one held item blocking the rest.
     */
    public function emptyTrash(string $disk): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $fs = $this->disks->disk($disk);
        $n = 0;
        $held = 0;
        foreach ($this->meta->allTrash($disk) as $id => $e) {
            if (!$this->trashVisible($e)) {
                continue;
            }
            if ($this->meta->holdBlocking($disk, (string) ($e['original_key'] ?? '')) !== null) {
                $held++;
                continue;
            }
            $this->purgeTrashedVersions($disk, ['_id' => (string) $id] + $e, $fs); // permanent → history goes too
            try { $fs->deleteDirectory('_fluxfiles/trash/' . $id); } catch (\Throwable $ex) { /* best effort */ }
            $this->meta->removeTrash($disk, $id);
            $n++;
        }
        return ['purged' => $n, 'held' => $held];
    }

    /**
     * Reject a trash id that could escape the `_fluxfiles/trash/` namespace when
     * used in a path. Ids are hex tokens; a tampered index (BYOB) can't turn an
     * id into a traversal payload.
     */
    private function assertSafeTrashId(string $id): void
    {
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new ApiException('Invalid trash id', 400, 'invalid_trash_id');
        }
    }

    /** Strip any path-traversal from a relative path read back from a manifest. */
    private function safeRel(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        $parts = [];
        foreach (explode('/', $rel) as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                continue;
            }
            $parts[] = $p;
        }
        return implode('/', $parts);
    }

    /** A trash entry is visible when its origin is in scope and (owner-only) ours. */
    private function trashVisible(array $entry): bool
    {
        if (!$this->claims->isPathInScope((string) ($entry['original_key'] ?? ''))) {
            return false;
        }
        if ($this->claims->ownerOnly && ($entry['deleted_by'] ?? null) !== $this->claims->userId) {
            return false;
        }
        return true;
    }

    private function assertTrashScope(array $entry): void
    {
        if (!$this->trashVisible($entry)) {
            throw new ApiException('Trash item not in scope', 403, 'forbidden');
        }
    }

    public function rename(string $disk, string $path, string $newName): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped, $disk);
        $this->assertNotHidden($scoped);
        $this->assertOwner($disk, $scoped);
        // Renaming a held path would let it evade holdBlocking()'s path-string
        // match (§6 of the design doc) — blocked as a security requirement, not
        // just a nicety.
        $this->assertNoActiveHold($disk, $scoped);

        $newName = trim($newName);
        if ($newName === '') {
            throw new ApiException('New name cannot be empty', 400, 'name_empty');
        }
        // A dot-only name is a path segment, not a name: `..` would resolve the
        // target to the parent directory.
        if (preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $newName) || trim($newName, '.') === '') {
            throw new ApiException('Name contains invalid characters', 400, 'name_invalid');
        }

        $this->assertSafeFilename($newName);

        $fs = $this->disks->disk($disk);

        $dir = dirname($scoped);
        $newPath = ($dir !== '.' && $dir !== '') ? $dir . '/' . $newName : $newName;
        $this->assertNotSystem($newPath, $disk);
        // show_hidden=false: renaming `.env` → `env.txt` would expose it, and the
        // reverse would hide a file from the token that owns it.
        $this->assertNotHidden($newPath);

        if ($scoped === $newPath) {
            throw new ApiException('New name is the same as current name', 400, 'name_same');
        }

        // Check target doesn't already exist
        $this->assertTargetAvailable($fs, $newPath);

        $isDir = false;
        try {
            $isDir = $fs->directoryExists($scoped);
        } catch (\Throwable $e) {
            // Not a directory
        }

        // For files, the extension is fixed at upload time (it ties the stored
        // MIME, variants and the JWT allowedExt policy to the file). Renaming
        // may only change the base name — never the extension — so a token that
        // can only upload e.g. images cannot rename a.png → a.svg to bypass it.
        if (!$isDir) {
            $oldExt = strtolower(pathinfo($scoped, PATHINFO_EXTENSION));
            $newExt = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
            if ($oldExt !== $newExt) {
                throw new ApiException(
                    'Changing the file extension is not allowed',
                    400,
                    'ext_changed',
                    ['from' => $oldExt, 'to' => $newExt]
                );
            }
        }

        if ($isDir) {
            $this->assertOwnsTree($disk, $scoped);
            // Relocate the whole subtree — files, `_variants/` (it lives inside
            // the folder) and subdirectories that hold no files at all. This runs
            // before any index bookkeeping so a failure can never leave the folder
            // index describing a directory that is no longer there.
            $this->moveDirectoryTree($disk, $fs, $scoped, $newPath);
            // Move metadata for children
            $this->meta->renameChildren($disk, $scoped, $newPath);
            $this->meta->renameDirPrefix($disk, $scoped, $newPath);
            // Version history is keyed by scoped path — re-key every child's.
            $this->moveVersionsDir($disk, $scoped, $newPath, $fs);
        } else {
            $fs->move($scoped, $newPath);
            // Move metadata
            $this->moveMetadata($disk, $scoped, $disk, $newPath);
            // Move image variants
            $this->moveVariants($disk, $scoped, $disk, $newPath);
            // Move version history (Versioning module) with the file
            $this->moveVersions($disk, $scoped, $newPath, $fs);

            $this->meta->trackParents($disk, $newPath);
        }

        return [
            'key'  => $this->outKey($newPath),
            'name' => $newName,
            'url'  => $isDir ? null : $this->downloadUrl($disk, $newPath),
        ];
    }

    public function move(string $disk, string $from, string $to): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scopedFrom = $this->scopedPath($from);
        $this->assertNotSystem($scopedFrom, $disk);
        $this->assertNotHidden($scopedFrom);
        $this->assertOwner($disk, $scopedFrom);
        // Source-side only — moving a file INTO an existing hold's subtree is
        // fine (§6 of the design doc); only relocating a held path is blocked.
        $this->assertNoActiveHold($disk, $scopedFrom);
        $scopedTo   = $this->scopedPath($to);
        $this->assertNotSystem($scopedTo, $disk);
        $this->assertNotHidden($scopedTo);
        $fs = $this->disks->disk($disk);
        $this->assertTargetAvailable($fs, $scopedTo);
        $isDir = false;
        try {
            $isDir = $fs->directoryExists($scopedFrom);
        } catch (\Throwable $e) {
            // ignore
        }

        if ($isDir) {
            $this->assertOwnsTree($disk, $scopedFrom);
            $this->moveDirectoryTree($disk, $fs, $scopedFrom, $scopedTo);
        } else {
            $this->assertRelocationExt($scopedFrom, $scopedTo);
            $fs->move($scopedFrom, $scopedTo);
        }

        // Keep metadata + folder index best-effort in sync
        if ($isDir) {
            $this->meta->renameChildren($disk, $scopedFrom, $scopedTo);
            $this->meta->renameDirPrefix($disk, $scopedFrom, $scopedTo);
            $this->moveVersionsDir($disk, $scopedFrom, $scopedTo, $fs);
        } else {
            $this->moveMetadata($disk, $scopedFrom, $disk, $scopedTo);
            $this->moveVariants($disk, $scopedFrom, $disk, $scopedTo);
            $this->moveVersions($disk, $scopedFrom, $scopedTo, $fs);
            $this->meta->trackParents($disk, $scopedTo);
        }

        return ['key' => $this->outKey($scopedTo)];
    }

    public function copy(string $disk, string $from, string $to): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $this->assertPerm('write');

        $scopedFrom = $this->scopedPath($from);
        $this->assertNotSystem($scopedFrom, $disk);
        $this->assertNotHidden($scopedFrom);
        $this->assertOwner($disk, $scopedFrom);
        $scopedTo   = $this->scopedPath($to);
        $this->assertNotSystem($scopedTo, $disk);
        $this->assertNotHidden($scopedTo);
        $fs = $this->disks->disk($disk);
        $this->assertTargetAvailable($fs, $scopedTo);

        // Recursive folder copy isn't supported; without this guard the adapter
        // throws a raw UnableToCopyFile ("the first argument to copy() cannot be
        // a directory") instead of the API envelope.
        try {
            $isDir = $fs->directoryExists($scopedFrom);
        } catch (\Throwable $e) {
            $isDir = false;
        }
        if ($isDir) {
            throw new ApiException('Folders cannot be copied', 400, 'copy_dir_unsupported');
        }

        $this->assertRelocationExt($scopedFrom, $scopedTo);

        // Quota/file-count check — same-disk copy() creates a new object just
        // like upload() does, so it must be bounded the same way (crossCopy()
        // already checked this on the destination disk; this closed the gap
        // where repeatedly copying one file in-place bypassed both limits).
        if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0) {
            $fileSize = (int) ($fs->fileSize($scopedFrom) ?: 0);
            $this->quotaManager->assertQuota(
                $disk,
                $this->claims->pathPrefix,
                $fileSize,
                $this->claims->maxStorageMb
            );
        }
        if ($this->quotaManager !== null && $this->claims->maxFiles > 0) {
            $this->quotaManager->assertFileCount(
                $disk,
                $this->claims->pathPrefix,
                1,
                $this->claims->maxFiles
            );
        }

        $fs->copy($scopedFrom, $scopedTo);

        $this->copyMetadata($disk, $scopedFrom, $disk, $scopedTo);
        $this->copyVariants($disk, $scopedFrom, $disk, $scopedTo);
        $this->meta->trackParents($disk, $scopedTo);

        return ['key' => $this->outKey($scopedTo)];
    }

    public function crossCopy(string $srcDisk, string $srcPath, string $dstDisk, string $dstPath): array
    {
        $this->assertDisk($srcDisk);
        $this->assertDisk($dstDisk);
        $this->assertPerm('read');
        $this->assertPerm('write');

        if ($srcDisk === $dstDisk) {
            return $this->copy($srcDisk, $srcPath, $dstPath);
        }

        $scopedSrc = $this->scopedPath($srcPath);
        $this->assertNotSystem($scopedSrc, $srcDisk);
        $this->assertNotHidden($scopedSrc);
        $this->assertOwner($srcDisk, $scopedSrc);
        $scopedDst = $this->scopedPath($dstPath);
        $this->assertNotSystem($scopedDst, $dstDisk);
        $this->assertNotHidden($scopedDst);
        $this->assertRelocationExt($scopedSrc, $scopedDst);

        $srcFs = $this->disks->disk($srcDisk);
        $dstFs = $this->disks->disk($dstDisk);

        // Clean 404 for a missing source (else readStream throws a raw 500).
        if (!$srcFs->fileExists($scopedSrc)) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        // Don't silently clobber an existing destination — match same-disk copy()
        // (409 name_exists), so cross-disk can't overwrite another user's file either.
        $this->assertTargetAvailable($dstFs, $scopedDst);

        // Quota check on destination
        if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0) {
            $fileSize = $srcFs->fileSize($scopedSrc);
            $this->quotaManager->assertQuota(
                $dstDisk,
                $this->claims->pathPrefix,
                $fileSize,
                $this->claims->maxStorageMb
            );
        }

        if ($this->quotaManager !== null && $this->claims->maxFiles > 0) {
            $this->quotaManager->assertFileCount($dstDisk, $this->claims->pathPrefix, 1, $this->claims->maxFiles);
        }

        // Stream the file across disks
        $stream = $srcFs->readStream($scopedSrc);
        try {
            $dstFs->writeStream($scopedDst, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->copyMetadata($srcDisk, $scopedSrc, $dstDisk, $scopedDst);
        $this->copyVariants($srcDisk, $scopedSrc, $dstDisk, $scopedDst);
        $this->meta->trackParents($dstDisk, $scopedDst);

        return [
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->downloadUrl($dstDisk, $scopedDst),
            'src_disk' => $srcDisk,
            'dst_disk' => $dstDisk,
        ];
    }

    public function crossMove(string $srcDisk, string $srcPath, string $dstDisk, string $dstPath): array
    {
        $this->assertDisk($srcDisk);
        $this->assertDisk($dstDisk);
        $this->assertPerm('write');
        $this->assertPerm('delete');

        if ($srcDisk === $dstDisk) {
            return $this->move($srcDisk, $srcPath, $dstPath);
        }

        $scopedSrc = $this->scopedPath($srcPath);
        $this->assertNotSystem($scopedSrc, $srcDisk);
        $this->assertNotHidden($scopedSrc);
        $this->assertOwner($srcDisk, $scopedSrc);
        // Source-side only, same as move() above.
        $this->assertNoActiveHold($srcDisk, $scopedSrc);
        $scopedDst = $this->scopedPath($dstPath);
        $this->assertNotSystem($scopedDst, $dstDisk);
        $this->assertNotHidden($scopedDst);
        $this->assertRelocationExt($scopedSrc, $scopedDst);

        $srcFs = $this->disks->disk($srcDisk);
        $dstFs = $this->disks->disk($dstDisk);

        // Clean 404 for a missing source; don't silently clobber the destination.
        if (!$srcFs->fileExists($scopedSrc)) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        $this->assertTargetAvailable($dstFs, $scopedDst);

        // Quota check on destination — crossCopy() already does this; crossMove()
        // writes the same way (writeStream to $dstFs) and must be bounded the
        // same way, since QuotaManager enforces maxStorageMb per-disk, not
        // globally, so moving in from an unconstrained disk must still respect
        // the destination's cap.
        if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0) {
            $fileSize = $srcFs->fileSize($scopedSrc);
            $this->quotaManager->assertQuota(
                $dstDisk,
                $this->claims->pathPrefix,
                $fileSize,
                $this->claims->maxStorageMb
            );
        }

        if ($this->quotaManager !== null && $this->claims->maxFiles > 0) {
            $this->quotaManager->assertFileCount($dstDisk, $this->claims->pathPrefix, 1, $this->claims->maxFiles);
        }

        $stream = $srcFs->readStream($scopedSrc);
        try {
            $dstFs->writeStream($scopedDst, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->moveMetadata($srcDisk, $scopedSrc, $dstDisk, $scopedDst);
        $this->moveVariants($srcDisk, $scopedSrc, $dstDisk, $scopedDst);
        $srcFs->delete($scopedSrc);
        // Version history is per-disk (it lives under the SOURCE disk's
        // `_fluxfiles/versions/`), so it can't follow the file across disks —
        // and left behind it would be orphaned there, or silently re-attach to
        // an unrelated file later created at the same source key.
        $this->purgeVersions($srcDisk, $scopedSrc, $srcFs);

        $this->meta->trackParents($dstDisk, $scopedDst);

        return [
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->downloadUrl($dstDisk, $scopedDst),
            'src_disk' => $srcDisk,
            'dst_disk' => $dstDisk,
        ];
    }

    /**
     * Relocate a whole directory subtree, empty subdirectories included.
     *
     * Local and SFTP expose real directories, so one adapter move() is a rename
     * syscall: atomic, and it carries every child along — files, `_variants/`,
     * and folders that hold no files at all. Object stores (S3/R2) have no
     * directories: there move() is CopyObject+delete on a single key and throws
     * for a prefix, so the tree is walked instead — directory markers are
     * recreated at the destination *before* the files move, which is what keeps
     * a file-less folder from disappearing.
     *
     * The source is only dropped once the destination genuinely exists, so a
     * failed relocation can never leave the caller with neither.
     */
    private function moveDirectoryTree(string $disk, $fs, string $from, string $to): void
    {
        $from = rtrim($from, '/');
        $to   = rtrim($to, '/');

        // Moving a folder inside its own subtree would have the walk below drop
        // the source *and* the destination it just wrote into. Local/SFTP fail
        // this at the rename syscall, but only with a raw 500 — guard both.
        if ($to === $from || str_starts_with($to . '/', $from . '/')) {
            throw new ApiException('Cannot move a folder into itself', 400, 'move_into_self');
        }

        if ($this->hasRealDirectories($disk)) {
            $fs->move($from, $to);
            return;
        }

        // Collect first so moving objects doesn't disturb the recursive listing.
        $prefix = $from . '/';
        $dirs   = [];
        $files  = [];
        foreach ($fs->listContents($from, true) as $item) {
            $rel = substr($item->path(), strlen($prefix));
            if ($rel === '' || $rel === false) {
                continue;
            }
            if ($item->isFile()) {
                $files[] = $rel;
            } else {
                $dirs[] = $rel;
            }
        }

        // The destination must exist even when nothing is moved into it.
        $fs->createDirectory($to);
        foreach ($dirs as $rel) {
            try {
                $fs->createDirectory($to . '/' . $rel);
            } catch (\Throwable $e) {
                // Markers are best effort — a prefix holding files is recreated
                // implicitly by the moves below.
            }
        }
        foreach ($files as $rel) {
            $fs->move($prefix . $rel, $to . '/' . $rel);
        }

        if (!$fs->directoryExists($to)) {
            throw new ApiException('Folder could not be relocated', 500, 'move_failed');
        }
        try {
            $fs->deleteDirectory($from);
        } catch (\Throwable $e) {
            // Destination is in place; a leftover source marker is harmless.
        }
    }

    /**
     * True when the disk's driver has real directories, so a single move()
     * renames the whole subtree. Object stores (s3/r2) don't — anything not
     * known to have them takes the safe per-object walk.
     */
    private function hasRealDirectories(string $disk): bool
    {
        $driver = $this->disks->config($disk)['driver'] ?? 'local';
        return $driver === 'local' || $driver === 'sftp';
    }

    private function copyMetadata(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $existing = $this->meta->get($srcDisk, $srcKey);
        if ($existing) {
            $this->meta->save($dstDisk, $dstKey, $existing);
        }
    }

    private function moveMetadata(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        // Same disk: the save + delete become one index write.
        $batch = $srcDisk === $dstDisk;
        if ($batch) {
            $this->beginIndexBatch($srcDisk);
        }
        try {
            $this->copyMetadata($srcDisk, $srcKey, $dstDisk, $dstKey);
            $this->meta->delete($srcDisk, $srcKey);
        } finally {
            if ($batch) {
                $this->endIndexBatch($srcDisk);
            }
        }
    }

    /**
     * Group metadata writes into one index update where the backend supports it
     * (the JSON backend rewrites its disk-wide index per write; the DB backends
     * write per row and have nothing to batch).
     */
    private function beginIndexBatch(string $disk): void
    {
        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->beginIndexBatch($disk);
        }
    }

    private function endIndexBatch(string $disk): void
    {
        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->endIndexBatch($disk);
        }
    }

    private function copyVariants(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $this->transferVariants($srcDisk, $srcKey, $dstDisk, $dstKey, false);
    }

    private function moveVariants(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $this->transferVariants($srcDisk, $srcKey, $dstDisk, $dstKey, true);
    }

    private function transferVariants(
        string $srcDisk,
        string $srcKey,
        string $dstDisk,
        string $dstKey,
        bool $deleteSource
    ): void {
        $srcFs = $this->disks->disk($srcDisk);
        $dstFs = $this->disks->disk($dstDisk);
        $sizes = ['thumb', 'medium', 'large'];

        foreach ($sizes as $size) {
            $srcVariant = $this->variantKey($srcKey, $size);
            $dstVariant = $this->variantKey($dstKey, $size);

            try {
                if (!$srcFs->fileExists($srcVariant)) {
                    continue;
                }

                $stream = $srcFs->readStream($srcVariant);
                try {
                    $dstFs->writeStream($dstVariant, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                if ($deleteSource) {
                    $srcFs->delete($srcVariant);
                }
            } catch (\Throwable $e) {
                error_log("FluxFiles: Variant transfer failed ({$size}) — " . $e->getMessage());
            }
        }
    }

    private function deleteVariants(string $disk, string $key): void
    {
        $fs = $this->disks->disk($disk);
        $sizes = ['thumb', 'medium', 'large'];
        foreach ($sizes as $size) {
            // Delete both the current (extension-aware) and legacy (extension-less)
            // variant keys so old caches don't linger after the naming change.
            foreach ([$this->variantKey($key, $size), $this->legacyVariantKey($key, $size)] as $variant) {
                try {
                    if ($fs->fileExists($variant)) {
                        $fs->delete($variant);
                    }
                } catch (\Throwable $e) {
                    // Best effort cleanup
                }
            }
        }
    }

    private function deleteVariantsDir(string $disk, string $dirPath): void
    {
        $fs = $this->disks->disk($disk);
        $variantsDir = ($dirPath !== '.' && $dirPath !== '') ? $dirPath . '/_variants' : '_variants';
        try {
            if ($fs->directoryExists($variantsDir)) {
                $fs->deleteDirectory($variantsDir);
            }
        } catch (\Throwable $e) {
            // Best effort cleanup
        }
    }

    private function variantKey(string $key, string $size): string
    {
        $dir = dirname($key);
        // Full filename incl. extension so `a.jpg` and `a.png` don't share variants.
        // Must match ImageOptimizer::process(). (Legacy extension-less variants from
        // before this change are orphaned — harmless cache, cleaned by deleteVariants.)
        $basename = pathinfo($key, PATHINFO_BASENAME);
        $variantsDir = ($dir !== '.' && $dir !== '') ? $dir . '/_variants' : '_variants';
        return $variantsDir . '/' . $basename . '_' . $size . '.webp';
    }

    /** Legacy (pre-extension) variant key — extension-less basename. Used only to
     *  clean up old variants on delete so they don't linger after the naming change. */
    private function legacyVariantKey(string $key, string $size): string
    {
        $dir = dirname($key);
        $basename = pathinfo($key, PATHINFO_FILENAME);
        $variantsDir = ($dir !== '.' && $dir !== '') ? $dir . '/_variants' : '_variants';
        return $variantsDir . '/' . $basename . '_' . $size . '.webp';
    }

    public function mkdir(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped, $disk);
        $fs = $this->disks->disk($disk);

        // Refuse to "create" a folder that already exists (as a folder OR a file
        // with that name). Flysystem's createDirectory is idempotent and would
        // silently 200, so without this an existing folder looks freshly created —
        // the OS (Finder/Explorer) reports a conflict here, and so should we.
        if ($fs->directoryExists($scoped) || $fs->fileExists($scoped)) {
            throw new ApiException('A folder with this name already exists', 409, 'folder_exists', ['name' => basename($scoped)]);
        }

        $fs->createDirectory($scoped);

        $this->meta->trackDir($disk, $scoped);
        $this->meta->trackParents($disk, $scoped);

        return ['created' => true];
    }

    /**
     * @param string  $disk
     * @param string  $path
     * @param int     $x
     * @param int     $y
     * @param int     $width
     * @param int     $height
     * @param ?string $savePath If set, save cropped version as a new file; otherwise overwrite
     * @return array
     */
    public function cropImage(
        string $disk,
        string $path,
        int $x,
        int $y,
        int $width,
        int $height,
        ?string $savePath = null
    ): array {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scopedSrc = $this->scopedPath($path);
        $this->assertNotSystem($scopedSrc, $disk);
        $this->assertNotHidden($scopedSrc);
        $this->assertOwner($disk, $scopedSrc);
        $fs = $this->disks->disk($disk);

        $imageData = $fs->read($scopedSrc);
        $ext = strtolower(pathinfo($scopedSrc, PATHINFO_EXTENSION));
        $format = in_array($ext, ['jpg', 'jpeg']) ? 'jpg' : ($ext === 'webp' ? 'webp' : 'png');

        $result = $this->imageOptimizer->crop($imageData, $x, $y, $width, $height, $format);
        $scopedDst = $savePath ? $this->scopedPath($savePath) : $scopedSrc;
        $this->assertNotSystem($scopedDst, $disk);
        $this->assertNotHidden($scopedDst);
        if ($savePath !== null) {
            // Extension is immutable — a "save as" crop keeps the source format,
            // same rule applyWatermark() enforces.
            if (strtolower(pathinfo($scopedDst, PATHINFO_EXTENSION)) !== $ext) {
                throw new ApiException('Cannot change the file extension', 422, 'ext_changed');
            }
            $this->validateUploadName(basename($scopedDst), 0);
            // "Save as" to a different path must not silently clobber an existing file
            // (in-place crop, where dst == src, is still allowed to overwrite).
            if ($scopedDst !== $scopedSrc) {
                $this->assertTargetAvailable($fs, $scopedDst);
            }
        }
        $this->writeScopedFile($disk, $scopedDst, $result['data']);
        if ($scopedDst !== $scopedSrc) {
            // "Save as copy" creates a new file — carry over metadata (title/tags/
            // uploaded_by) and register it in the search + folder index, same as copy().
            $this->copyMetadata($disk, $scopedSrc, $disk, $scopedDst);
            $this->meta->trackParents($disk, $scopedDst);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ffcrop_');
        file_put_contents($tmpFile, $result['data']);

        $variants = null;
        try {
            $variants = $this->variantUrls($disk, $this->imageOptimizer->process($fs, $scopedDst, $tmpFile));
        } catch (\Throwable $e) {
            error_log('FluxFiles: Variant regeneration after crop failed — ' . $e->getMessage());
        } finally {
            @unlink($tmpFile);
        }

        return $this->unscopeItems([[
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->downloadUrl($disk, $scopedDst),
            'width'    => $result['width'],
            'height'   => $result['height'],
            'variants' => $variants,
        ]])[0];
    }

    /**
     * Burn a watermark into an image and save it (in place or "save as"). The free
     * drag-and-drop editor counterpart to the on-the-fly /img overlay. $wm carries
     * {type, logo_data|text, x, y, scale|font_size, opacity, color}. Mirrors
     * cropImage: write perm + owner + extension-immutable + variant regen.
     *
     * @param array<string,mixed> $wm
     * @return array<string,mixed>
     */
    public function applyWatermark(string $disk, string $path, array $wm, ?string $savePath = null): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        // Burn-in and the serve-time OVERLAY are mutually exclusive. An overlay
        // token is preview-only (the clean file is withheld), so burning a second
        // mark INTO a file you can't even retrieve would just double-watermark it.
        // Burn in with a normal, downloadable token instead.
        if ($this->claims->watermark !== null) {
            throw new ApiException('Burn-in is not available on a preview/overlay-watermark token', 409, 'watermark_overlay_active');
        }

        $scopedSrc = $this->scopedPath($path);
        $this->assertNotSystem($scopedSrc, $disk);
        $this->assertNotHidden($scopedSrc);
        $this->assertOwner($disk, $scopedSrc);
        $fs = $this->disks->disk($disk);
        if (!$fs->fileExists($scopedSrc)) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        if (!$this->imageOptimizer->isImage($scopedSrc)) {
            throw new ApiException('Not an image', 415, 'not_image');
        }

        $ext = strtolower(pathinfo($scopedSrc, PATHINFO_EXTENSION));
        $format = in_array($ext, ['jpg', 'jpeg'], true) ? 'jpg' : ($ext === 'webp' ? 'webp' : 'png');

        $scopedDst = $savePath ? $this->scopedPath($savePath) : $scopedSrc;
        $this->assertNotHidden($scopedDst);
        $inPlace = ($scopedDst === $scopedSrc);
        if ($inPlace) {
            // A burn-in rewrites the held record's bytes — refuse before the
            // original is snapshotted, not after.
            $this->assertNoActiveHold($disk, $scopedSrc);
        }

        // Non-destructive burn-in: keep the true original so re-editing never STACKS
        // a second mark and "Remove watermark" can restore it (the model every
        // serious tool uses — Image Watermark / Easy Watermark / Envira). The
        // backup is made once, before the first in-place burn; re-edits then read
        // FROM the clean original, not the already-watermarked file.
        $backupKey = $this->watermarkBackupKey($scopedSrc);
        $hasBackup = $fs->fileExists($backupKey);
        if ($inPlace && !$hasBackup) {
            $fs->write($backupKey, (string) $fs->read($scopedSrc)); // first burn → snapshot the original
            $hasBackup = true;
        }
        // Always burn from the clean original when we have one (re-edit = re-burn
        // from scratch at the new position), else from the source itself.
        $sourceBytes = $hasBackup ? (string) $fs->read($backupKey) : (string) $fs->read($scopedSrc);
        $result = $this->imageOptimizer->burnWatermark($sourceBytes, $wm, $format);

        $this->assertNotSystem($scopedDst, $disk);
        if (!$inPlace) {
            // Extension is immutable — a watermarked copy keeps the source format.
            if (strtolower(pathinfo($scopedDst, PATHINFO_EXTENSION)) !== $ext) {
                throw new ApiException('Cannot change the file extension', 422, 'ext_changed');
            }
            $this->validateUploadName(basename($scopedDst), 0);
            $this->assertTargetAvailable($fs, $scopedDst);
        }
        $this->writeScopedFile($disk, $scopedDst, $result['data']);
        if ($inPlace) {
            // Flag so the UI can offer "Remove watermark" (restore from backup).
            $this->meta->save($disk, $scopedDst, ['watermarked' => true]);
        } else {
            // "Save as copy" creates a new file — carry over metadata (title/tags/
            // uploaded_by) and register it in the search + folder index, same as copy().
            $this->copyMetadata($disk, $scopedSrc, $disk, $scopedDst);
            $this->meta->trackParents($disk, $scopedDst);
        }

        // Regenerate variants for the new bytes (best-effort), like cropImage.
        $tmpFile = tempnam(sys_get_temp_dir(), 'ffwm_');
        $variants = null;
        try {
            file_put_contents($tmpFile, $result['data']);
            $variants = $this->variantUrls($disk, $this->imageOptimizer->process($fs, $scopedDst, $tmpFile));
        } catch (\Throwable $e) {
            error_log('FluxFiles: Variant regen after watermark failed — ' . $e->getMessage());
        } finally {
            @unlink($tmpFile);
        }

        return $this->unscopeItems([[
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->downloadUrl($disk, $scopedDst),
            'width'    => $result['width'],
            'height'   => $result['height'],
            'variants' => $variants,
            'watermarked' => $inPlace ? true : null,
        ]])[0];
    }

    /** Storage key of the kept pre-watermark original for an (already-scoped) image. */
    private function watermarkBackupKey(string $scopedKey): string
    {
        return '_fluxfiles/originals/' . ltrim($scopedKey, '/');
    }

    /**
     * Remove an in-place burned-in watermark by restoring the kept original
     * (the non-destructive counterpart to applyWatermark). 404 when the image was
     * never watermarked in place (no backup to restore). Write perm + owner +
     * variant regen, mirroring applyWatermark.
     *
     * @return array<string,mixed>
     */
    public function removeWatermark(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped, $disk);
        $this->assertOwner($disk, $scoped);
        $this->assertNoActiveHold($disk, $scoped);
        $fs = $this->disks->disk($disk);

        $backupKey = $this->watermarkBackupKey($scoped);
        if (!$fs->fileExists($backupKey)) {
            throw new ApiException('No watermark to remove', 404, 'no_watermark_backup');
        }
        $original = (string) $fs->read($backupKey);
        $fs->write($scoped, $original);
        $fs->delete($backupKey);
        $this->meta->save($disk, $scoped, ['watermarked' => false]);

        // Regenerate variants from the restored original (best-effort).
        $tmpFile = tempnam(sys_get_temp_dir(), 'ffwm_');
        $variants = null;
        try {
            file_put_contents($tmpFile, $original);
            $variants = $this->variantUrls($disk, $this->imageOptimizer->process($fs, $scoped, $tmpFile));
        } catch (\Throwable $e) {
            error_log('FluxFiles: Variant regen after watermark removal failed — ' . $e->getMessage());
        } finally {
            @unlink($tmpFile);
        }

        return $this->unscopeItems([[
            'key'      => $this->outKey($scoped),
            'url'      => $this->downloadUrl($disk, $scoped),
            'variants' => $variants,
            'watermarked' => false,
        ]])[0];
    }

    public function aiTag(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        if ($this->aiTagger === null) {
            throw new ApiException('AI tagging is not configured', 400, 'ai_not_configured');
        }

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);
        $this->assertOwner($disk, $scoped);
        // It rewrites title/alt/caption/tags — the same metadata edit the
        // metadata endpoints refuse on a held file.
        $this->assertNoActiveHold($disk, $scoped);
        $fs = $this->disks->disk($disk);

        $name = basename($scoped);
        if (!$this->imageOptimizer->isImage($name)) {
            throw new ApiException('AI tagging is only available for images', 400, 'ai_images_only');
        }

        $imageData = $fs->read($scoped);
        $mime = $fs->mimeType($scoped);

        $aiResult = $this->aiTagger->analyze($imageData, $mime);
        $existing = $this->meta->get($disk, $scoped);

        // Merge instead of clobber: a re-tag (or a second AI provider) must not wipe
        // tags the user added by hand. Dedup case-insensitively, keep first-seen casing.
        $mergedTags = array_values(array_filter(array_map('trim', explode(',', (string) ($existing['tags'] ?? '')))));
        $seen = array_map('strtolower', $mergedTags);
        foreach (($aiResult['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || in_array(strtolower($tag), $seen, true)) {
                continue;
            }
            $mergedTags[] = $tag;
            $seen[] = strtolower($tag);
        }

        $data = [
            'title'    => (!empty($existing['title'])) ? $existing['title'] : ($aiResult['title'] ?? null),
            'alt_text' => (!empty($existing['alt_text'])) ? $existing['alt_text'] : ($aiResult['alt_text'] ?? null),
            'caption'  => (!empty($existing['caption'])) ? $existing['caption'] : ($aiResult['caption'] ?? null),
            'tags'     => implode(', ', $mergedTags),
        ];

        $this->meta->save($disk, $scoped, $data);

        return [
            'tags'     => $mergedTags,
            'title'    => $data['title'],
            'alt_text' => $data['alt_text'],
            'caption'  => $data['caption'],
        ];
    }

    private const MAX_PRESIGN_TTL = 86400; // 24 hours

    /** Tighter ceiling for PUT presigns specifically — see presign()'s use of it. */
    private const MAX_PUT_PRESIGN_TTL = 900; // 15 minutes

    /** Max bytes the code editor will read/write (config files are small). */
    private const MAX_EDIT_BYTES = 5 * 1024 * 1024;

    /** Skip on-upload auto-optimize above this size (huge images: too slow inline). */
    private const AUTO_OPTIMIZE_MAX = 40 * 1024 * 1024;

    /** Hard cap on the rename probe so a pathological folder can't loop forever. */
    private const MAX_COLLISION_TRIES = 10000;

    /**
     * Find a non-colliding filename in $dirUser by appending ` (1)`, ` (2)`, … to the
     * base name while preserving the extension (extension immutability), e.g.
     * `photo.jpg` → `photo (1).jpg` (Finder / Google Drive / Windows style).
     * $dirUser/$name are UNSCOPED (user-relative); returns the chosen unscoped
     * filename. Falls back to a short random suffix if the numeric probe is exhausted.
     */
    private function uniqueName($fs, string $dirUser, string $name): string
    {
        $dot  = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $ext  = $dot === false ? '' : substr($name, $dot); // includes the leading dot
        $prefix = $dirUser === '' ? '' : $dirUser . '/';

        for ($i = 1; $i <= self::MAX_COLLISION_TRIES; $i++) {
            $candidate = $base . ' (' . $i . ')' . $ext;
            if (!$fs->fileExists($this->scopedPath($prefix . $candidate))) {
                return $candidate;
            }
        }
        // Exhausted (extremely unlikely) — random suffix is effectively collision-free.
        return $base . ' (' . bin2hex(random_bytes(4)) . ')' . $ext;
    }

    /**
     * Read a file's text content for the in-browser editor. Read perm; size-capped
     * (MAX_EDIT_BYTES → 413) and binary-guarded (a NUL byte → 415). Works on any
     * disk via Flysystem.
     *
     * @return array{path:string,content:string,size:int}
     */
    public function getContent(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        if (!$this->claims->allowDownload) {
            throw new ApiException('Downloading the original is not allowed', 403, 'download_forbidden');
        }
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);

        $fs = $this->disks->disk($disk);
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        $size = (int) ($fs->fileSize($scoped) ?? 0);
        if ($size > self::MAX_EDIT_BYTES) {
            throw new ApiException('File is too large to edit', 413, 'edit_too_large',
                ['max_mb' => round(self::MAX_EDIT_BYTES / 1048576)]);
        }
        $content = (string) $fs->read($scoped);
        if (strpos($content, "\0") !== false) {
            throw new ApiException('This is not a text file', 415, 'not_text');
        }
        return ['path' => $path, 'content' => $content, 'size' => strlen($content)];
    }

    /**
     * Overwrite a file's text content from the editor. Gated by the allow_code_edit
     * claim (off by default — editing config/executable files is powerful) + write
     * perm. The file must already EXIST (the editor never creates a new executable),
     * the extension policy (allowed_ext) still applies, and content is size-capped.
     * Unlike upload, the always-dangerous-extension block is NOT applied — editing
     * an existing .php/.sh/.env on a server you manage is the whole point.
     *
     * @return array{path:string,size:int}
     */
    public function putContent(string $disk, string $path, string $content): array
    {
        $this->assertDisk($disk);
        if (!$this->claims->allowCodeEdit) {
            throw new ApiException('Editing file content is not allowed', 403, 'edit_forbidden');
        }
        $this->assertPerm('write');
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped, $disk);
        $this->assertNotHidden($scoped);
        // Overwriting a file's content is a destructive modify — honour owner_only,
        // like delete/rename/move/crop/watermark do (it was missing here).
        $this->assertOwner($disk, $scoped);
        $this->assertNoActiveHold($disk, $scoped);
        $this->assertExt(strtolower(pathinfo($scoped, PATHINFO_EXTENSION)));

        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new ApiException('Content is too large', 413, 'edit_too_large',
                ['max_mb' => round(self::MAX_EDIT_BYTES / 1048576)]);
        }
        $fs = $this->disks->disk($disk);
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('File not found (the editor edits existing files only)', 404, 'not_found');
        }
        if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0) {
            $this->quotaManager->assertQuota(
                $disk,
                $this->claims->pathPrefix,
                strlen($content) - (int) $fs->fileSize($scoped),
                $this->claims->maxStorageMb
            );
        }
        // Virus scan (paid Virus module). The editor writes attacker-influenced bytes
        // to an existing path — a webshell pasted into a .php is exactly what ClamAV
        // catches — so this path is scanned like an upload. The content is already
        // capped at MAX_EDIT_BYTES, so staging it to a temp is bounded.
        if ($this->virusScanner !== null || $this->dlpScanner !== null) {
            $editTmp = tempnam(sys_get_temp_dir(), 'ffedit');
            if ($editTmp === false || @file_put_contents($editTmp, $content) === false) {
                if (is_string($editTmp)) { @unlink($editTmp); }
                // Same staging step feeds both scanners — attribute the failure code to
                // whichever is actually active (virus takes precedence when both are on,
                // matching its historical error code at this call site).
                $code = $this->virusScanner !== null ? 'virus_failed' : 'dlp_failed';
                throw new ApiException('Could not stage content for scanning', 500, $code);
            }
            try {
                $this->assertNoVirus($editTmp, basename($scoped));
                // PII scan (paid DLP module) — the editor writes attacker-influenced
                // text content, exactly the shape DLP is built to catch (e.g. pasting
                // a customer export into a .csv/.txt config file).
                $this->assertNoPii($editTmp, basename($scoped));
            } finally {
                @unlink($editTmp);
            }
        }

        // Snapshot the current content before overwriting it (Versioning module) —
        // the editor's repeated saves are the prime "give me the old one back" case.
        $this->keepVersion($disk, $scoped, $fs);
        $fs->write($scoped, $content);
        $this->meta->save($disk, $scoped, ['size' => strlen($content), 'modified' => time()]);
        $this->meta->saveHash($disk, $scoped, hash('sha256', $content));
        return ['path' => $path, 'size' => strlen($content)];
    }

    /**
     * Read the Unix permission mode of a file/dir on an SFTP disk (cPanel-style
     * file permissions). Returns the 3-digit octal string (e.g. "644"). SFTP only
     * — S3 has no Unix modes, local serves web uploads.
     *
     * @return array{path:string,mode:string}
     */
    public function getMode(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);

        [$conn, $root] = $this->disks->sftpConnection($disk);
        $location = ($root !== '' ? $root . '/' : '') . $scoped;
        $stat = $conn->stat($location);
        if (!is_array($stat) || !isset($stat['mode'])) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        return ['path' => $path, 'mode' => sprintf('%03o', $stat['mode'] & 0777)];
    }

    /**
     * Set the Unix permission mode of one file/dir on an SFTP disk. $mode is an
     * octal string ("644", "0755", "700"); only the low 0777 bits are applied.
     * Not recursive (one entry at a time — avoids broad accidents). Write perm
     * required.
     *
     * @return array{path:string,mode:string}
     */
    public function setMode(string $disk, string $path, string $mode): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');
        if (!preg_match('/^0?[0-7]{3}$/', $mode)) {
            throw new ApiException('Invalid mode: use a 3-digit octal like 644 or 755', 422, 'invalid_mode');
        }
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped, $disk);
        $this->assertNotHidden($scoped);
        $this->assertOwner($disk, $scoped);
        $this->assertNoActiveHold($disk, $scoped);

        [$conn, $root] = $this->disks->sftpConnection($disk);
        $location = ($root !== '' ? $root . '/' : '') . $scoped;
        if ($conn->stat($location) === false) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        if (!$conn->chmod(octdec($mode), $location, false)) {
            throw new ApiException('Failed to set permissions', 500, 'chmod_failed');
        }
        return ['path' => $path, 'mode' => sprintf('%03o', octdec($mode) & 0777)];
    }

    public function presign(string $disk, string $path, string $method, int $ttl, int $sizeBytes = 0): array
    {
        $this->assertDisk($disk);

        if (!in_array($method, ['GET', 'PUT', 'POST'], true)) {
            throw new ApiException('Invalid presign method: must be GET, PUT or POST', 400);
        }
        // An operator who wants upload size enforced by the bucket itself turns the
        // advisory-only PUT off and leaves clients the POST policy below.
        if ($method === 'PUT' && !$this->claims->allowPresignPut) {
            throw new ApiException('Presigned PUT is disabled for this token — use method POST', 403, 'presign_put_disabled');
        }

        // A preview-only token must not be able to mint a clean download URL.
        if ($method === 'GET' && !$this->claims->allowDownload) {
            throw new ApiException('Downloading the original is not allowed', 403, 'download_forbidden');
        }

        $this->assertPerm($method === 'GET' ? 'read' : 'write');

        if ($ttl < 1) {
            $ttl = 3600;
        }
        if ($ttl > self::MAX_PRESIGN_TTL) {
            $ttl = self::MAX_PRESIGN_TTL;
        }
        // PUT presigns get a much shorter ceiling than GET: `size` is only
        // checked HERE, at mint time (see the ContentLength note below for why
        // it can't be signed into the URL itself), so the window during which a
        // client could reuse the URL with a larger-than-declared body is exactly
        // this TTL. Capping it tightly bounds that advisory check's blast radius
        // without needing a different upload mechanism.
        if ($method !== 'GET' && $ttl > self::MAX_PUT_PRESIGN_TTL) {
            $ttl = self::MAX_PUT_PRESIGN_TTL;
        }

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') !== 's3') {
            // Gated local / SFTP media has no S3-style presigned URL — mint a fresh
            // tokened /api/fm/stream URL instead so a <video>/<audio> element on
            // these disks can also recover from an expired stream token (the same
            // /api/fm/presign call the S3 branch below serves).
            if ($method === 'GET' && ($this->isGatedLocal($config) || (($config['driver'] ?? '') === 'sftp' && $this->streamSecret !== ''))) {
                $streamTtl = $this->claims->streamTokenTtl > 0 ? $this->claims->streamTokenTtl : 3600;
                return [
                    'url'        => $this->gatedLocalUrl($disk, $scoped),
                    'expires_at' => time() + $streamTtl,
                ];
            }
            throw new ApiException('Presigned URLs are only available for S3/R2 disks', 400);
        }

        if ($method === 'PUT' || $method === 'POST') {
            if ($sizeBytes <= 0) {
                throw new ApiException('Missing required field: size', 400, 'missing_param');
            }
            $this->validateUploadName(basename($scoped), $sizeBytes);
            if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0 && $sizeBytes > 0) {
                $this->quotaManager->assertQuota(
                    $disk,
                    $this->claims->pathPrefix,
                    $sizeBytes,
                    $this->claims->maxStorageMb
                );
            }

            $fs = $this->disks->disk($disk);
            try {
                if ($fs->fileExists($scoped)) {
                    $this->assertOwner($disk, $scoped);
                    $this->assertNoActiveHold($disk, $scoped);
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Some adapters can throw on existence checks; presign should still work for new objects.
            }
        }

        $client = $this->disks->s3Client($disk);
        $bucket = $config['bucket'] ?? '';

        if ($method === 'POST') {
            return $this->presignPost($client, $config, $bucket, $scoped, $ttl, $sizeBytes);
        }

        $params = [
            'Bucket' => $bucket,
            'Key'    => $scoped,
        ];
        // Same reasoning as fileUrl()'s presign path: an inline .svg/.html executes in
        // the bucket's origin, so sign the disposition in rather than letting R2/S3
        // serve it with whatever Content-Type it stored.
        if ($method === 'GET') {
            $params['ResponseContentDisposition'] = $this->presignDisposition($scoped);
            $params['ResponseContentType'] = self::presignContentType($scoped);
        }
        // NOT signing the declared size into the URL — `ContentLength` cannot be:
        // the AWS SDK's SignatureV4::getHeaderBlacklist() drops `content-length`
        // from the signature unconditionally (confirmed empirically: a presigned
        // PutObject URL generated with and without a ContentLength param is
        // byte-identical, `X-Amz-SignedHeaders=host`), and moveHeadersToQuery()
        // only promotes `x-amz-*` headers to the query string, so there is no
        // way to get the declared size into anything S3/R2 actually checks.
        // So the max_upload_mb / max_storage_mb / max_files checks above are
        // ADVISORY ONLY for a PUT presign: they run against the client-declared
        // `size`, while the signed URL constrains only Bucket+Key — `size: 1`
        // plus a 10 GB PUT body sails past every limit at once, and the quota
        // accounting above will be wrong for that upload until a reconciling
        // pass (e.g. a later `list()`/usage refresh that re-stats the object)
        // corrects it. The blast radius is bounded by MAX_PUT_PRESIGN_TTL
        // (15 min) above, not by anything signed here. A hard server-side cap
        // needs createPresignedPost() + a `content-length-range` policy
        // condition instead — a different client contract (POST form upload,
        // not PUT) that would also have to change the UI, both proxy adapters,
        // and the docs consistently, so it is a separate, larger change.

        $cmd = $client->getCommand($method === 'GET' ? 'GetObject' : 'PutObject', $params);

        $request = $client->createPresignedRequest($cmd, "+{$ttl} seconds");
        $url = (string) $request->getUri();

        return [
            'url'        => $url,
            'expires_at' => time() + $ttl,
        ];
    }

    /**
     * Browser-form upload (S3 POST Object, SigV4 policy) — the size-ENFORCING
     * counterpart of a presigned PUT. A PUT URL can't carry its size (S3 drops
     * Content-Length from the signature), so `size` there is advisory. The POST
     * policy is checked by the BUCKET: `content-length-range` caps the body at the
     * size declared (and quota-checked) here, the key is pinned exactly, and the
     * Content-Type is pinned to the extension's type — so neither an oversized body
     * nor a spoofed `text/html` type can get past it.
     *
     * The client submits `multipart/form-data` to `url` with every entry of
     * `fields` first and the file LAST (field name `file`).
     *
     * Cloudflare R2 does not implement POST Object → 400 presign_post_unsupported.
     *
     * @return array{url:string,fields:array<string,string>,expires_at:int,max_bytes:int}
     */
    private function presignPost($client, array $config, string $bucket, string $scoped, int $ttl, int $sizeBytes): array
    {
        $endpointHost = strtolower((string) (parse_url((string) ($config['endpoint'] ?? ''), PHP_URL_HOST) ?? ''));
        if ($endpointHost !== '' && str_ends_with($endpointHost, '.r2.cloudflarestorage.com')) {
            throw new ApiException(
                'This storage (Cloudflare R2) does not support form (POST) uploads — use chunked upload',
                400,
                'presign_post_unsupported'
            );
        }
        $contentType = self::presignContentType($scoped);
        $post = new \Aws\S3\PostObjectV4(
            $client,
            $bucket,
            ['key' => $scoped, 'Content-Type' => $contentType],
            [
                ['bucket' => $bucket],
                ['eq', '$key', $scoped],
                ['eq', '$Content-Type', $contentType],
                ['content-length-range', 0, $sizeBytes],
            ],
            "+{$ttl} seconds"
        );
        return [
            'url'        => (string) ($post->getFormAttributes()['action'] ?? ''),
            'fields'     => array_map('strval', $post->getFormInputs()),
            'expires_at' => time() + $ttl,
            'max_bytes'  => $sizeBytes,
        ];
    }

    /**
     * Duplicate finder for the usage dashboard: groups byte-identical files under
     * the tenant's prefix and reports how much space the extra copies waste.
     *
     * Backend-agnostic and cheap by construction: one recursive listing, a size
     * pre-filter (files of a unique size can't have a twin), then a streamed
     * SHA-256 of only the size-colliding candidates, capped at $hashBudgetBytes
     * read per call — over budget it returns what it has with `truncated: true`.
     * Hidden/reserved paths follow the same rules as list().
     *
     * @return array{groups:array<int,array{hash:string,size:int,count:int,wasted:int,files:array<int,array{key:string,modified:?int}>}>,total_wasted:int,scanned_files:int,truncated:bool}
     */
    public function findDuplicates(string $disk, int $maxGroups = 50, int $hashBudgetBytes = 512 * 1024 * 1024): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $root = $this->scopedPath('');
        $fs = $this->disks->disk($disk);

        $bySize = [];
        $scanned = 0;
        foreach ($fs->listContents($root, true) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $key = $item->path();
            if ($this->isReservedSystemPath($key) || $this->isHiddenForToken($key)) {
                continue;
            }
            $size = (int) ($item->fileSize() ?? 0);
            if ($size <= 0) {
                continue;
            }
            $scanned++;
            $bySize[$size][] = ['key' => $key, 'modified' => $item->lastModified()];
        }

        $byHash = [];
        $budget = $hashBudgetBytes;
        $truncated = false;
        krsort($bySize); // biggest first: those waste the most, so they get the budget
        foreach ($bySize as $size => $files) {
            if (count($files) < 2) {
                continue;
            }
            foreach ($files as $f) {
                if ($budget < $size) {
                    $truncated = true;
                    break 2;
                }
                $stream = $fs->readStream($f['key']);
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $budget -= $size;
                $byHash[$size . ':' . hash_final($ctx)][] = $f;
            }
        }

        $groups = [];
        $totalWasted = 0;
        foreach ($byHash as $id => $files) {
            if (count($files) < 2) {
                continue;
            }
            [$size, $hash] = explode(':', $id, 2);
            $wasted = (int) $size * (count($files) - 1);
            $totalWasted += $wasted;
            usort($files, static fn ($a, $b) => ($a['modified'] ?? 0) <=> ($b['modified'] ?? 0)); // oldest = the "original"
            $groups[] = [
                'hash'   => $hash,
                'size'   => (int) $size,
                'count'  => count($files),
                'wasted' => $wasted,
                'files'  => array_map(fn ($f) => ['key' => $this->outKey($f['key']), 'modified' => $f['modified']], $files),
            ];
        }
        usort($groups, static fn ($a, $b) => $b['wasted'] <=> $a['wasted']);

        return [
            'groups'        => array_slice($groups, 0, max(1, $maxGroups)),
            'total_wasted'  => $totalWasted,
            'scanned_files' => $scanned,
            'truncated'     => $truncated,
        ];
    }

    /**
     * Apply the token's `retention_rules`: files under each rule's path not
     * modified for `days` days go to trash (or are deleted). Dry-run lists what
     * would happen and changes nothing. Every action goes through trash()/delete()'s
     * scoped cores (trashScoped()/deleteScoped() — same checks, real key),
     * so owner_only, the `delete` perm and LEGAL HOLDS (always win) still apply —
     * a held file is reported as `held`, never touched. At most $maxActions files
     * per call (`truncated: true` → run again).
     *
     * @return array{dry_run:bool,rules:int,matched:int,acted:int,held:int,errors:int,truncated:bool,items:array<int,array{path:string,action:string,age_days:int,result:string}>}
     */
    public function runRetention(string $disk, bool $dryRun = true, int $maxActions = 500): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $fs = $this->disks->disk($disk);
        $now = time();
        $report = ['dry_run' => $dryRun, 'rules' => count($this->claims->retentionRules), 'matched' => 0,
            'acted' => 0, 'held' => 0, 'errors' => 0, 'truncated' => false, 'items' => []];
        // Collect first, act after: trashing moves files while a lazy listing of the
        // same tree is still being iterated otherwise.
        $candidates = [];
        foreach ($this->claims->retentionRules as $rule) {
            $root = $this->scopedPath($rule['path']);
            if ($this->isReservedSystemPath($root)) {
                continue;
            }
            $cutoff = $now - $rule['days'] * 86400;
            try {
                foreach ($fs->listContents($root, true) as $item) {
                    if (!$item->isFile()) {
                        continue;
                    }
                    $key = $item->path();
                    if (isset($candidates[$key]) || $this->isReservedSystemPath($key) || $this->isHiddenForToken($key)) {
                        continue;
                    }
                    $mtime = (int) ($item->lastModified() ?? $now);
                    if ($mtime <= $cutoff) {
                        $candidates[$key] = ['action' => $rule['action'], 'age_days' => intdiv($now - $mtime, 86400)];
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        ksort($candidates, SORT_STRING);
        $report['matched'] = count($candidates);

        foreach ($candidates as $key => $c) {
            if (count($report['items']) >= $maxActions) {
                $report['truncated'] = true;
                break;
            }
            $rel = $this->outKey((string) $key);
            $entry = ['path' => $rel, 'action' => $c['action'], 'age_days' => $c['age_days'], 'result' => 'pending'];
            if ($this->meta->holdBlocking($disk, (string) $key) !== null) {
                $entry['result'] = 'held';
                $report['held']++;
            } elseif ($dryRun) {
                $entry['result'] = 'would_' . $c['action'];
            } else {
                try {
                    // Act on the listed storage key itself, not $rel: outKey() →
                    // scopedPath() is not an identity when a subfolder repeats the
                    // prefix's last segment (prefix `t` + key `t/t/a` → rel `t/a`
                    // → scoped `t/a`, a different file).
                    $c['action'] === 'delete' ? $this->deleteScoped($disk, (string) $key) : $this->trashScoped($disk, (string) $key);
                    $entry['result'] = $c['action'] === 'delete' ? 'deleted' : 'trashed';
                    $report['acted']++;
                } catch (ApiException $e) {
                    $entry['result'] = (string) ($e->getErrorCode() ?? 'error');
                    $e->getErrorCode() === 'legal_hold_active' ? $report['held']++ : $report['errors']++;
                }
            }
            $report['items'][] = $entry;
        }
        return $report;
    }

    /**
     * Run (or resume) a fixity pass over the token's root — see FixityChecker.
     * $auditEntries are the disk's audit rows, used to tell a change made through
     * FluxFiles (explained) from one made behind its back (unexplained).
     *
     * @param array<int,array<string,mixed>> $auditEntries
     * @return array<string,mixed>
     */
    /** @param array<int,array<string,mixed>>|callable(?int):array<int,array<string,mixed>> $auditEntries see FixityChecker::run() */
    public function runFixityCheck(string $disk, $auditEntries, int $budgetBytes = 1024 * 1024 * 1024): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $report = FixityChecker::run(
            $this->disks->disk($disk),
            $this->scopedPath(''),
            fn (string $k): bool => $this->isReservedSystemPath($k) || $this->isHiddenForToken($k),
            $auditEntries,
            $budgetBytes
        );
        foreach (['changed_explained', 'changed_unexplained', 'missing_explained', 'missing_unexplained'] as $list) {
            $report[$list] = array_map(fn (string $k) => $this->outKey($k), $report[$list]);
        }
        return $report;
    }

    /**
     * On-demand virus scan of a file that is ALREADY stored — e.g. one uploaded
     * before scanning was switched on, or arriving by a path no scan covers
     * (chunked/presigned direct-to-bucket uploads). Read-only: reports, never
     * deletes. $scanner is the Virus module's scanPath() (gated by the caller).
     *
     * @param callable(string):array $scanner
     * @return array{path:string,clean:bool,threat:?string,scanned_at:int}
     */
    public function scanForViruses(string $disk, string $path, callable $scanner): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);
        $fs = $this->disks->disk($disk);
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('File not found', 404, 'not_found');
        }
        if ((int) ($fs->fileSize($scoped) ?? 0) > self::SCAN_MAX_BYTES) {
            throw new ApiException('File is too large to scan', 413, 'upload_too_large',
                ['max' => (self::SCAN_MAX_BYTES / 1048576) . 'MB']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ffscan');
        if ($tmp === false) {
            throw new ApiException('Could not stage file for scanning', 500, 'virus_failed');
        }
        try {
            $in = $fs->readStream($scoped);
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }
            $verdict = $scanner($tmp);
        } finally {
            @unlink($tmp);
        }
        $clean = is_array($verdict) && ($verdict['clean'] ?? false) === true;
        $threat = is_array($verdict) && isset($verdict['threat']) && $verdict['threat'] !== '' ? (string) $verdict['threat'] : null;
        return ['path' => $path, 'clean' => $clean, 'threat' => $clean ? null : ($threat ?? 'unknown'), 'scanned_at' => time()];
    }

    public function fileMeta(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);
        $fs = $this->disks->disk($disk);

        // Each lookup is best-effort: some S3-compatible backends (notably Cloudflare R2)
        // don't return a ContentType on HeadObject, which makes Flysystem's mimeType()
        // throw. Fall back to an extension-based guess instead of failing the whole call.
        $size = null;
        try { $size = $fs->fileSize($scoped); } catch (\Throwable $e) {}

        $mime = null;
        try { $mime = $fs->mimeType($scoped); } catch (\Throwable $e) {}
        if ($mime === null || $mime === '') {
            $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
                ->detectMimeTypeFromPath($scoped) ?? 'application/octet-stream';
        }

        $modified = null;
        try { $modified = $fs->lastModified($scoped); } catch (\Throwable $e) {}

        return [
            'size'     => $size,
            'mime'     => $mime,
            'modified' => $modified,
        ];
    }

    private function scopedPath(string $path): string
    {
        $path = str_replace(["\0", "\x00"], '', $path);
        // Split on "\" as well as "/" — Flysystem's WhitespacePathNormalizer does
        // str_replace('\\', '/') BEFORE popping ".." segments, so anything that
        // only explodes on "/" sees "a\..\..\b" as one opaque segment, passes it
        // through, and lets Flysystem resolve it back out of the tenant prefix
        // (and past isReservedSystemPath(), which matches a literal "_fluxfiles/").
        // Keep this in sync with Claims::stripDotSegments().
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                continue;
            }
            if ($part !== '') {
                $safe[] = $part;
            }
        }

        $relative = implode('/', $safe);
        $prefix = trim($this->claims->pathPrefix, '/');

        if ($prefix !== '') {
            // Idempotent prefixing. list() returns full prefixed keys (e.g.
            // "user_1/posts" internally) and accepts relative paths from the UI,
            // so a path already inside the prefix must NOT be prefixed again (which
            // produced "user_1/user_1/posts" → empty folder). `..`/`.` were stripped above
            // and the trailing-"/" boundary blocks prefix confusion (user_1 vs
            // user_10), so anything not under the prefix is still sandboxed into it.
            if ($relative === $prefix || strpos($relative, $prefix . '/') === 0) {
                return $relative;
            }

            // Fail closed on a cross-tenant path. When the prefix has a parent
            // (e.g. "users/42" → parent "users"), a path that targets a sibling
            // under that same parent ("users/99/…") is unambiguously another
            // tenant's tree — reject it instead of silently sandboxing it into an
            // empty phantom folder. A flat single-segment prefix ("user_1") has no
            // parent, so "user_2/…" is indistinguishable from a real subfolder and
            // stays sandboxed (still safe — it can never reach user_2's files).
            $parent = (strpos($prefix, '/') !== false) ? substr($prefix, 0, strrpos($prefix, '/')) : '';
            if ($parent !== '' && strpos($relative, $parent . '/') === 0) {
                throw new ApiException('Access denied to path', 403, 'path_denied');
            }

            return $relative === '' ? $prefix : $prefix . '/' . $relative;
        }

        return $relative;
    }

    /**
     * Strip the tenant prefix from an outbound navigation key so the client sees
     * paths relative to ITS root (the prefix is an internal sandbox detail). The
     * inverse of scopedPath(); idempotent. See Claims::unscopePath().
     */
    private function outKey(string $key): string
    {
        return $this->claims->unscopePath($key);
    }

    /**
     * Apply outKey() to the navigation keys in a result set (item `key` and each
     * variant `key`). `url`/`permanent_url` are deliberately left untouched —
     * those are real storage URLs and must keep the full path.
     */
    private function unscopeItems(array $items): array
    {
        foreach ($items as &$it) {
            if (isset($it['key']) && is_string($it['key'])) {
                $it['key'] = $this->outKey($it['key']);
            }
            if (!empty($it['variants']) && is_array($it['variants'])) {
                foreach ($it['variants'] as &$v) {
                    if (isset($v['key']) && is_string($v['key'])) {
                        $v['key'] = $this->outKey($v['key']);
                    }
                }
                unset($v);
            }
        }
        unset($it);
        return $items;
    }

    /**
     * Look up existing variant files (thumb/medium/large WebP) for an image.
     */
    /**
     * @param array<string,array<string,true>> $known existingVariants() result; a
     *        folder missing from it falls back to a per-file fileExists()
     */
    private function getFileVariants(string $disk, string $key, array $known = []): ?array
    {
        if (!$this->imageOptimizer->isImage($key)) {
            return null;
        }

        $variants = [];

        foreach (['thumb', 'medium', 'large'] as $size) {
            $vk = $this->variantKey($key, $size);
            if ($this->variantExists($disk, $vk, $known)) {
                $variants[$size] = [
                    'url' => $this->fileUrl($disk, $vk),
                    'key' => $vk,
                ];
            }
        }

        return !empty($variants) ? $variants : null;
    }

    /**
     * The clean-original URL a response may carry, or null for a preview-only
     * token (allow_download=false). THE single gate shared by list() and every
     * operation that echoes a URL back (upload, rename, cross-copy/move, crop,
     * watermark): a stream token / presigned GET of the un-watermarked file is
     * exactly what presign/getContent/zip refuse such a token.
     */
    private function downloadUrl(string $disk, string $key): ?string
    {
        return $this->claims->allowDownload ? $this->fileUrl($disk, $key) : null;
    }

    /**
     * getFileVariants() behind the same allow_download gate as downloadUrl().
     *
     * @param array<string,array<string,true>> $known see getFileVariants()
     */
    private function downloadVariants(string $disk, string $key, array $known = []): ?array
    {
        return $this->claims->allowDownload ? $this->getFileVariants($disk, $key, $known) : null;
    }

    /** Below this many candidates in a folder, per-file checks are cheaper than a listing. */
    private const VARIANT_LISTING_MIN = 8;

    /**
     * The variant files that exist, per `_variants/` folder, for the images and
     * PDFs/videos among $items — one non-recursive listing per folder. Folders with
     * only a few candidates are left out (callers fall back to fileExists()), as is
     * any folder whose listing fails.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<string,true>> variants dir => [variant key => true]
     */
    private function existingVariants(string $disk, array $items): array
    {
        $count = [];
        foreach ($items as $it) {
            if (($it['type'] ?? '') !== 'file') {
                continue;
            }
            $key = (string) $it['key'];
            if ($this->imageOptimizer->isImage($key) || MediaPoster::kindFor($key) !== null) {
                $dir = dirname($this->variantKey($key, 'thumb'));
                $count[$dir] = ($count[$dir] ?? 0) + 1;
            }
        }
        $known = [];
        $fs = $this->disks->disk($disk);
        foreach ($count as $dir => $n) {
            if ($n < self::VARIANT_LISTING_MIN) {
                continue;
            }
            try {
                $set = [];
                foreach ($fs->listContents((string) $dir, false) as $v) {
                    if ($v->isFile()) {
                        $set[$v->path()] = true;
                    }
                }
                $known[(string) $dir] = $set;
            } catch (\Throwable $e) {
                // fall back to per-file checks for this folder
            }
        }
        return $known;
    }

    /** @param array<string,array<string,true>> $known */
    private function variantExists(string $disk, string $variantKey, array $known): bool
    {
        $dir = dirname($variantKey);
        if (isset($known[$dir])) {
            return isset($known[$dir][$variantKey]);
        }
        return $this->disks->disk($disk)->fileExists($variantKey);
    }

    /**
     * Shape ImageOptimizer::process() output for a response, behind the same
     * allow_download gate as downloadUrl() — clean WebP variants are as much a
     * copy of the original as its URL.
     *
     * @param array<string,array{key:string,width:int,height:int}> $processed
     */
    private function variantUrls(string $disk, array $processed): ?array
    {
        if (!$this->claims->allowDownload) {
            return null;
        }
        $out = [];
        foreach ($processed as $sizeName => $info) {
            $out[$sizeName] = [
                'url'    => $this->fileUrl($disk, $info['key']),
                'key'    => $info['key'],
                'width'  => $info['width'],
                'height' => $info['height'],
            ];
        }
        return $out;
    }

    /**
     * A stable, non-expiring URL for embedding (editors, saved content), or null
     * when none exists. Local disks and public disks / disks with a `public_url`
     * (CDN / custom domain) have one; a private bucket without a public domain
     * does not (its only URL is a short-lived presigned one).
     */
    /**
     * Is this a local disk served through gated per-file stream tokens? True only
     * when the disk is local, marked `'private' => true`, and a stream secret is
     * configured (setStreamSecret). Such a disk has no static/permanent URL.
     */
    private function isGatedLocal(array $config): bool
    {
        return ($config['driver'] ?? '') === 'local'
            && !empty($config['private'])
            && $this->streamSecret !== '';
    }

    /** Mint the /api/fm/stream?token=… URL for one file on a gated local disk. */
    private function gatedLocalUrl(string $disk, string $path): string
    {
        $ttl = $this->claims->streamTokenTtl > 0 ? $this->claims->streamTokenTtl : 3600;
        $token = StreamToken::mint($disk, $path, $this->claims->userId, $ttl, $this->streamSecret, $this->claims->auditReads);
        return $this->apiBasePath . '/stream?token=' . rawurlencode($token);
    }

    /**
     * Base URL for the on-demand WebP endpoint of one image file, or null when
     * the feature is off (claim disabled, no stream secret, or not an image). The
     * host appends `&width=…&quality=…`. Reuses the stream secret (= FLUXFILES_SECRET).
     */
    private function imgBaseUrl(string $disk, string $path): ?string
    {
        if (!$this->claims->webpEnabled || $this->streamSecret === ''
            || !$this->imageOptimizer->isImage($path)) {
            return null;
        }
        $ttl = $this->claims->streamTokenTtl > 0 ? $this->claims->streamTokenTtl : 3600;
        $maxWidth = $this->claims->webpMaxWidth > 0 ? $this->claims->webpMaxWidth : 2000;
        $token = ImageToken::mint(
            $disk,
            $path,
            $this->claims->userId,
            $ttl,
            $this->streamSecret,
            $maxWidth,
            $this->claims->webpDefaultQuality,
            $this->claims->watermark,
            // A preview-only token must not get the clean original out of /img's
            // fall-through paths either — presign/getContent/zip all refuse it.
            $this->claims->allowDownload
        );
        return $this->apiBasePath . '/img?token=' . rawurlencode($token);
    }

    /**
     * Build a responsive `srcset` string from an image's on-demand endpoint base
     * and the tenant's width ladder. Pure metadata — no image is read. Widths are
     * capped at the source width (the transform never upsizes) and webp_max_width;
     * the largest serveable 100px-multiple of the source is always offered as the
     * full-resolution candidate. Returns '' when there's nothing useful to emit.
     */
    private function buildSrcset(string $imgBase, int $naturalWidth): string
    {
        // Too small to bother with responsive variants.
        if ($naturalWidth > 0 && $naturalWidth < 100) {
            return '';
        }
        $maxWidth = $this->claims->webpMaxWidth > 0 ? $this->claims->webpMaxWidth : 2000;
        // Largest 100px-multiple we can honestly serve (floor → never claim upscale).
        $ceiling = $naturalWidth > 0
            ? min($maxWidth, max(100, (int) floor($naturalWidth / 100) * 100))
            : $maxWidth;

        $widths = [];
        foreach ($this->claims->srcsetWidths as $w) {
            if ($w >= 100 && $w <= $ceiling) {
                $widths[$w] = true;
            }
        }
        $widths[$ceiling] = true;   // always offer the full-resolution candidate
        $widths = array_keys($widths);
        sort($widths);

        $parts = [];
        foreach ($widths as $w) {
            $parts[] = $imgBase . '&width=' . $w . ' ' . $w . 'w';
        }
        return implode(', ', $parts);
    }

    /** Default zip/extract limits (overridable per token via zip_max_mb / zip_max_files). */
    private const ZIP_DEFAULT_MAX_MB = 1024;
    private const ZIP_DEFAULT_MAX_FILES = 10000;

    /** True for FluxFiles-internal keys that must never be zipped/exposed. */
    private function isSystemKey(string $key): bool
    {
        return self::isReservedKey($key)
            || strcasecmp(substr($key, -10), '.meta.json') === 0;
    }

    /**
     * Copy $in → $out, refusing to write more than $limit bytes. Returns the byte
     * count, or null when the source held more than $limit (output then partial).
     *
     * @param resource $in
     * @param resource $out
     */
    private static function copyCapped($in, $out, int $limit): ?int
    {
        $n = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $n += strlen($chunk);
            if ($n > $limit) {
                return null;
            }
            fwrite($out, $chunk);
        }
        return $n;
    }

    /**
     * Expand a selection of files/folders into a flat zip manifest, applying every
     * guard (disk/read-perm/allow_zip/allow_download, path scope, owner_only, the
     * system-path skip) plus a pre-flight size/count check — so an oversized
     * selection is rejected with 413 before a single byte is streamed.
     *
     * @param  string[] $paths
     * @return array{entries: list<array{key:string,name:string,size:int}>, total:int, count:int}
     */
    public function zipManifest(string $disk, array $paths): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        if (!$this->claims->allowZip) {
            throw new ApiException('Zip download is not allowed', 403, 'zip_forbidden');
        }
        if (!$this->claims->allowDownload) {
            throw new ApiException('Download is not allowed for this token', 403, 'download_forbidden');
        }
        $paths = array_values(array_filter(array_map('strval', $paths), static fn ($p) => $p !== ''));
        if ($paths === []) {
            throw new ApiException('No paths to zip', 400, 'zip_empty');
        }

        $maxFiles = $this->claims->zipMaxFiles > 0 ? $this->claims->zipMaxFiles : self::ZIP_DEFAULT_MAX_FILES;
        $maxBytes = ($this->claims->zipMaxMb > 0 ? $this->claims->zipMaxMb : self::ZIP_DEFAULT_MAX_MB) * 1024 * 1024;

        $fs = $this->disks->disk($disk);
        $entries = [];
        $seen = [];
        $total = 0;

        $add = function (string $key, string $name, int $size) use (&$entries, &$seen, &$total, $maxFiles, $maxBytes): void {
            // De-dupe archive names so two same-named picks don't collide.
            $candidate = $name;
            $i = 1;
            while (isset($seen[$candidate])) {
                $dot = strrpos($name, '.');
                $candidate = ($dot !== false && $dot > 0)
                    ? substr($name, 0, $dot) . " ({$i})" . substr($name, $dot)
                    : $name . " ({$i})";
                $i++;
            }
            $seen[$candidate] = true;
            $entries[] = ['key' => $key, 'name' => $candidate, 'size' => $size];
            $total += $size;
            if (count($entries) > $maxFiles) {
                throw new ApiException('Too many files to zip', 413, 'zip_too_many', ['max' => $maxFiles]);
            }
            if ($total > $maxBytes) {
                throw new ApiException('Selection is too large to zip', 413, 'zip_too_large', ['max_mb' => (int) ($maxBytes / 1048576)]);
            }
        };

        foreach ($paths as $p) {
            $scoped = $this->scopedPath($p);
            $this->assertNotSystem($scoped);
            $this->assertNotHidden($scoped);

            if ($fs->directoryExists($scoped)) {
                $this->assertOwnsTree($disk, $scoped);
                // Keep the selected folder itself as the top entry in the archive.
                $parent = strpos($scoped, '/') !== false ? substr($scoped, 0, strrpos($scoped, '/') + 1) : '';
                foreach ($fs->listContents($scoped, true) as $item) {
                    if (!$item->isFile()) {
                        continue;
                    }
                    $key = $item->path();
                    if ($this->isSystemKey($key) || $this->isHiddenForToken($key)) {
                        continue;
                    }
                    $rel = ($parent !== '' && str_starts_with($key, $parent)) ? substr($key, strlen($parent)) : $key;
                    $add($key, $rel, (int) ($item->fileSize() ?? 0));
                }
            } elseif ($fs->fileExists($scoped)) {
                if ($this->isSystemKey($scoped)) {
                    continue;
                }
                $this->assertOwner($disk, $scoped);
                $add($scoped, basename($scoped), (int) $fs->fileSize($scoped));
            } else {
                throw new ApiException('Path not found: ' . $p, 404, 'not_found');
            }
        }

        if ($entries === []) {
            throw new ApiException('Nothing to zip', 400, 'zip_empty');
        }
        return ['entries' => $entries, 'total' => $total, 'count' => count($entries)];
    }

    /**
     * Stream the selection as a zip to php://output. Constant-memory — each entry
     * is piped through Flysystem readStream. ZipStream sends the HTTP headers
     * ($sendHeaders=false in tests). zipManifest() runs first, so a guard/size
     * violation throws (→ JSON error) before any byte is written.
     *
     * @param string[] $paths
     */
    public function streamZip(string $disk, array $paths, ?string $name = null, bool $sendHeaders = true, ?callable $beforeStream = null): void
    {
        $manifest = $this->zipManifest($disk, $paths);
        // Every guard has passed and nothing is on the wire yet — the one point a
        // caller can still record the download (audit) before ZipStream takes over
        // the response and the request ends in an exit.
        if ($beforeStream !== null) {
            $beforeStream($manifest);
        }
        $fs = $this->disks->disk($disk);

        $zip = new \ZipStream\ZipStream(
            outputName: $this->zipFilename($name, $paths),
            contentType: 'application/zip',
            sendHttpHeaders: $sendHeaders,
            defaultEnableZeroHeader: true,
        );
        foreach ($manifest['entries'] as $e) {
            $stream = $fs->readStream($e['key']);
            $zip->addFileFromStream($e['name'], $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $zip->finish();
    }

    /**
     * Extract a `.zip` in place into a destination folder (default: a folder named
     * after the archive, beside it). Two passes: pass 1 validates **every** entry
     * (zip-slip, dangerous-ext, allowed_ext, system-path, the bomb caps, quota) so
     * a structural violation aborts before any write. Pass 2 scans and writes each
     * entry; a scan/storage failure leaves earlier successful entries in place.
     *
     * @return array{extracted:int, dest:string, bytes:int}
     */
    public function extractZip(string $disk, string $path, ?string $dest = null): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');
        if (!$this->claims->allowExtract) {
            throw new ApiException('Extracting archives is not allowed', 403, 'extract_forbidden');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new ApiException('Zip extraction is unavailable on this server', 501, 'zip_unavailable');
        }

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertNotHidden($scoped);
        $this->assertPerm('read');
        $this->assertOwner($disk, $scoped);
        $fs = $this->disks->disk($disk);
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('Archive not found', 404, 'not_found');
        }
        if (strtolower(pathinfo($scoped, PATHINFO_EXTENSION)) !== 'zip') {
            throw new ApiException('Not a .zip archive', 400, 'not_a_zip');
        }

        // Destination in user space (so the return value is prefix-relative), then scoped.
        if ($dest === null || trim($dest) === '') {
            $dir = strpos($path, '/') !== false ? substr($path, 0, strrpos($path, '/')) : '';
            $folder = (string) preg_replace('/\.zip$/i', '', basename($path));
            $destUser = ($dir !== '' ? rtrim($dir, '/') . '/' : '') . $folder;
        } else {
            $destUser = trim($dest);
        }
        $destScoped = $this->scopedPath($destUser);
        $this->assertNotSystem($destScoped, $disk);
        $this->assertNotHidden($destScoped);

        $maxFiles = $this->claims->zipMaxFiles > 0 ? $this->claims->zipMaxFiles : self::ZIP_DEFAULT_MAX_FILES;
        $maxBytes = ($this->claims->zipMaxMb > 0 ? $this->claims->zipMaxMb : self::ZIP_DEFAULT_MAX_MB) * 1024 * 1024;

        // ZipArchive needs a local path — copy the archive down (uniform across disks).
        $tmp = tempnam(sys_get_temp_dir(), 'ffzip');
        $src = $fs->readStream($scoped);
        $dstH = fopen($tmp, 'wb');
        stream_copy_to_stream($src, $dstH);
        fclose($dstH);
        if (is_resource($src)) {
            fclose($src);
        }

        try {
            $za = new \ZipArchive();
            if ($za->open($tmp) !== true) {
                throw new ApiException('The archive is corrupt or unreadable', 400, 'bad_zip');
            }

            // ── Pass 1: validate every entry + resolve its final target, write nothing ──
            $prefix = $destScoped !== '' ? rtrim($destScoped, '/') . '/' : '';
            $collision = $this->claims->uploadCollision; // rename (default) | overwrite | reject
            $plan = [];   // list of ['orig' => entryName, 'target' => scopedDstKey]
            $total = 0;
            for ($i = 0; $i < $za->numFiles; $i++) {
                $stat = $za->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = (string) $stat['name'];
                if ($name === '' || substr($name, -1) === '/') {
                    continue; // directory entry — created implicitly on write
                }
                // Zip-slip: reject absolute / drive-letter / any ".." segment.
                $norm = str_replace('\\', '/', $name);
                if ($norm[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $norm) || preg_match('#^[A-Za-z]:#', $norm)) {
                    throw new ApiException('Archive contains an unsafe path', 403, 'zip_slip', ['entry' => $name]);
                }
                $rel = $this->safeRel($norm);
                if ($rel === '') {
                    continue;
                }
                // show_hidden=false: dot-entries (`.ssh/authorized_keys`, macOS
                // `__MACOSX/._x`) are skipped, mirroring zipManifest()'s skip on
                // the way out — a token that can't see dotfiles can't plant them.
                if ($this->isHiddenForToken($prefix . $rel)) {
                    continue;
                }
                if ($this->isSystemKey($rel)) {
                    throw new ApiException('Archive targets a system path', 403, 'system_path', ['entry' => $name]);
                }
                // Same policy as upload: block dangerous extensions + honour allowed_ext.
                $this->assertSafeFilename(basename($rel));
                $this->assertExt(strtolower(pathinfo($rel, PATHINFO_EXTENSION)));

                // Collision with an existing destination file → honour owner_only +
                // the upload_collision policy (extract no longer clobbers silently).
                $target = $prefix . $rel;
                // Per-entry, disk-aware: isSystemKey() above has no `.git` arm, so
                // without this an archive carrying `.git/config` (or a whole planted
                // repo) is the one write path that skips the SFTP git-internal block.
                $this->assertNotSystem($target, $disk);
                $overwrite = false;
                if ($fs->fileExists($target)) {
                    $this->assertOwner($disk, $target); // don't overwrite another user's file
                    if ($collision === 'overwrite') {
                        $this->assertNoActiveHold($disk, $target);
                        $overwrite = true;
                    }
                    if ($collision === 'reject') {
                        throw new ApiException('A file already exists at the destination', 409, 'name_conflict', ['name' => $rel]);
                    }
                    if ($collision !== 'overwrite') { // 'rename' → keep both
                        $dir = strpos($target, '/') !== false ? substr($target, 0, strrpos($target, '/')) : '';
                        $target = ($dir !== '' ? $dir . '/' : '') . $this->uniqueName($fs, $dir, basename($target));
                    }
                }

                $total += (int) $stat['size'];
                $plan[] = ['orig' => $name, 'target' => $target, 'size' => (int) $stat['size'], 'overwrite' => $overwrite];
                if (count($plan) > $maxFiles) {
                    throw new ApiException('Archive has too many files', 413, 'zip_too_many', ['max' => $maxFiles]);
                }
                if ($total > $maxBytes) {
                    throw new ApiException('Archive is too large to extract', 413, 'zip_too_large', ['max_mb' => (int) ($maxBytes / 1048576)]);
                }
            }
            if ($plan === []) {
                throw new ApiException('The archive has no extractable files', 400, 'zip_empty');
            }
            // Quota on the total uncompressed size.
            if ($this->quotaManager !== null && $this->claims->maxStorageMb > 0) {
                $this->quotaManager->assertQuota($disk, $this->claims->pathPrefix, $total, $this->claims->maxStorageMb);
            }
            if ($this->quotaManager !== null && $this->claims->maxFiles > 0) {
                $newTargets = [];
                foreach ($plan as $entry) {
                    if (!$fs->fileExists($entry['target'])) {
                        $newTargets[$entry['target']] = true;
                    }
                }
                $this->quotaManager->assertFileCount($disk, $this->claims->pathPrefix, count($newTargets), $this->claims->maxFiles);
            }

            // ── Pass 2: write each entry + register it in the same pipeline as an
            // upload (metadata + folder index + hash + image variants), so extracted
            // files get thumbnails and show up in search — not just on disk. ──
            $written = 0;
            foreach ($plan as $e) {
                $stream = $za->getStream($e['orig']);
                if (!is_resource($stream)) {
                    continue;
                }
                // Spool to a local temp so we can both write to $fs and feed the
                // metadata/variant pipeline a local path (uniform across disks).
                $entryTmp = tempnam(sys_get_temp_dir(), 'ffex');
                $eh = fopen($entryTmp, 'wb');
                // The bomb caps in pass 1 trust the size the archive DECLARES in its
                // central directory. Enforce it on the bytes actually inflated, so a
                // lying header can't fill the temp dir (or storage) past the cap.
                $copied = self::copyCapped($stream, $eh, $e['size']);
                fclose($eh);
                fclose($stream);
                if ($copied === null) {
                    @unlink($entryTmp);
                    throw new ApiException('Archive is too large to extract', 413, 'zip_too_large',
                        ['max_mb' => (int) ($maxBytes / 1048576), 'entry' => $e['orig'], 'extracted' => $written]);
                }
                try {
                    // Virus scan (paid Virus module) per entry, BEFORE it is written.
                    // A threat aborts the whole extract: entries already written were
                    // each scanned clean, so the guarantee holds — no infected byte is
                    // ever written — while the rest of a malicious archive is refused.
                    // Deliberately no rollback: with collision=overwrite an extracted
                    // entry may have replaced a pre-existing file, and deleting those
                    // would destroy the user's own data over someone else's archive.
                    if ($this->virusScanner !== null) {
                        try {
                            $this->assertNoVirus($entryTmp, basename($e['target']));
                        } catch (ApiException $ex) {
                            throw new ApiException(
                                $ex->getMessage(),
                                $ex->getHttpCode(),
                                $ex->getErrorCode(),
                                $ex->getErrorParams() + ['entry' => $e['orig'], 'extracted' => $written]
                            );
                        }
                    }
                    // PII scan (paid DLP module) per entry, BEFORE it is written — same
                    // per-entry, no-rollback contract as the virus scan above (one
                    // PII-bearing entry aborts the rest of the archive; entries already
                    // written stay written).
                    if ($this->dlpScanner !== null) {
                        try {
                            $this->assertNoPii($entryTmp, basename($e['target']));
                        } catch (ApiException $ex) {
                            throw new ApiException(
                                $ex->getMessage(),
                                $ex->getHttpCode(),
                                $ex->getErrorCode(),
                                $ex->getErrorParams() + ['entry' => $e['orig'], 'extracted' => $written]
                            );
                        }
                    }
                    // Same privacy rule as upload(): an archive of phone photos must not
                    // be a way around `strip_exif`. Magic-byte detection, lossless.
                    if ($this->claims->stripExif && (int) @filesize($entryTmp) <= self::STRIP_EXIF_MAX) {
                        $raw = @file_get_contents($entryTmp);
                        $res = is_string($raw) ? ExifStripper::strip($raw) : null;
                        if ($res !== null && $res['removed']) {
                            @file_put_contents($entryTmp, $res['data']);
                        }
                        unset($raw, $res);
                    }
                    // Overwriting an existing file (collision=overwrite) → snapshot the
                    // current bytes (Versioning module) first, like upload()/putContent()
                    // do — an overwrite by unzip must be as reversible as any other.
                    if ($e['overwrite']) {
                        $this->keepVersion($disk, $e['target'], $fs);
                    }
                    $in = fopen($entryTmp, 'rb');
                    $fs->writeStream($e['target'], $in);
                    if (is_resource($in)) {
                        fclose($in);
                    }
                    $this->registerExtractedFile($disk, $fs, $e['target'], $entryTmp);
                } finally {
                    @unlink($entryTmp);
                }
                $written++;
            }
            $za->close();
        } finally {
            @unlink($tmp);
        }

        return ['extracted' => $written, 'dest' => $destUser, 'bytes' => $total];
    }

    /**
     * Register a freshly-extracted file in the same pipeline as an upload so it
     * isn't a second-class citizen: folder index (search), metadata (owner / size /
     * mime / image dims), content hash (dedup), and image variants (thumbnails).
     * Best-effort per step — a failure on one file never aborts the extract. AI
     * auto-tag is intentionally skipped (would be costly per file on a bulk extract).
     *
     * @param string $key       scoped destination key (already written to $fs)
     * @param string $localPath local copy of the file's bytes (for dims/mime/variants)
     */
    private function registerExtractedFile(string $disk, $fs, string $key, string $localPath): void
    {
        $this->meta->trackParents($disk, $key); // folder search index
        $name = basename($key);
        $attrs = [
            'uploaded_by' => $this->claims->userId,
            'size'        => (int) (@filesize($localPath) ?: 0),
            'modified'    => time(),
            'created'     => time(),
        ];
        $mime = @mime_content_type($localPath);
        if ($mime !== false && $mime !== '' && $mime !== null) {
            $attrs['mime'] = $mime;
        }
        $isImage = $this->imageOptimizer->isImage($name);
        if ($isImage) {
            $dims = @getimagesize($localPath);
            if (is_array($dims) && isset($dims[0], $dims[1])) {
                $attrs['width']  = (int) $dims[0];
                $attrs['height'] = (int) $dims[1];
            }
        }
        $this->meta->save($disk, $key, $attrs); // also adds the file to the search index

        $hash = @hash_file('sha256', $localPath);
        if ($hash !== false) {
            $this->meta->saveHash($disk, $key, $hash);
        }
        if ($isImage) {
            try {
                $this->imageOptimizer->process($fs, $key, $localPath); // thumb/medium/large WebP
            } catch (\Throwable $e) {
                error_log('FluxFiles: variant gen for extracted file failed — ' . $e->getMessage());
            }
        }
    }

    /** A safe download filename: the caller's name, else a single selection's name, else 'files'. */
    private function zipFilename(?string $name, array $paths): string
    {
        $base = ($name !== null && trim($name) !== '') ? trim($name) : '';
        if ($base === '' && count($paths) === 1) {
            $base = basename(rtrim((string) $paths[0], '/'));
        }
        $base = (string) preg_replace('/\.zip$/i', '', $base);
        $base = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base);
        $base = trim($base);
        return ($base === '' ? 'files' : $base) . '.zip';
    }

    private function permanentUrl(string $disk, string $path): ?string
    {
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') === 'local') {
            // A gated (private) local disk has no stable URL — only the short-lived
            // tokened stream URL — so there's no permanent URL to embed.
            if ($this->isGatedLocal($config)) {
                return null;
            }
            return rtrim($config['url'] ?? '/storage/uploads', '/') . '/' . $path;
        }

        $publicUrl = rtrim($config['public_url'] ?? '', '/');
        if ($publicUrl !== '') {
            return $publicUrl . '/' . $path;
        }

        if (($config['visibility'] ?? 'private') === 'public') {
            $bucket = $config['bucket'] ?? '';
            if (!empty($config['endpoint'])) {
                return rtrim($config['endpoint'], '/') . '/' . $bucket . '/' . $path;
            }
            $region = $config['region'] ?? 'us-east-1';
            return "https://{$bucket}.s3.{$region}.amazonaws.com/{$path}";
        }

        return null;
    }

    private function fileUrl(string $disk, string $path): string
    {
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') === 'local') {
            // Gated local disk → tokened stream URL; otherwise the static disk URL.
            if ($this->isGatedLocal($config)) {
                return $this->gatedLocalUrl($disk, $path);
            }
            $baseUrl = rtrim($config['url'] ?? '/storage/uploads', '/');
            return $baseUrl . '/' . $path;
        }

        // SFTP has neither a static URL nor presigned URLs — every download/preview
        // is streamed through the app via a tokened /api/fm/stream link (the same
        // mechanism gated local media uses). Needs a stream secret to be set.
        if (($config['driver'] ?? '') === 'sftp') {
            return $this->streamSecret !== ''
                ? $this->gatedLocalUrl($disk, $path)
                : ''; // feature off → no servable URL
        }

        // S3/R2. Private disks (the default) are served through short-lived presigned
        // GET URLs so objects work even on private buckets. Public disks return a direct
        // URL — using public_url (CDN / R2 custom domain) when set, otherwise the
        // bucket/endpoint URL.
        $visibility = $config['visibility'] ?? 'private';
        if ($visibility !== 'public') {
            // Media files get a longer presigned TTL (preview_url_ttl) so a long
            // video doesn't expire mid-playback; everything else uses the disk default.
            $ttlOverride = ($this->claims->previewUrlTtl > 0 && Claims::isMediaPath($path))
                ? $this->claims->previewUrlTtl
                : null;
            $signed = $this->presignGetUrl($disk, $path, $config, $ttlOverride);
            if ($signed !== null) {
                return $signed;
            }
            // Fall through to a direct URL if presigning is unavailable.
        }

        $publicUrl = rtrim($config['public_url'] ?? '', '/');
        if ($publicUrl !== '') {
            return $publicUrl . '/' . $path;
        }

        $bucket = $config['bucket'] ?? '';
        if (!empty($config['endpoint'])) {
            return rtrim($config['endpoint'], '/') . '/' . $bucket . '/' . $path;
        }
        $region = $config['region'] ?? 'us-east-1';
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$path}";
    }

    /**
     * Build a presigned GET URL for an S3/R2 object so private buckets can serve it.
     * TTL is taken from the disk's `url_ttl` (default 1 hour), clamped to MAX_PRESIGN_TTL.
     * Returns null if signing is not possible so the caller can fall back.
     */
    private function presignGetUrl(string $disk, string $path, array $config, ?int $ttlOverride = null): ?string
    {
        $ttl = $ttlOverride ?? (int) ($config['url_ttl'] ?? 3600);
        if ($ttl < 1) {
            $ttl = 3600;
        }
        if ($ttl > self::MAX_PRESIGN_TTL) {
            $ttl = self::MAX_PRESIGN_TTL;
        }

        try {
            $client = $this->disks->s3Client($disk);
            $cmd = $client->getCommand('GetObject', [
                'Bucket'                     => $config['bucket'] ?? '',
                'Key'                        => $path,
                'ResponseContentDisposition' => $this->presignDisposition($path),
                'ResponseContentType'        => self::presignContentType($path),
            ]);
            $request = $client->createPresignedRequest($cmd, "+{$ttl} seconds");
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            error_log('FluxFiles: presign GET URL failed — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Content-Disposition to sign into a presigned S3/R2 GET URL.
     *
     * The same inline-safe set as handleMediaStream() and the Share route: media,
     * raster images and PDF stay inline so <video>/<img>/pdf preview works, and
     * everything else — .svg and .html above all — is forced to attachment.
     *
     * This matters even though the bucket is a different origin from FluxFiles (so
     * no main JWT is reachable): an .svg served inline still executes as active
     * content in the BUCKET's origin, and a custom R2/CDN domain is commonly a
     * subdomain of the host app's site, which makes it same-site for SameSite=Lax
     * cookies. Nothing sanitizes SVG on the way in (ImageOptimizer leaves it alone
     * by design), so the disposition is the control.
     */
    /**
     * Content-Type to sign into a presigned S3/R2 GET URL, derived from the
     * extension — never from what the object was stored with. The stored
     * Content-Type is client-controlled: it is not part of a presigned PUT's
     * signature, and Flysystem sniffs it from the bytes on a string write. So a
     * `photo.png` PUT with `Content-Type: text/html` would otherwise come back
     * from the bucket as inline (by extension, above) HTML. Overriding it here
     * keeps the disposition and the type in agreement.
     */
    public static function presignContentType(string $path): string
    {
        return (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }

    private function presignDisposition(string $path): string
    {
        // MIME by extension, never sniffed — an .html that sniffs as text/html must
        // not become active content.
        $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())
            ->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
        $inline = (bool) preg_match('#^(video/|audio/|image/(?!svg))|^application/pdf$#', $mime);

        $name  = basename($path);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'file';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return ($inline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"'
            . "; filename*=UTF-8''" . rawurlencode($name);
    }

    /**
     * When owner_only is enabled, verify the current user owns the file.
     * Skips check for directories (no ownership metadata) and for files
     * without uploaded_by (legacy files uploaded before owner tracking).
     */
    private function assertOwner(string $disk, string $scopedPath): void
    {
        if (!$this->claims->ownerOnly) {
            return;
        }

        $meta = $this->meta->get($disk, $scopedPath);
        if ($meta === null) {
            // No metadata — likely a directory or legacy file; allow
            return;
        }

        $owner = $meta['uploaded_by'] ?? null;
        if ($owner === null) {
            // Legacy file without ownership info; allow
            return;
        }

        if ($owner !== $this->claims->userId) {
            throw new ApiException('You can only modify files you uploaded', 403, 'owner_only');
        }
    }

    /**
     * When owner_only is enabled, verify the current user owns every file inside a
     * directory before a folder-level delete/rename/move. Without this, the dir-path
     * assertOwner() (which skips directories) would let a user wipe/relocate a folder
     * full of other people's files. Files with no owner (legacy / pre-existing) are
     * allowed; enumeration failures fall through (best-effort, like elsewhere).
     */
    private function assertOwnsTree(string $disk, string $scopedDir): void
    {
        if (!$this->claims->ownerOnly) {
            return;
        }

        $fs = $this->disks->disk($disk);
        try {
            foreach ($fs->listContents($scopedDir, true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $key = $item->path();
                if ($this->isReservedSystemPath($key)) {
                    continue;
                }
                $meta = $this->meta->get($disk, $key);
                $owner = $meta['uploaded_by'] ?? null;
                if ($owner !== null && $owner !== $this->claims->userId) {
                    throw new ApiException('This folder contains files you do not own', 403, 'owner_only');
                }
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Could not enumerate the tree — fall through; the operation itself will
            // surface any real storage error.
        }
    }

    /**
     * Legal hold enforcement — free/core and license-independent (see
     * docs/design/RETENTION-LEGAL-HOLD-DESIGN.md §2/§5). Unlike every other paid-module
     * hook on this class (virusScanner/dlpScanner/versionKeeper/…), this check
     * has NO installed/licensed/claim gate at all: once a hold exists in
     * holds.json, it blocks delete/trash/rename/move/cross-move/purge
     * unconditionally, so a lapsed license or an uninstalled module can never
     * silently stop enforcing an already-placed hold. Only PLACING/RELEASING a
     * hold (the `legal-hold` module's own routes) is paid-gated.
     */
    private function assertNoActiveHold(string $disk, string $scopedPath): void
    {
        $hold = $this->meta->holdBlocking($disk, $scopedPath);
        if ($hold !== null) {
            // The client sees its own (tenant-relative) path, never the internal prefix.
            $params = ['hold_id' => $hold['hold_id'] ?? null, 'path' => $this->outKey($scopedPath)];
            if ($this->claims->hasPerm('audit')) {
                $params['reason'] = $hold['reason'] ?? null;
                $params['placed_by'] = $hold['placed_by'] ?? null;
                $params['placed_at'] = $hold['placed_at'] ?? null;
            }
            throw new ApiException('This item is under legal hold and cannot be modified', 403, 'legal_hold_active', $params);
        }
    }

    /**
     * Block access to internal system paths (_fluxfiles/, _variants/) and to the
     * reserved "{name}.meta.json" filename shape. The latter is a normal, writable
     * path in the user's own namespace, but StorageMetadataHandler treats any
     * legacy sidecar found there as authoritative (including `uploaded_by`) — so
     * without this guard a plain `write`-permission upload/rename/move/copy could
     * forge one and hijack ownership of the real target file. Reading pre-existing
     * legacy sidecars still works (backward compat); this only closes new writes.
     */
    /**
     * $disk is optional and ADDITIVE: when given and the disk is SFTP, this also
     * refuses any path with a `.git` segment (see isGitInternalSegment() for why
     * SFTP specifically, and GitDeploy's config audit for the RCE this closes at
     * the root rather than only auditing the symptom). Pure-read call sites
     * (list/getContent/fileMeta/presign/getMode) intentionally omit $disk —
     * this check targets writes, which is where the attack actually lands.
     */
    private function assertNotSystem(string $scopedPath, string $disk = ''): void
    {
        if ($this->isReservedSystemPath($scopedPath)) {
            throw new ApiException('Access denied: system path', 403, 'system_path');
        }
        if ($disk !== '' && self::isGitInternalSegment($scopedPath)
            && ($this->disks->config($disk)['driver'] ?? '') === 'sftp') {
            throw new ApiException('Access denied: git-internal path', 403, 'git_internal_path');
        }
    }

    /**
     * True when any path segment is `.git` (case-insensitively, same reasoning
     * as isReservedKey()'s case-fold — an SFTP server may sit on a
     * case-insensitive filesystem too). `.gitignore`/`.gitattributes` are
     * SIBLING files at a repo's root, not a `.git` segment, so they are
     * unaffected and stay writable; only the internal directory itself (and
     * anything nested under it, e.g. `.git/config`, `.git/hooks/post-merge`)
     * is blocked.
     */
    /**
     * `show_hidden=false` used to be list()-only cosmetics: a dotfile (`.env`,
     * `.git/config`, `.htpasswd`) was hidden from the listing yet still readable by
     * name through /content, /presign, /zip, /meta. Enforce it on direct access too,
     * so the claim means what operators reasonably read it as. Judged on the path
     * RELATIVE to the tenant prefix, so a prefix that itself has a dotted segment
     * never hides the tenant's whole tree.
     */
    private function assertNotHidden(string $scopedPath): void
    {
        if ($this->isHiddenForToken($scopedPath)) {
            throw new ApiException('Access denied to path', 403, 'path_denied');
        }
    }

    private function isHiddenForToken(string $scopedPath): bool
    {
        if ($this->claims->showHidden) {
            return false;
        }
        foreach (explode('/', trim($this->claims->unscopePath($scopedPath), '/')) as $seg) {
            if ($seg !== '' && $seg[0] === '.') {
                return true;
            }
        }
        return false;
    }

    private static function isGitInternalSegment(string $key): bool
    {
        foreach (explode('/', trim($key, '/')) as $seg) {
            if (strcasecmp($seg, '.git') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Non-throwing counterpart to assertNotSystem() for callers (e.g. Backup) that
     * need to SKIP reserved paths while walking a tree, rather than reject a single
     * write. Keep this in sync with assertNotSystem() — same reserved-path rules
     * (it's the sole implementation; assertNotSystem() just wraps it in a throw).
     */
    public function isReservedSystemPath(string $scopedPath): bool
    {
        if (self::isReservedKey($scopedPath)) {
            return true;
        }
        // Reserved legacy-sidecar filename shape — see doc-comment above.
        if (strcasecmp(substr($scopedPath, -10), '.meta.json') === 0) {
            return true;
        }
        return false;
    }

    /**
     * THE single reserved-namespace rule: true when any path segment names a
     * FluxFiles-internal directory (`_fluxfiles/` bookkeeping, `_variants/`
     * derivatives), at any depth, with or without a trailing slash.
     *
     * The comparison is deliberately CASE-INSENSITIVE. The storage key is not
     * the whole story: on a case-insensitive filesystem (APFS by default,
     * Windows, most SMB/NFS mounts) a write to `_FLUXFILES/trash.json` passes a
     * case-sensitive guard and then lands on the real `_fluxfiles/trash.json` —
     * which is enough to poison the search index + hash dedup, forge audit
     * lines, hijack a sidecar's `uploaded_by` (which assertOwner trusts), or
     * plant a `trash.json` whose `original_key` becomes an attacker-chosen
     * `move()` source. The `.meta.json` arm above was already case-folded; this
     * closes the same hole for the directory arms.
     *
     * This is the sole implementation — StorageMetadataHandler::isReservedPath()
     * and QuotaManager both call it, because having the rule written out four
     * times is exactly how three of the copies ended up case-sensitive.
     */
    public static function isReservedKey(string $key): bool
    {
        foreach (explode('/', trim($key, '/')) as $seg) {
            foreach (self::SYSTEM_PREFIXES as $prefix) {
                if (strcasecmp($seg, rtrim($prefix, '/')) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function assertDisk(string $disk): void
    {
        if (!$this->claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403, 'disk_denied');
        }
    }

    private function assertPerm(string $perm): void
    {
        if (!$this->claims->hasPerm($perm)) {
            throw new ApiException("Permission denied: {$perm}", 403, 'permission_denied');
        }
    }

    /**
     * Largest upload `strip_exif` reads into memory to rewrite. Photos are far
     * below this; a bigger file is stored as-is rather than risk the memory limit.
     */
    private const STRIP_EXIF_MAX = 64 * 1024 * 1024;

    /** Largest stored file scanForViruses() stages to a temp file for the scanner. */
    private const SCAN_MAX_BYTES = 512 * 1024 * 1024;

    /** Internal directories that must never be exposed or modified by users. */
    private const SYSTEM_PREFIXES = ['_fluxfiles/', '_variants/'];

    /** Extensions that are always blocked regardless of allowedExt. */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps',
        // Handler-mapped on common Apache/cPanel (EasyApache `.php8`, older
        // Debian/Ubuntu `ph(p[3457]?|t|tml)`), plus server-side includes.
        'php8', 'pht', 'phtm', 'phpt', 'pgif', 'shtml', 'shtm', 'stm',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'bat', 'cmd', 'com',
        'exe', 'dll', 'msi', 'scr', 'vbs', 'wsf', 'jsp', 'jspx',
        'asp', 'aspx', 'asa', 'ashx', 'asmx', 'htaccess', 'htpasswd',
    ];

    /**
     * Whole filenames that reconfigure the web server / PHP for the directory
     * they land in, regardless of extension: `.user.ini` (PHP-FPM per-dir ini —
     * `auto_prepend_file` turns any upload into code), `web.config` (IIS
     * handler mappings). Compared case-insensitively.
     */
    private const DANGEROUS_FILENAMES = ['.user.ini', 'web.config', '.htaccess', '.htpasswd'];

    private function assertExt(string $ext): void
    {
        if ($this->claims->allowedExt === null) {
            return;
        }
        if (!in_array($ext, $this->claims->allowedExt, true)) {
            throw new ApiException("File extension not allowed: {$ext}", 403, 'ext_not_allowed', ['ext' => $ext]);
        }
    }

    /**
     * A relocation (move/copy, same- or cross-disk) may rename a file but must
     * not change its type: the destination extension has to match the source
     * AND stay within the JWT allowedExt policy. This closes the move/copy
     * vectors for bypassing the upload extension rules (mirrors rename).
     */
    private function assertRelocationExt(string $scopedFrom, string $scopedTo): void
    {
        // Only rename() used to call assertSafeFilename() — move/copy/crossMove/
        // crossCopy all funnel through here, so checking it once here closes the
        // double-extension gap (e.g. "shell.php.jpg") for all four at once.
        $this->assertSafeFilename(basename($scopedTo));

        $srcExt = strtolower(pathinfo($scopedFrom, PATHINFO_EXTENSION));
        $dstExt = strtolower(pathinfo($scopedTo, PATHINFO_EXTENSION));
        if ($srcExt !== $dstExt) {
            throw new ApiException(
                'Changing the file extension is not allowed',
                400,
                'ext_changed',
                ['from' => $srcExt, 'to' => $dstExt]
            );
        }
        $this->assertExt($dstExt);
    }

    /**
     * Check ALL extensions in a filename to block double-extension attacks
     * like "shell.php.jpg" which some servers may execute as PHP.
     */
    private function assertSafeFilename(string $name): void
    {
        // Trailing dots/spaces are dropped by Windows filesystems, so "x.php " or
        // "x.php." lands as "x.php" — compare on the name Windows would keep.
        $name = rtrim($name, ". \t");
        if (in_array(strtolower($name), self::DANGEROUS_FILENAMES, true)) {
            throw new ApiException("Dangerous filename: {$name}", 403, 'ext_dangerous');
        }
        $parts = explode('.', strtolower($name));
        array_shift($parts); // remove the base name
        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new ApiException("Dangerous extension detected in filename: {$part}", 403, 'ext_dangerous');
            }
        }
    }

    /**
     * The last segment of a key — locale-independent, unlike basename(), so it
     * can be compared byte-for-byte against a validated name.
     */
    private static function leafOf(string $key): string
    {
        $key = rtrim($key, '/');
        $pos = strrpos($key, '/');
        return $pos === false ? $key : substr($key, $pos + 1);
    }

    /**
     * Name checks on the FINAL storage key of a file write — never on the
     * client-supplied name it was built from, since scopedPath() can collapse
     * `.`/`..`/empty segments and promote a different segment to the leaf.
     * Refuses a target that is the tenant root itself or a dot-only leaf, then
     * applies the allowed_ext and dangerous-name rules (upload()'s order) to the
     * real leaf.
     */
    private function assertFileLeaf(string $scopedKey): void
    {
        $leaf = self::leafOf($scopedKey);
        if (trim($this->outKey($scopedKey), '/') === '' || trim($leaf, ". \t") === '') {
            throw new ApiException('Name contains invalid characters', 400, 'name_invalid');
        }
        $this->assertExt(strtolower(pathinfo($leaf, PATHINFO_EXTENSION)));
        $this->assertSafeFilename($leaf);
    }

    /**
     * Reject if a file or directory already exists at the destination, so
     * rename/move/copy never silently overwrite. Some adapters throw on the
     * directoryExists() probe — that is treated as "not a directory", not a hard error.
     */
    private function assertTargetAvailable($fs, string $target): void
    {
        try {
            if ($fs->fileExists($target) || $fs->directoryExists($target)) {
                throw new ApiException('A file or folder with that name already exists', 409, 'name_exists');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Some adapters may throw on the existence probe; allow the operation.
        }
    }

    private function assertUploadSize(float $sizeMb): void
    {
        if ($sizeMb > $this->claims->maxUploadMb) {
            throw new ApiException(
                "File too large: {$sizeMb}MB exceeds limit of {$this->claims->maxUploadMb}MB",
                413,
                'upload_too_large',
                ['max' => $this->claims->maxUploadMb . 'MB']
            );
        }
    }

    public function validateUploadName(string $name, int $sizeBytes = 0): void
    {
        // A dot-only/empty name is a path segment scopedPath() would drop, not a
        // file name — the write would land on whatever segment precedes it.
        if (trim($name, ". \t") === '') {
            throw new ApiException('Name contains invalid characters', 400, 'name_invalid');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->assertExt($ext);
        $this->assertSafeFilename($name);
        if ($sizeBytes > 0) {
            $this->assertUploadSize($sizeBytes / (1024 * 1024));
        }
    }

    /**
     * $disk is optional for backward compatibility with module callers; pass it
     * on any WRITE so the SFTP `.git` block in assertNotSystem() applies too.
     */
    public function validateUserPath(string $path, string $disk = ''): string
    {
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped, $disk);
        // show_hidden=false covers module callers too (share/ocr/ai/c2pa/backup/
        // versioning/intake all scope through here) — otherwise a public share
        // link or an OCR read of `.env` sidesteps the claim core enforces.
        $this->assertNotHidden($scoped);
        return $scoped;
    }

    /**
     * Despite the name, callers historically passed an already-prefixed key
     * that was NEVER routed through scopedPath()/Claims::normalizeKey() first
     * (the metadata and chunk-upload endpoints in index.php) — so a raw
     * "user_1/../user_2/x" traversal reached assertNotSystem() untouched and
     * then the caller's own downstream path-building untouched too. Normalize
     * here as the defense-in-depth safety net the name implies, and RETURN
     * the safe value — callers must use it (not their original variable) for
     * anything built from this path afterwards. Idempotent on an already
     * scoped/normalized path.
     */
    public function validateScopedPath(string $scopedPath, string $disk = ''): string
    {
        $scopedPath = $this->claims->normalizeKey($scopedPath);
        $this->assertNotSystem($scopedPath, $disk);
        return $scopedPath;
    }

    /**
     * Same defense-in-depth normalization as validateScopedPath() above, plus
     * the owner_only check. Returns the safe path — see validateScopedPath()'s
     * doc-comment for why callers must use the return value.
     */
    public function assertCanModifyScopedPath(string $disk, string $scopedPath): string
    {
        $scopedPath = $this->claims->normalizeKey($scopedPath);
        $this->assertNotSystem($scopedPath, $disk);
        $this->assertNotHidden($scopedPath);
        $this->assertOwner($disk, $scopedPath);
        $this->assertNoHoldIfExists($disk, $scopedPath);
        return $scopedPath;
    }

    /**
     * READ-type counterpart to assertCanModifyScopedPath(), for modules that use
     * a file as a SOURCE without changing it (share link, OCR, AI Vision / C2PA
     * input). Same normalization, scope, system-path, show_hidden and owner_only
     * checks — but NO legal-hold check: a hold freezes a record against change,
     * it doesn't forbid reading, sharing or processing a copy of it (doing so
     * made a held file unshareable — legal_hold_active on a pure read). Any
     * module WRITE still goes through assertCanModifyScopedPath() /
     * writeScopedFile(), which do check holds. Returns the safe path — callers
     * must use the return value (see validateScopedPath()).
     */
    public function assertCanUseScopedPath(string $disk, string $scopedPath): string
    {
        $scopedPath = $this->claims->normalizeKey($scopedPath);
        $this->assertScopedKeyInScope($scopedPath);
        $this->assertNotSystem($scopedPath);
        $this->assertNotHidden($scopedPath);
        $this->assertOwner($disk, $scopedPath);
        return $scopedPath;
    }

    /** Fail closed on an already-scoped key that isn't under the token's prefix. */
    private function assertScopedKeyInScope(string $scopedKey): void
    {
        if (!$this->claims->isPathInScope($scopedKey)) {
            throw new ApiException('Access denied to path', 403, 'path_denied');
        }
    }

    /**
     * Legal hold for an OVERWRITE: only an existing file can be "modified" — a new
     * file landing inside a held folder is allowed, exactly like move() lets a
     * file be moved INTO a held subtree.
     */
    private function assertNoHoldIfExists(string $disk, string $scopedPath): void
    {
        // Hold lookup first: it's one cached manifest/table read and almost always
        // empty, so the per-path existence probe only runs when a hold overlaps.
        if ($this->meta->holdBlocking($disk, $scopedPath) === null) {
            return;
        }
        try {
            $exists = $this->disks->disk($disk)->fileExists($scopedPath);
        } catch (\Throwable $e) {
            $exists = true; // can't tell → fail closed on a held path
        }
        if ($exists) {
            $this->assertNoActiveHold($disk, $scopedPath);
        }
    }

    /** Validate multipart writes before S3 completes them; init may choose a free name. */
    public function validateChunkUpload(string $disk, string $key, int $size, bool $resolveCollision = false): string
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');
        $key = $this->validateScopedPath($key, $disk);
        $this->validateUploadName(basename($key), $size);
        $fs = $this->disks->disk($disk);
        if ($fs->fileExists($key) && $this->claims->uploadCollision !== 'overwrite') {
            if (!$resolveCollision || $this->claims->uploadCollision === 'reject') {
                throw new ApiException('A file with this name already exists', 409, 'name_conflict');
            }
            $dir = dirname($key);
            $dir = $dir === '.' ? '' : $dir;
            $key = ($dir === '' ? '' : $dir . '/') . $this->uniqueName($fs, $dir, basename($key));
        }
        $exists = $fs->fileExists($key);
        if ($exists) {
            $this->assertOwner($disk, $key);
            $this->assertNoActiveHold($disk, $key);
        }
        if ($fs->directoryExists($key)) {
            throw new ApiException('A folder with this name already exists', 409, 'name_conflict');
        }
        if ($this->quotaManager !== null) {
            $delta = $size - ($exists ? (int) $fs->fileSize($key) : 0);
            $this->quotaManager->assertQuota($disk, $this->claims->pathPrefix, $delta, $this->claims->maxStorageMb);
            $this->quotaManager->assertFileCount($disk, $this->claims->pathPrefix, $exists ? 0 : 1, $this->claims->maxFiles);
        }
        return $key;
    }

    /**
     * Public wrapper around assertOwnsTree() for external modules (e.g. Backup)
     * that enumerate a directory subtree via raw Flysystem access rather than
     * FileManager's own per-file operations. Without this, owner_only would be
     * enforced on every FileManager write path but silently skipped by a module
     * that lists/copies bytes directly — same fail-closed check folder
     * delete/rename/move already get. No-op when owner_only is off.
     */
    public function assertCanReadTree(string $disk, string $scopedDir): void
    {
        $this->assertOwnsTree($disk, $scopedDir);
    }

    /**
     * Write module-generated bytes (AI Vision, C2PA, …) to a path already validated
     * with validateUserPath()/assertCanModifyScopedPath(). Modules must go through
     * this rather than touching the disk directly: it's the only way their write
     * gets the same allowed_ext, dangerous-extension, and virus-scan checks every
     * other write path (upload/putContent/move/copy) already gets — a raw
     * $fs->write() from a module would silently skip all three. It also bounds
     * the write by max_storage (byte delta) + max_files (new files only) and
     * snapshots an existing target via the Versioning hook before overwriting
     * it — so a caller must NOT keepVersion()/snapshot it again itself.
     */
    public function writeScopedFile(string $disk, string $scopedPath, string $content): void
    {
        // Module callers validate with validateUserPath(), historically without a
        // disk — re-check here, where the disk is known, so a module write can't
        // reach `_fluxfiles/` or an SFTP repo's `.git/`. The disk, `write` and scope
        // checks are the module's job too, but a write primitive shouldn't rely on
        // every future caller remembering them.
        $this->assertDisk($disk);
        $this->assertPerm('write');
        $scopedPath = $this->validateScopedPath($scopedPath, $disk);
        $this->assertScopedKeyInScope($scopedPath);
        $this->assertNoHoldIfExists($disk, $scopedPath);
        $this->assertFileLeaf($scopedPath);

        $fs = $this->disks->disk($disk);
        $existing = $this->existingSize($fs, $scopedPath);
        $this->assertWriteFits($disk, strlen($content), $existing);
        if ($this->virusScanner !== null || $this->dlpScanner !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'ffwsf');
            if ($tmp === false || @file_put_contents($tmp, $content) === false) {
                if (is_string($tmp)) { @unlink($tmp); }
                $code = $this->virusScanner !== null ? 'virus_failed' : 'dlp_failed';
                throw new ApiException('Could not stage content for scanning', 500, $code);
            }
            try {
                $this->assertNoVirus($tmp, basename($scopedPath));
                // PII scan (paid DLP module) — module-generated bytes (AI Vision output,
                // C2PA-signed output, …) go through this same shared write path, so DLP
                // coverage is free for any future module that writes through it too.
                $this->assertNoPii($tmp, basename($scopedPath));
            } finally {
                @unlink($tmp);
            }
        }
        // In-place overwrite (AI/C2PA output onto its source, version restore,
        // in-place crop/watermark) → snapshot first, like upload()/putContent().
        if ($existing !== null) {
            $this->keepVersion($disk, $scopedPath, $fs);
        }
        $fs->write($scopedPath, $content);

        $mime = null;
        if (class_exists(\finfo::class)) {
            $m = @(new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            $mime = is_string($m) && $m !== '' ? $m : null;
        }
        $dims = $this->imageOptimizer->isImage($scopedPath) ? @getimagesizefromstring($content) : false;
        $this->recordModuleWrite($disk, $scopedPath, $existing === null, strlen($content),
            hash('sha256', $content), $mime, is_array($dims) ? $dims : null);
    }

    /**
     * Descriptive metadata + content hash for a file a module wrote through
     * writeScopedFile()/writeScopedStream(), in ONE index write — what upload()
     * records, so list() carries mime/width/height (→ img_srcset, select payload)
     * and duplicate detection sees the file. Ownership: a NEW file is owned by the
     * acting user like an upload (owner_only stays consistent); an overwrite sends
     * neither `uploaded_by` nor `created`, and the save() merge keeps the existing
     * owner and any user-entered title/alt/caption/tags.
     *
     * @param array<int,mixed>|null $dims getimagesize() result
     */
    private function recordModuleWrite(string $disk, string $key, bool $isNew, int $size, ?string $hash, ?string $mime, ?array $dims): void
    {
        $attrs = ['size' => $size, 'modified' => time()];
        if ($isNew) {
            $attrs['uploaded_by'] = $this->claims->userId;
            $attrs['created'] = time();
        }
        if ($mime !== null) {
            $attrs['mime'] = $mime;
        }
        if ($dims !== null && isset($dims[0], $dims[1])) {
            $attrs['width']  = (int) $dims[0];
            $attrs['height'] = (int) $dims[1];
        }
        $this->beginIndexBatch($disk);
        try {
            if ($hash !== null) {
                $this->meta->saveHash($disk, $key, $hash);
            }
            $this->meta->save($disk, $key, $attrs);
        } finally {
            $this->endIndexBatch($disk);
        }
    }

    /** Size of an existing file at $key, or null when nothing is there. */
    private function existingSize($fs, string $key): ?int
    {
        try {
            return $fs->fileExists($key) ? (int) ($fs->fileSize($key) ?? 0) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Quota (byte delta) + max_files (only for a NEW file) for a write of
     * $newBytes over a file of $existingBytes (null = doesn't exist yet). The
     * shared bound for the module write helpers and crop/watermark "save as",
     * which create files exactly like upload()/copy() do.
     */
    private function assertWriteFits(string $disk, int $newBytes, ?int $existingBytes): void
    {
        if ($this->quotaManager === null) {
            return;
        }
        if ($this->claims->maxStorageMb > 0) {
            $this->quotaManager->assertQuota(
                $disk,
                $this->claims->pathPrefix,
                $newBytes - (int) $existingBytes,
                $this->claims->maxStorageMb
            );
        }
        if ($this->claims->maxFiles > 0 && $existingBytes === null) {
            $this->quotaManager->assertFileCount($disk, $this->claims->pathPrefix, 1, $this->claims->maxFiles);
        }
    }

    /**
     * Attach a module-generated "derived" file's ownership metadata (copied from
     * its source) and register it in the search/folder index — the same
     * bookkeeping cropImage()/applyWatermark() do internally for their own "save
     * as" output via copyMetadata()+trackParents(), exposed here so a module
     * writing through writeScopedFile()/writeScopedStream() can do the same.
     * Without this, the new file has no `uploaded_by` and assertOwner() treats a
     * missing owner as "legacy; allow" — an ownerless orphan any co-tenant can
     * modify/delete even under owner_only. No-op when src === dst (in-place write,
     * nothing "derived"). $srcScopedPath/$dstScopedPath must already be scoped
     * (validateUserPath()/assertCanModifyScopedPath() output).
     */
    public function attachDerivedFile(string $disk, string $srcScopedPath, string $dstScopedPath): void
    {
        if ($srcScopedPath === $dstScopedPath) {
            return;
        }
        // Only the user-facing fields: size/mime/dimensions/dates describe the NEW
        // bytes (recorded by the write), and the source's would clobber them.
        $src = $this->meta->get($disk, $srcScopedPath);
        if ($src) {
            $this->meta->save($disk, $dstScopedPath, array_diff_key(
                $src,
                array_flip(['size', 'modified', 'created', 'mime', 'width', 'height'])
            ));
        }
        $this->meta->trackParents($disk, $dstScopedPath);
    }

    /**
     * Cross-disk counterpart to attachDerivedFile() for a module that streamed a
     * file from $srcDisk to $dstDisk through writeScopedStream() (Backup's sync):
     * copies the source's metadata (uploaded_by/title/alt/tags…) onto the
     * destination key and registers it in the DESTINATION disk's search/folder
     * index — the same tail crossCopy() runs after its own write. Without it the
     * synced copy has no owner on $dstDisk (assertOwner() → "legacy; allow" →
     * owner_only bypassed there) and is invisible to search. Variants are NOT
     * transferred (a backup is bytes + bookkeeping, not a full cross-copy). Both
     * paths must already be scoped. No-op when src and dst are the same disk+key.
     */
    public function attachCopiedFile(string $srcDisk, string $srcScopedPath, string $dstDisk, string $dstScopedPath): void
    {
        if ($srcDisk === $dstDisk && $srcScopedPath === $dstScopedPath) {
            return;
        }
        $this->copyMetadata($srcDisk, $srcScopedPath, $dstDisk, $dstScopedPath);
        $this->meta->trackParents($dstDisk, $dstScopedPath);
    }

    /**
     * Stream-based counterpart to writeScopedFile() for module writes too large to
     * buffer as a string (Backup's cross-disk sync). Same ext/filename/virus-scan
     * checks; stages $stream to a local temp file so the scanner (which needs a
     * local path) can see it regardless of either disk's driver, then streams that
     * temp file to the destination — never holds the whole file in PHP memory.
     *
     * Also enforces the destination's quota (same as crossCopy()/crossMove()) and
     * snapshots an existing target via the Versioning hook before overwriting it
     * (same as upload()) — Backup's sync writes through here specifically to share
     * these checks with every other write path, so they can't be skipped by going
     * around FileManager.
     */
    public function writeScopedStream(string $disk, string $scopedPath, $stream): void
    {
        $this->assertDisk($disk);            // see writeScopedFile()
        $this->assertPerm('write');
        $scopedPath = $this->validateScopedPath($scopedPath, $disk);
        $this->assertScopedKeyInScope($scopedPath);
        $this->assertNoHoldIfExists($disk, $scopedPath);
        $this->assertFileLeaf($scopedPath);

        $fs = $this->disks->disk($disk);
        $tmp = tempnam(sys_get_temp_dir(), 'ffwss');
        if ($tmp === false) {
            throw new ApiException('Could not stage content for scanning', 500, 'virus_failed');
        }
        try {
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                throw new ApiException('Could not stage content for scanning', 500, 'virus_failed');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);

            // Quota (delta over an existing target) + max_files for a new one —
            // crossCopy()/crossMove() bound the same way; checked before the
            // scanners below so a rejection short-circuits without spending time
            // scanning bytes that won't be written anyway.
            $this->assertWriteFits($disk, (int) filesize($tmp), $this->existingSize($fs, $scopedPath));

            if ($this->virusScanner !== null) {
                $this->assertNoVirus($tmp, basename($scopedPath));
            }
            if ($this->dlpScanner !== null) {
                $this->assertNoPii($tmp, basename($scopedPath));
            }
            $in = fopen($tmp, 'rb');
            if ($in === false) {
                throw new ApiException('Could not stage content for write', 500, 'virus_failed');
            }
            // Snapshot the pre-existing target before it's overwritten (Versioning
            // module) — same as upload()'s overwrite path. Backup's caller already
            // enforces owner_only (assertCanModifyScopedPath()) before calling here,
            // per this method's contract of not calling assertOwner() itself.
            if ($fs->fileExists($scopedPath)) {
                $this->keepVersion($disk, $scopedPath, $fs);
            }
            $isNew = !$fs->fileExists($scopedPath);
            $fs->writeStream($scopedPath, $in);
            if (is_resource($in)) { fclose($in); }

            $mime = @mime_content_type($tmp);
            $dims = $this->imageOptimizer->isImage($scopedPath) ? @getimagesize($tmp) : false;
            $hash = hash_file('sha256', $tmp);
            $this->recordModuleWrite($disk, $scopedPath, $isNew, (int) filesize($tmp),
                $hash === false ? null : $hash, is_string($mime) && $mime !== '' ? $mime : null,
                is_array($dims) ? $dims : null);
        } finally {
            @unlink($tmp);
        }
    }
}

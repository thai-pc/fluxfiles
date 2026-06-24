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

    /** @var MetadataRepositoryInterface */
    private $meta;

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
     * Enable gated local media: when set, files on a local disk marked
     * `'private' => true` are served through a per-file /api/fm/stream token
     * instead of a static URL. Without a secret the feature stays off.
     */
    public function setStreamSecret(string $secret): void
    {
        $this->streamSecret = $secret;
    }

    public function list(string $disk, string $path, int $limit = 0, string $cursor = ''): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $fs = $this->disks->disk($disk);

        $all = [];

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($scoped, false) as $item) {
            $name = basename($item->path());

            // Hide internal system directories.
            if ($name === '_fluxfiles' || $name === '_variants') {
                continue;
            }
            // Hide dotfiles (.env, .gitignore, .git/…) unless the token opts in —
            // matches Finder / cPanel / Nextcloud, and keeps a `.env` out of sight.
            if (!$this->claims->showHidden && $name !== '' && $name[0] === '.') {
                continue;
            }
            // Hide a LEGACY sidecar ({file}.meta.json next to its file) but show genuine
            // user-uploaded *.meta.json files (no matching base file). New sidecars live
            // under _fluxfiles/meta/ and are already hidden by the check above.
            if (substr($name, -10) === '.meta.json' && $item->isFile()
                && $fs->fileExists(substr($item->path(), 0, -10))
            ) {
                continue;
            }

            $entry = [
                'key'  => $item->path(),
                'name' => $name,
                'type' => $item->isFile() ? 'file' : 'dir',
            ];

            if ($item->isFile()) {
                /** @var FileAttributes $item */
                $entry['size']     = $item->fileSize();
                $entry['modified'] = $item->lastModified();
                // Preview-only tokens (allow_download=false) get no clean download URL.
                if ($this->claims->allowDownload) {
                    $entry['url'] = $this->fileUrl($disk, $item->path());
                }
            } else {
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

        // Stable ordering for deterministic cursor pagination: dirs first, then files, both by key ASC.
        usort($all, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcmp($a['key'], $b['key']);
        });

        $total = count($all);

        if ($limit > 0) {
            // Skip past cursor (exclusive). The client echoes the relative cursor
            // we handed out, so re-scope it to the absolute key space first.
            if ($cursor !== '') {
                $absCursor = $this->scopedPath($cursor);
                $start = 0;
                foreach ($all as $i => $it) {
                    if ($it['key'] === $absCursor) {
                        $start = $i + 1;
                        break;
                    }
                }
                $all = array_slice($all, $start);
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
        $dirsCreated = ($this->meta instanceof StorageMetadataHandler)
            ? $this->meta->dirsCreated($disk)
            : [];
        foreach ($items as &$item) {
            if (($item['type'] ?? '') === 'dir') {
                $c = $dirsCreated[$item['key']] ?? null;
                if ($c !== null) {
                    $item['created'] = (int) $c;
                }
                continue;
            }
            if (($item['type'] ?? '') === 'file') {
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
                $item['variants'] = $this->claims->allowDownload
                    ? $this->getFileVariants($disk, $item['key'])
                    : null;
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
        $this->assertNotSystem($scoped);
        $fs = $this->disks->disk($disk);

        // Duplicate detection (SHA-256). Only report a duplicate if the candidate
        // actually exists on disk and isn't inside an internal directory — stale
        // index entries shouldn't surface as phantom duplicates to the user.
        $hash = hash_file('sha256', $file['tmp_name']);
        if ($hash !== false && !$forceUpload) {
            $ownerFilter = $this->claims->ownerOnly ? $this->claims->userId : null;
            $existing = $this->meta->findByHash($disk, $hash, $this->claims->pathPrefix, $ownerFilter);
            if ($existing) {
                $existingKey = $existing['file_key'];
                $isSystem = str_starts_with($existingKey, '_fluxfiles/')
                    || str_starts_with($existingKey, '_variants/')
                    || str_contains($existingKey, '/_fluxfiles/')
                    || str_contains($existingKey, '/_variants/');
                if (!$isSystem && $fs->fileExists($existingKey)) {
                    return $this->unscopeItems([[
                        'key'       => $existingKey,
                        'url'       => $this->fileUrl($disk, $existingKey),
                        'name'      => basename($existingKey),
                        'duplicate' => true,
                        'message'   => 'File already exists. Use force_upload to override.',
                        'variants'  => $this->getFileVariants($disk, $existingKey),
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
        if ($this->claims->uploadCollision !== 'overwrite' && $fs->fileExists($scoped)) {
            if ($this->claims->uploadCollision === 'reject') {
                throw new ApiException('A file with this name already exists', 409, 'name_conflict', ['name' => $name]);
            }
            // 'rename' → append -1, -2, … to the base name (extension preserved).
            $name   = $this->uniqueName($fs, rtrim($path, '/'), $name);
            $scoped = $this->scopedPath(rtrim($path, '/') . '/' . $name);
            $this->assertNotSystem($scoped);
        }

        // Track parent directories for global folder search (best-effort)
        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->trackParents($disk, $scoped);
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

        // Save file hash for duplicate detection
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

        // Record the on-upload optimization savings into the tenant's counter, and
        // surface them on the upload result so the UI can toast "saved N".
        $result = [
            'key'      => $scoped,
            'url'      => $this->fileUrl($disk, $scoped),
            'size'     => $file['size'],
            'name'     => $name,
            'variants' => null,
        ];
        if ($autoSaved > 0) {
            OptimizeStats::record($fs, $this->claims->pathPrefix, $autoSaved, 1);
            $result['optimized'] = true;
            $result['saved_bytes'] = $autoSaved;
        }

        // Generate image variants (thumb, medium, large as WebP)
        if ($this->imageOptimizer->isImage($name)) {
            try {
                $variants = $this->imageOptimizer->process($fs, $scoped, $file['tmp_name']);
                $variantUrls = [];
                foreach ($variants as $sizeName => $info) {
                    $variantUrls[$sizeName] = [
                        'url'    => $this->fileUrl($disk, $info['key']),
                        'key'    => $info['key'],
                        'width'  => $info['width'],
                        'height' => $info['height'],
                    ];
                }
                $result['variants'] = $variantUrls;
            } catch (\Throwable $e) {
                error_log('FluxFiles: Image optimization failed — ' . $e->getMessage());
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

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertOwner($disk, $scoped);
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
                $fs->deleteDirectory($scoped);
                $this->meta->deleteChildren($disk, $scoped);
                if ($this->meta instanceof StorageMetadataHandler) {
                    $this->meta->deleteDirPrefix($disk, $scoped);
                }
            } else {
                $this->deleteVariants($disk, $scoped);
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

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $this->assertOwner($disk, $scoped);

        if (!($this->meta instanceof StorageMetadataHandler)) {
            throw new ApiException('Trash is not available for this storage', 400, 'trash_unavailable');
        }

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

    /** Soft-delete a whole directory subtree (variants included) into trash. */
    private function trashDirectory(string $disk, string $scoped, string $id, string $trashDir, $fs): array
    {
        $this->assertOwnsTree($disk, $scoped);

        // Collect paths first so moving files doesn't disturb the recursive listing.
        $paths = [];
        try {
            foreach ($fs->listContents($scoped, true) as $item) {
                if ($item->isFile()) {
                    $paths[] = $item->path();
                }
            }
        } catch (\Throwable $e) {
            throw new ApiException('Trash failed: ' . $e->getMessage(), 500, 'trash_failed');
        }

        $prefix = rtrim($scoped, '/') . '/';
        $files  = [];
        $total  = 0;
        foreach ($paths as $p) {
            $rel = substr($p, strlen($prefix));
            // Snapshot metadata for real files (variant files have none).
            $meta = (strpos($rel, '_variants/') === false) ? ($this->meta->get($disk, $p) ?: []) : [];
            try { $total += (int) $fs->fileSize($p); } catch (\Throwable $e) { /* best effort */ }
            try {
                $fs->move($p, $trashDir . '/payload/' . $rel);
            } catch (\Throwable $e) {
                throw new ApiException('Trash failed: ' . $e->getMessage(), 500, 'trash_failed');
            }
            $files[] = ['rel' => $rel, 'meta' => $meta];
        }

        try { $fs->deleteDirectory($scoped); } catch (\Throwable $e) { /* best effort */ }
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
        ]);

        return ['trashed' => true, 'trash_id' => $id];
    }

    /** Restore a trashed file or directory to its original key (or $newPath). */
    public function restore(string $disk, string $id, ?string $newPath = null): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        $this->assertSafeTrashId($id);
        if (!($this->meta instanceof StorageMetadataHandler)) {
            throw new ApiException('Trash is not available for this storage', 400, 'trash_unavailable');
        }

        $entry = $this->meta->getTrash($disk, $id);
        if ($entry === null) {
            throw new ApiException('Trash item not found', 404, 'not_found');
        }
        $this->assertTrashScope($entry);

        $target = ($newPath !== null && $newPath !== '')
            ? $this->scopedPath($newPath)
            : (string) ($entry['original_key'] ?? '');
        $this->assertNotSystem($target);

        $fs = $this->disks->disk($disk);
        $this->assertTargetAvailable($fs, $target);

        $trashDir = '_fluxfiles/trash/' . $id;

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
        $this->meta->trackParents($disk, $target);

        try { $fs->deleteDirectory($trashDir); } catch (\Throwable $e) { /* best effort */ }
        $this->meta->removeTrash($disk, $id);

        return ['restored' => true, 'key' => $this->outKey($target)];
    }

    /** Restore a trashed directory subtree to $target. */
    private function restoreDirectory(string $disk, string $id, array $entry, string $target, string $trashDir, $fs): array
    {
        $target = rtrim($target, '/');
        try {
            $fs->createDirectory($target); // handles empty folders too
        } catch (\Throwable $e) { /* best effort */ }

        foreach (($entry['files'] ?? []) as $f) {
            $rel = $this->safeRel((string) ($f['rel'] ?? '')); // strip any traversal
            if ($rel === '') {
                continue;
            }
            $src = $trashDir . '/payload/' . $rel;
            $dst = $target . '/' . $rel;
            try {
                if ($fs->fileExists($src)) {
                    $fs->move($src, $dst);
                }
            } catch (\Throwable $e) { /* best effort */ }
            if (!empty($f['meta'])) {
                $this->meta->save($disk, $dst, $f['meta']);
            }
            // Rebuild the folder tree in the dirs index for real files.
            if (strpos($rel, '_variants/') === false) {
                $this->meta->trackParents($disk, $dst);
            }
        }
        $this->meta->trackDir($disk, $target);

        try { $fs->deleteDirectory($trashDir); } catch (\Throwable $e) { /* best effort */ }
        $this->meta->removeTrash($disk, $id);

        return ['restored' => true, 'key' => $this->outKey($target)];
    }

    /** List trash entries the caller may see (scoped to prefix + owner). */
    public function listTrash(string $disk): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        if (!($this->meta instanceof StorageMetadataHandler)) {
            return [];
        }
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
        if (!($this->meta instanceof StorageMetadataHandler)) {
            throw new ApiException('Trash is not available for this storage', 400, 'trash_unavailable');
        }
        $entry = $this->meta->getTrash($disk, $id);
        if ($entry === null) {
            throw new ApiException('Trash item not found', 404, 'not_found');
        }
        $this->assertTrashScope($entry);

        $fs = $this->disks->disk($disk);
        try { $fs->deleteDirectory('_fluxfiles/trash/' . $id); } catch (\Throwable $e) { /* best effort */ }
        $this->meta->removeTrash($disk, $id);

        return ['purged' => true];
    }

    /** Permanently delete every trash item the caller may see. */
    public function emptyTrash(string $disk): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');
        if (!($this->meta instanceof StorageMetadataHandler)) {
            return ['purged' => 0];
        }
        $fs = $this->disks->disk($disk);
        $n = 0;
        foreach ($this->meta->allTrash($disk) as $id => $e) {
            if (!$this->trashVisible($e)) {
                continue;
            }
            try { $fs->deleteDirectory('_fluxfiles/trash/' . $id); } catch (\Throwable $ex) { /* best effort */ }
            $this->meta->removeTrash($disk, $id);
            $n++;
        }
        return ['purged' => $n];
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
        $this->assertNotSystem($scoped);
        $this->assertOwner($disk, $scoped);

        $newName = trim($newName);
        if ($newName === '') {
            throw new ApiException('New name cannot be empty', 400, 'name_empty');
        }
        if (preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $newName)) {
            throw new ApiException('Name contains invalid characters', 400, 'name_invalid');
        }

        $this->assertSafeFilename($newName);

        $fs = $this->disks->disk($disk);

        $dir = dirname($scoped);
        $newPath = ($dir !== '.' && $dir !== '') ? $dir . '/' . $newName : $newName;

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
            // For directories: move all contents to new path
            $scopedPrefix = rtrim($scoped, '/') . '/';
            foreach ($fs->listContents($scoped, true) as $item) {
                if ($item->isFile()) {
                    $relative = substr($item->path(), strlen($scopedPrefix));
                    $fs->move($item->path(), $newPath . '/' . $relative);
                }
            }
            // Move _variants/ directory
            $oldVariantsDir = $scopedPrefix . '_variants';
            $newVariantsDir = rtrim($newPath, '/') . '/_variants';
            try {
                if ($fs->directoryExists($oldVariantsDir)) {
                    foreach ($fs->listContents($oldVariantsDir, true) as $vItem) {
                        if ($vItem->isFile()) {
                            $vRelative = substr($vItem->path(), strlen(rtrim($oldVariantsDir, '/') . '/'));
                            $fs->move($vItem->path(), $newVariantsDir . '/' . $vRelative);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('FluxFiles: Variant directory rename failed — ' . $e->getMessage());
            }
            // Move metadata for children
            $this->meta->renameChildren($disk, $scoped, $newPath);
            if ($this->meta instanceof StorageMetadataHandler) {
                $this->meta->renameDirPrefix($disk, $scoped, $newPath);
            }
            try {
                $fs->deleteDirectory($scoped);
            } catch (\Throwable $e) {
                // May already be empty/gone
            }
        } else {
            $fs->move($scoped, $newPath);
            // Move metadata
            $this->moveMetadata($disk, $scoped, $disk, $newPath);
            // Move image variants
            $this->moveVariants($disk, $scoped, $disk, $newPath);

            if ($this->meta instanceof StorageMetadataHandler) {
                $this->meta->trackParents($disk, $newPath);
            }
        }

        return [
            'key'  => $this->outKey($newPath),
            'name' => $newName,
            'url'  => $isDir ? null : $this->fileUrl($disk, $newPath),
        ];
    }

    public function move(string $disk, string $from, string $to): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scopedFrom = $this->scopedPath($from);
        $this->assertNotSystem($scopedFrom);
        $this->assertOwner($disk, $scopedFrom);
        $scopedTo   = $this->scopedPath($to);
        $this->assertNotSystem($scopedTo);
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
        } else {
            $this->assertRelocationExt($scopedFrom, $scopedTo);
        }

        $fs->move($scopedFrom, $scopedTo);

        // Keep metadata + folder index best-effort in sync
        if ($isDir) {
            $this->meta->renameChildren($disk, $scopedFrom, $scopedTo);
            if ($this->meta instanceof StorageMetadataHandler) {
                $this->meta->renameDirPrefix($disk, $scopedFrom, $scopedTo);
            }
        } else {
            $this->moveMetadata($disk, $scopedFrom, $disk, $scopedTo);
            $this->moveVariants($disk, $scopedFrom, $disk, $scopedTo);
            if ($this->meta instanceof StorageMetadataHandler) {
                $this->meta->trackParents($disk, $scopedTo);
            }
        }

        return ['key' => $this->outKey($scopedTo)];
    }

    public function copy(string $disk, string $from, string $to): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');
        $this->assertPerm('write');

        $scopedFrom = $this->scopedPath($from);
        $this->assertNotSystem($scopedFrom);
        $this->assertOwner($disk, $scopedFrom);
        $scopedTo   = $this->scopedPath($to);
        $this->assertNotSystem($scopedTo);
        $fs = $this->disks->disk($disk);
        $this->assertTargetAvailable($fs, $scopedTo);
        $this->assertRelocationExt($scopedFrom, $scopedTo);
        $fs->copy($scopedFrom, $scopedTo);

        $this->copyMetadata($disk, $scopedFrom, $disk, $scopedTo);
        $this->copyVariants($disk, $scopedFrom, $disk, $scopedTo);
        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->trackParents($disk, $scopedTo);
        }

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
        $this->assertNotSystem($scopedSrc);
        $this->assertOwner($srcDisk, $scopedSrc);
        $scopedDst = $this->scopedPath($dstPath);
        $this->assertNotSystem($scopedDst);
        $this->assertRelocationExt($scopedSrc, $scopedDst);

        $srcFs = $this->disks->disk($srcDisk);
        $dstFs = $this->disks->disk($dstDisk);

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
        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->trackParents($dstDisk, $scopedDst);
        }

        return [
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->fileUrl($dstDisk, $scopedDst),
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
        $this->assertNotSystem($scopedSrc);
        $this->assertOwner($srcDisk, $scopedSrc);
        $scopedDst = $this->scopedPath($dstPath);
        $this->assertNotSystem($scopedDst);
        $this->assertRelocationExt($scopedSrc, $scopedDst);

        $srcFs = $this->disks->disk($srcDisk);
        $dstFs = $this->disks->disk($dstDisk);

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

        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->trackParents($dstDisk, $scopedDst);
        }

        return [
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->fileUrl($dstDisk, $scopedDst),
            'src_disk' => $srcDisk,
            'dst_disk' => $dstDisk,
        ];
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
        $this->copyMetadata($srcDisk, $srcKey, $dstDisk, $dstKey);
        $this->meta->delete($srcDisk, $srcKey);
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
            $variant = $this->variantKey($key, $size);
            try {
                if ($fs->fileExists($variant)) {
                    $fs->delete($variant);
                }
            } catch (\Throwable $e) {
                // Best effort cleanup
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
        $basename = pathinfo($key, PATHINFO_FILENAME);
        $variantsDir = ($dir !== '.' && $dir !== '') ? $dir . '/_variants' : '_variants';
        return $variantsDir . '/' . $basename . '_' . $size . '.webp';
    }

    public function mkdir(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $fs = $this->disks->disk($disk);

        // Refuse to "create" a folder that already exists (as a folder OR a file
        // with that name). Flysystem's createDirectory is idempotent and would
        // silently 200, so without this an existing folder looks freshly created —
        // the OS (Finder/Explorer) reports a conflict here, and so should we.
        if ($fs->directoryExists($scoped) || $fs->fileExists($scoped)) {
            throw new ApiException('A folder with this name already exists', 409, 'folder_exists', ['name' => basename($scoped)]);
        }

        $fs->createDirectory($scoped);

        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->trackDir($disk, $scoped);
            $this->meta->trackParents($disk, $scoped);
        }

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
        $this->assertNotSystem($scopedSrc);
        $this->assertOwner($disk, $scopedSrc);
        $fs = $this->disks->disk($disk);

        $imageData = $fs->read($scopedSrc);
        $ext = strtolower(pathinfo($scopedSrc, PATHINFO_EXTENSION));
        $format = in_array($ext, ['jpg', 'jpeg']) ? 'jpg' : ($ext === 'webp' ? 'webp' : 'png');

        $result = $this->imageOptimizer->crop($imageData, $x, $y, $width, $height, $format);
        $scopedDst = $savePath ? $this->scopedPath($savePath) : $scopedSrc;
        $this->assertNotSystem($scopedDst);
        if ($savePath !== null) {
            $this->validateUploadName(basename($scopedDst), 0);
            // "Save as" to a different path must not silently clobber an existing file
            // (in-place crop, where dst == src, is still allowed to overwrite).
            if ($scopedDst !== $scopedSrc) {
                $this->assertTargetAvailable($fs, $scopedDst);
            }
        }
        $fs->write($scopedDst, $result['data']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'ffcrop_');
        file_put_contents($tmpFile, $result['data']);

        $variants = null;
        try {
            $variantResult = $this->imageOptimizer->process($fs, $scopedDst, $tmpFile);
            $variantUrls = [];
            foreach ($variantResult as $sizeName => $info) {
                $variantUrls[$sizeName] = [
                    'url'    => $this->fileUrl($disk, $info['key']),
                    'key'    => $info['key'],
                    'width'  => $info['width'],
                    'height' => $info['height'],
                ];
            }
            $variants = $variantUrls;
        } catch (\Throwable $e) {
            error_log('FluxFiles: Variant regeneration after crop failed — ' . $e->getMessage());
        } finally {
            @unlink($tmpFile);
        }

        return $this->unscopeItems([[
            'key'      => $this->outKey($scopedDst),
            'url'      => $this->fileUrl($disk, $scopedDst),
            'width'    => $result['width'],
            'height'   => $result['height'],
            'variants' => $variants,
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
        $fs = $this->disks->disk($disk);

        $name = basename($scoped);
        if (!$this->imageOptimizer->isImage($name)) {
            throw new ApiException('AI tagging is only available for images', 400, 'ai_images_only');
        }

        $imageData = $fs->read($scoped);
        $mime = $fs->mimeType($scoped);

        $aiResult = $this->aiTagger->analyze($imageData, $mime);
        $existing = $this->meta->get($disk, $scoped);

        $data = [
            'title'    => (!empty($existing['title'])) ? $existing['title'] : ($aiResult['title'] ?? null),
            'alt_text' => (!empty($existing['alt_text'])) ? $existing['alt_text'] : ($aiResult['alt_text'] ?? null),
            'caption'  => (!empty($existing['caption'])) ? $existing['caption'] : ($aiResult['caption'] ?? null),
            'tags'     => implode(', ', $aiResult['tags'] ?? []),
        ];

        $this->meta->save($disk, $scoped, $data);

        return [
            'tags'     => $aiResult['tags'] ?? [],
            'title'    => $data['title'],
            'alt_text' => $data['alt_text'],
            'caption'  => $data['caption'],
        ];
    }

    private const MAX_PRESIGN_TTL = 86400; // 24 hours

    /** Max bytes the code editor will read/write (config files are small). */
    private const MAX_EDIT_BYTES = 5 * 1024 * 1024;

    /** Skip on-upload auto-optimize above this size (huge images: too slow inline). */
    private const AUTO_OPTIMIZE_MAX = 40 * 1024 * 1024;

    /** Hard cap on the rename probe so a pathological folder can't loop forever. */
    private const MAX_COLLISION_TRIES = 10000;

    /**
     * Find a non-colliding filename in $dirUser by appending `-1`, `-2`, … to the
     * base name while preserving the extension (extension immutability), e.g.
     * `photo.jpg` → `photo-1.jpg`. $dirUser/$name are UNSCOPED (user-relative);
     * returns the chosen unscoped filename. Falls back to a short random suffix if
     * the numeric probe is exhausted.
     */
    private function uniqueName($fs, string $dirUser, string $name): string
    {
        $dot  = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $ext  = $dot === false ? '' : substr($name, $dot); // includes the leading dot
        $prefix = $dirUser === '' ? '' : $dirUser . '/';

        for ($i = 1; $i <= self::MAX_COLLISION_TRIES; $i++) {
            $candidate = $base . '-' . $i . $ext;
            if (!$fs->fileExists($this->scopedPath($prefix . $candidate))) {
                return $candidate;
            }
        }
        // Exhausted (extremely unlikely) — random suffix is effectively collision-free.
        return $base . '-' . bin2hex(random_bytes(4)) . $ext;
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
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);

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
        $this->assertNotSystem($scoped);
        $this->assertExt(strtolower(pathinfo($scoped, PATHINFO_EXTENSION)));

        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new ApiException('Content is too large', 413, 'edit_too_large',
                ['max_mb' => round(self::MAX_EDIT_BYTES / 1048576)]);
        }
        $fs = $this->disks->disk($disk);
        if (!$fs->fileExists($scoped)) {
            throw new ApiException('File not found (the editor edits existing files only)', 404, 'not_found');
        }
        $fs->write($scoped, $content);
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
        $this->assertNotSystem($scoped);

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

        if (!in_array($method, ['GET', 'PUT'], true)) {
            throw new ApiException('Invalid presign method: must be GET or PUT', 400);
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

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') !== 's3') {
            throw new ApiException('Presigned URLs are only available for S3/R2 disks', 400);
        }

        if ($method === 'PUT') {
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
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Some adapters can throw on existence checks; presign should still work for new objects.
            }
        }

        $client = $this->disks->s3Client($disk);
        $bucket = $config['bucket'] ?? '';

        $cmd = $client->getCommand(
            $method === 'GET' ? 'GetObject' : 'PutObject',
            [
                'Bucket' => $bucket,
                'Key'    => $scoped,
            ]
        );

        $request = $client->createPresignedRequest($cmd, "+{$ttl} seconds");
        $url = (string) $request->getUri();

        return [
            'url'        => $url,
            'expires_at' => time() + $ttl,
        ];
    }

    public function fileMeta(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');

        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
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
    private function getFileVariants(string $disk, string $key): ?array
    {
        if (!$this->imageOptimizer->isImage($key)) {
            return null;
        }

        $fs = $this->disks->disk($disk);
        $variants = [];

        foreach (['thumb', 'medium', 'large'] as $size) {
            $vk = $this->variantKey($key, $size);
            if ($fs->fileExists($vk)) {
                $variants[$size] = [
                    'url' => $this->fileUrl($disk, $vk),
                    'key' => $vk,
                ];
            }
        }

        return !empty($variants) ? $variants : null;
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
        $token = StreamToken::mint($disk, $path, $this->claims->userId, $ttl, $this->streamSecret);
        return '/api/fm/stream?token=' . rawurlencode($token);
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
            $this->claims->watermark
        );
        return '/api/fm/img?token=' . rawurlencode($token);
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
        return str_starts_with($key, '_fluxfiles/') || str_starts_with($key, '_variants/')
            || str_contains($key, '/_fluxfiles/') || str_contains($key, '/_variants/')
            || substr($key, -10) === '.meta.json';
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

            if ($fs->directoryExists($scoped)) {
                $this->assertOwnsTree($disk, $scoped);
                // Keep the selected folder itself as the top entry in the archive.
                $parent = strpos($scoped, '/') !== false ? substr($scoped, 0, strrpos($scoped, '/') + 1) : '';
                foreach ($fs->listContents($scoped, true) as $item) {
                    if (!$item->isFile()) {
                        continue;
                    }
                    $key = $item->path();
                    if ($this->isSystemKey($key)) {
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
    public function streamZip(string $disk, array $paths, ?string $name = null, bool $sendHeaders = true): void
    {
        $manifest = $this->zipManifest($disk, $paths);
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
     * a single bad entry aborts the whole extract before any write; pass 2 writes.
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
        $this->assertNotSystem($destScoped);

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

            // ── Pass 1: validate every entry, write nothing ──
            $plan = [];   // list of ['orig' => entryName, 'rel' => safeRelPath]
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
                if ($this->isSystemKey($rel)) {
                    throw new ApiException('Archive targets a system path', 403, 'system_path', ['entry' => $name]);
                }
                // Same policy as upload: block dangerous extensions + honour allowed_ext.
                $this->assertSafeFilename(basename($rel));
                $this->assertExt(strtolower(pathinfo($rel, PATHINFO_EXTENSION)));

                $total += (int) $stat['size'];
                $plan[] = ['orig' => $name, 'rel' => $rel];
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

            // ── Pass 2: write everything ──
            $written = 0;
            $prefix = $destScoped !== '' ? rtrim($destScoped, '/') . '/' : '';
            foreach ($plan as $e) {
                $stream = $za->getStream($e['orig']);
                if (!is_resource($stream)) {
                    continue;
                }
                $fs->writeStream($prefix . $e['rel'], $stream);
                fclose($stream);
                $written++;
            }
            $za->close();
        } finally {
            @unlink($tmp);
        }

        return ['extracted' => $written, 'dest' => $destUser, 'bytes' => $total];
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
                'Bucket' => $config['bucket'] ?? '',
                'Key'    => $path,
            ]);
            $request = $client->createPresignedRequest($cmd, "+{$ttl} seconds");
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            error_log('FluxFiles: presign GET URL failed — ' . $e->getMessage());
            return null;
        }
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
                if (str_starts_with($key, '_fluxfiles/') || str_starts_with($key, '_variants/')
                    || str_contains($key, '/_fluxfiles/') || str_contains($key, '/_variants/')
                    || substr($key, -10) === '.meta.json'
                ) {
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
     * Block access to internal system paths (_fluxfiles/, _variants/).
     */
    private function assertNotSystem(string $scopedPath): void
    {
        $normalized = trim($scopedPath, '/') . '/';
        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (strpos($normalized, $prefix) === 0 || strpos($normalized, '/' . $prefix) !== false) {
                throw new ApiException('Access denied: system path', 403, 'system_path');
            }
        }
        // Also block exact match (e.g. "_fluxfiles" without trailing slash)
        $base = basename($scopedPath);
        if ($base === '_fluxfiles' || $base === '_variants') {
            throw new ApiException('Access denied: system path', 403, 'system_path');
        }
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

    /** Internal directories that must never be exposed or modified by users. */
    private const SYSTEM_PREFIXES = ['_fluxfiles/', '_variants/'];

    /** Extensions that are always blocked regardless of allowedExt. */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'bat', 'cmd', 'com',
        'exe', 'dll', 'msi', 'scr', 'vbs', 'wsf', 'jsp', 'jspx',
        'asp', 'aspx', 'htaccess', 'htpasswd',
    ];

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
        $parts = explode('.', strtolower($name));
        array_shift($parts); // remove the base name
        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new ApiException("Dangerous extension detected in filename: {$part}", 403, 'ext_dangerous');
            }
        }
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
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->assertExt($ext);
        $this->assertSafeFilename($name);
        if ($sizeBytes > 0) {
            $this->assertUploadSize($sizeBytes / (1024 * 1024));
        }
    }

    public function validateUserPath(string $path): string
    {
        $scoped = $this->scopedPath($path);
        $this->assertNotSystem($scoped);
        return $scoped;
    }

    public function validateScopedPath(string $scopedPath): string
    {
        $this->assertNotSystem($scopedPath);
        return $scopedPath;
    }

    public function assertCanModifyScopedPath(string $disk, string $scopedPath): void
    {
        $this->assertNotSystem($scopedPath);
        $this->assertOwner($disk, $scopedPath);
    }
}

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

    public function __construct(
        DiskManager $disks,
        Claims $claims,
        MetadataRepositoryInterface $meta
    ) {
        $this->disks = $disks;
        $this->claims = $claims;
        $this->meta = $meta;
        $this->imageOptimizer = new ImageOptimizer();
    }

    public function setQuotaManager(QuotaManager $qm): void
    {
        $this->quotaManager = $qm;
    }

    public function setAiTagger(AiTagger $ai): void
    {
        $this->aiTagger = $ai;
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
                $entry['url']      = $this->fileUrl($disk, $item->path());
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
            // Skip past cursor (exclusive)
            if ($cursor !== '') {
                $start = 0;
                foreach ($all as $i => $it) {
                    if ($it['key'] === $cursor) {
                        $start = $i + 1;
                        break;
                    }
                }
                $all = array_slice($all, $start);
            }

            $page = array_slice($all, 0, $limit);
            $nextCursor = (count($page) === $limit && $limit < count($all))
                ? $page[count($page) - 1]['key']
                : null;

            $this->attachMetadata($disk, $page);

            return [
                'items'       => $page,
                'next_cursor' => $nextCursor,
                'total'       => $total,
            ];
        }

        // Legacy mode: return flat array with all items + metadata.
        $this->attachMetadata($disk, $all);
        return $all;
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
        foreach ($items as &$item) {
            if (($item['type'] ?? '') === 'file') {
                $item['meta'] = $metaMap[$item['key']] ?? null;
                $item['variants'] = $this->getFileVariants($disk, $item['key']);
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
                    return [
                        'key'       => $existingKey,
                        'url'       => $this->fileUrl($disk, $existingKey),
                        'name'      => basename($existingKey),
                        'duplicate' => true,
                        'message'   => 'File already exists. Use force_upload to override.',
                        'variants'  => $this->getFileVariants($disk, $existingKey),
                    ];
                }
                // Stale / invalid hash entry — purge so it doesn't keep blocking uploads.
                $this->meta->delete($disk, $existingKey);
            }
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

        // Store ownership metadata
        $this->meta->save($disk, $scoped, [
            'uploaded_by' => $this->claims->userId,
        ]);

        $result = [
            'key'      => $scoped,
            'url'      => $this->fileUrl($disk, $scoped),
            'size'     => $file['size'],
            'name'     => $name,
            'variants' => null,
        ];

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

        // Auto-tag with AI if enabled
        if ($this->aiTagger !== null
            && $this->imageOptimizer->isImage($name)
            && ($_ENV['FLUXFILES_AI_AUTO_TAG'] ?? '') === 'true'
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

        return $result;
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
            'key'  => $newPath,
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

        return ['key' => $scopedTo];
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
        $fs->copy($scopedFrom, $scopedTo);

        $this->copyMetadata($disk, $scopedFrom, $disk, $scopedTo);
        $this->copyVariants($disk, $scopedFrom, $disk, $scopedTo);
        if ($this->meta instanceof StorageMetadataHandler) {
            $this->meta->trackParents($disk, $scopedTo);
        }

        return ['key' => $scopedTo];
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
            'key'      => $scopedDst,
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
            'key'      => $scopedDst,
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

        return [
            'key'      => $scopedDst,
            'url'      => $this->fileUrl($disk, $scopedDst),
            'width'    => $result['width'],
            'height'   => $result['height'],
            'variants' => $variants,
        ];
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

    public function presign(string $disk, string $path, string $method, int $ttl, int $sizeBytes = 0): array
    {
        $this->assertDisk($disk);

        if (!in_array($method, ['GET', 'PUT'], true)) {
            throw new ApiException('Invalid presign method: must be GET or PUT', 400);
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
            // "user_1/posts") and the UI navigates with those keys, so a path
            // already inside the prefix must NOT be prefixed again (which produced
            // "user_1/user_1/posts" → empty folder). `..`/`.` were stripped above
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

    private function fileUrl(string $disk, string $path): string
    {
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') === 'local') {
            $baseUrl = rtrim($config['url'] ?? '/storage/uploads', '/');
            return $baseUrl . '/' . $path;
        }

        // S3/R2. Private disks (the default) are served through short-lived presigned
        // GET URLs so objects work even on private buckets. Public disks return a direct
        // URL — using public_url (CDN / R2 custom domain) when set, otherwise the
        // bucket/endpoint URL.
        $visibility = $config['visibility'] ?? 'private';
        if ($visibility !== 'public') {
            $signed = $this->presignGetUrl($disk, $path, $config);
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
    private function presignGetUrl(string $disk, string $path, array $config): ?string
    {
        $ttl = (int) ($config['url_ttl'] ?? 3600);
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

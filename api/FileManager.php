<?php

declare(strict_types=1);

namespace FluxFiles;

use Aws\S3\S3Client;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;

class FileManager
{
    private ImageOptimizer $imageOptimizer;
    private ?QuotaManager $quotaManager = null;
    private ?AiTagger $aiTagger = null;

    public function __construct(
        private DiskManager $disks,
        private Claims $claims,
        private MetadataRepository $meta,
    ) {
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

    public function list(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');

        $scoped = $this->scopedPath($path);
        $fs = $this->disks->disk($disk);

        $items = [];
        $fileKeys = [];

        /** @var StorageAttributes $item */
        foreach ($fs->listContents($scoped, false) as $item) {
            $name = basename($item->path());
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
                $fileKeys[] = $item->path();
            }

            $items[] = $entry;
        }

        // Merge metadata for files and filter out trashed
        if (!empty($fileKeys)) {
            $metaMap = $this->meta->getBulk($disk, $fileKeys);
            $filtered = [];
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $item['meta'] = $metaMap[$item['key']] ?? null;
                    // Skip trashed files
                    if ($this->meta->isTrashed($disk, $item['key'])) {
                        continue;
                    }
                }
                $filtered[] = $item;
            }
            $items = $filtered;
        }

        return $items;
    }

    public function upload(string $disk, string $path, array $file, bool $forceUpload = false): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $name = $file['name'] ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->assertExt($ext);

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

        // Duplicate detection
        $hash = md5_file($file['tmp_name']);
        if ($hash !== false && !$forceUpload) {
            $existing = $this->meta->findByHash($disk, $hash);
            if ($existing) {
                return [
                    'key'       => $existing['file_key'],
                    'url'       => $this->fileUrl($disk, $existing['file_key']),
                    'name'      => basename($existing['file_key']),
                    'duplicate' => true,
                    'message'   => 'File already exists. Use force_upload to override.',
                    'variants'  => null,
                ];
            }
        }

        $scoped = $this->scopedPath(rtrim($path, '/') . '/' . $name);
        $fs = $this->disks->disk($disk);

        $stream = fopen($file['tmp_name'], 'r');
        if ($stream === false) {
            throw new ApiException('Failed to read uploaded file', 500);
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
                // Image optimization failure should not block upload
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

        // Soft delete: move to trash instead of permanent delete
        $this->meta->trash($disk, $scoped);

        return ['trashed' => true];
    }

    public function restore(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $this->meta->restore($disk, $scoped);

        return ['restored' => true];
    }

    public function purge(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('delete');

        $scoped = $this->scopedPath($path);
        $fs = $this->disks->disk($disk);

        // Permanently delete file from storage
        try {
            $fs->delete($scoped);
        } catch (\Throwable $e) {
            // File may already be gone from storage
        }

        // Remove metadata
        $this->meta->purge($disk, $scoped);

        return ['purged' => true];
    }

    public function listTrash(string $disk): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('read');

        return $this->meta->getTrashed($disk);
    }

    public function move(string $disk, string $from, string $to): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scopedFrom = $this->scopedPath($from);
        $scopedTo   = $this->scopedPath($to);
        $fs = $this->disks->disk($disk);
        $fs->move($scopedFrom, $scopedTo);

        return ['key' => $scopedTo];
    }

    public function copy(string $disk, string $from, string $to): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scopedFrom = $this->scopedPath($from);
        $scopedTo   = $this->scopedPath($to);
        $fs = $this->disks->disk($disk);
        $fs->copy($scopedFrom, $scopedTo);

        return ['key' => $scopedTo];
    }

    /**
     * Copy a file from one disk to another using streams.
     */
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
        $scopedDst = $this->scopedPath($dstPath);

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

        // Copy metadata
        $this->copyMetadata($srcDisk, $scopedSrc, $dstDisk, $scopedDst);

        // Copy image variants if they exist
        $this->copyVariants($srcDisk, $scopedSrc, $dstDisk, $scopedDst);

        return [
            'key'      => $scopedDst,
            'url'      => $this->fileUrl($dstDisk, $scopedDst),
            'src_disk' => $srcDisk,
            'dst_disk' => $dstDisk,
        ];
    }

    /**
     * Move a file from one disk to another (copy + delete source).
     */
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
        $scopedDst = $this->scopedPath($dstPath);

        $srcFs = $this->disks->disk($srcDisk);
        $dstFs = $this->disks->disk($dstDisk);

        // Stream the file across disks
        $stream = $srcFs->readStream($scopedSrc);
        try {
            $dstFs->writeStream($scopedDst, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Move metadata
        $this->moveMetadata($srcDisk, $scopedSrc, $dstDisk, $scopedDst);

        // Move image variants
        $this->moveVariants($srcDisk, $scopedSrc, $dstDisk, $scopedDst);

        // Delete source file
        $srcFs->delete($scopedSrc);

        return [
            'key'      => $scopedDst,
            'url'      => $this->fileUrl($dstDisk, $scopedDst),
            'src_disk' => $srcDisk,
            'dst_disk' => $dstDisk,
        ];
    }

    /**
     * Copy metadata from one disk/key to another.
     */
    private function copyMetadata(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $existing = $this->meta->get($srcDisk, $srcKey);
        if ($existing) {
            $this->meta->save($dstDisk, $dstKey, $existing);
        }
    }

    /**
     * Move metadata from one disk/key to another (copy + delete source).
     */
    private function moveMetadata(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $this->copyMetadata($srcDisk, $srcKey, $dstDisk, $dstKey);
        $this->meta->delete($srcDisk, $srcKey);
    }

    /**
     * Copy image variants (thumb/medium/large) across disks.
     */
    private function copyVariants(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $this->transferVariants($srcDisk, $srcKey, $dstDisk, $dstKey, false);
    }

    /**
     * Move image variants (thumb/medium/large) across disks.
     */
    private function moveVariants(string $srcDisk, string $srcKey, string $dstDisk, string $dstKey): void
    {
        $this->transferVariants($srcDisk, $srcKey, $dstDisk, $dstKey, true);
    }

    /**
     * Transfer image variants across disks (copy or move).
     */
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
                // Variant transfer failure should not block the main operation
                error_log("FluxFiles: Variant transfer failed ({$size}) — " . $e->getMessage());
            }
        }
    }

    /**
     * Build the variant key for a given file key and size name.
     * Matches ImageOptimizer naming: dir/_variants/basename_size.webp
     */
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
        $fs = $this->disks->disk($disk);
        $fs->createDirectory($scoped);

        return ['created' => true];
    }

    /**
     * Crop an image in-place or save as a new file.
     *
     * @param string  $disk     Storage disk
     * @param string  $path     File path
     * @param int     $x        Crop X offset
     * @param int     $y        Crop Y offset
     * @param int     $width    Crop width
     * @param int     $height   Crop height
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
        $fs = $this->disks->disk($disk);

        // Read the original image
        $imageData = $fs->read($scopedSrc);
        $ext = strtolower(pathinfo($scopedSrc, PATHINFO_EXTENSION));
        $format = in_array($ext, ['jpg', 'jpeg']) ? 'jpg' : ($ext === 'webp' ? 'webp' : 'png');

        // Crop
        $result = $this->imageOptimizer->crop($imageData, $x, $y, $width, $height, $format);

        // Determine destination
        $scopedDst = $savePath ? $this->scopedPath($savePath) : $scopedSrc;

        // Write cropped image
        $fs->write($scopedDst, $result['data']);

        // Regenerate image variants for the cropped image
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

    /**
     * Run AI analysis on an image and save tags/metadata.
     */
    public function aiTag(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        if ($this->aiTagger === null) {
            throw new ApiException('AI tagging is not configured', 400);
        }

        $scoped = $this->scopedPath($path);
        $fs = $this->disks->disk($disk);

        $name = basename($scoped);
        if (!$this->imageOptimizer->isImage($name)) {
            throw new ApiException('AI tagging is only available for images', 400);
        }

        $imageData = $fs->read($scoped);
        $mime = $fs->mimeType($scoped);

        $aiResult = $this->aiTagger->analyze($imageData, $mime);

        // Merge with existing metadata — AI fills empty fields only
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

    public function presign(string $disk, string $path, string $method, int $ttl): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') !== 's3') {
            throw new ApiException('Presigned URLs are only available for S3/R2 disks', 400);
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
        $fs = $this->disks->disk($disk);

        return [
            'size'     => $fs->fileSize($scoped),
            'mime'     => $fs->mimeType($scoped),
            'modified' => $fs->lastModified($scoped),
        ];
    }

    private function scopedPath(string $path): string
    {
        // Strip null bytes and path traversal
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
            return $prefix . '/' . $relative;
        }

        return $relative;
    }

    private function fileUrl(string $disk, string $path): string
    {
        $config = $this->disks->config($disk);

        if (($config['driver'] ?? '') === 'local') {
            $baseUrl = rtrim($config['url'] ?? '/storage/uploads', '/');
            // Strip the prefix from the URL path to make it relative
            return $baseUrl . '/' . $path;
        }

        // For S3/R2, construct the URL
        $bucket = $config['bucket'] ?? '';
        if (!empty($config['endpoint'])) {
            // R2 or custom endpoint
            return rtrim($config['endpoint'], '/') . '/' . $bucket . '/' . $path;
        }
        $region = $config['region'] ?? 'us-east-1';
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$path}";
    }

    private function assertDisk(string $disk): void
    {
        if (!$this->claims->hasDisk($disk)) {
            throw new ApiException("Access denied to disk: {$disk}", 403);
        }
    }

    private function assertPerm(string $perm): void
    {
        if (!$this->claims->hasPerm($perm)) {
            throw new ApiException("Permission denied: {$perm}", 403);
        }
    }

    private function assertExt(string $ext): void
    {
        if ($this->claims->allowedExt === null) {
            return;
        }
        if (!in_array($ext, $this->claims->allowedExt, true)) {
            throw new ApiException("File extension not allowed: {$ext}", 403);
        }
    }

    private function assertUploadSize(float $sizeMb): void
    {
        if ($sizeMb > $this->claims->maxUploadMb) {
            throw new ApiException(
                "File too large: {$sizeMb}MB exceeds limit of {$this->claims->maxUploadMb}MB",
                413
            );
        }
    }
}

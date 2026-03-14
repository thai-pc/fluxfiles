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

    public function mkdir(string $disk, string $path): array
    {
        $this->assertDisk($disk);
        $this->assertPerm('write');

        $scoped = $this->scopedPath($path);
        $fs = $this->disks->disk($disk);
        $fs->createDirectory($scoped);

        return ['created' => true];
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

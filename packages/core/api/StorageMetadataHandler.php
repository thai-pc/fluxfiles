<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Metadata lưu trực tiếp trong storage của user (S3/R2/Local) — không dùng SQLite.
 *
 * - S3/R2: Object Metadata (x-amz-meta-*) + index file _fluxfiles/index.json
 * - Local: Sidecar .meta.json + index file _fluxfiles/index.json
 * - Audit: _fluxfiles/audit.jsonl
 */
class StorageMetadataHandler implements MetadataRepositoryInterface
{
    private const INDEX_KEY = '_fluxfiles/index.json';
    private const AUDIT_KEY = '_fluxfiles/audit.jsonl';
    private const MAX_AUDIT_BYTES = 5 * 1024 * 1024; // 5MB rotation threshold
    private const AUDIT_KEEP_LINES = 5000; // Keep last N entries after rotation

    private DiskManager $diskManager;

    /** @var array<string, resource> Active file locks keyed by disk name */
    private array $indexLocks = [];

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
        if ($this->isS3Compatible($disk)) {
            $this->saveToS3($disk, $key, $data);
        } else {
            $this->saveToLocal($disk, $key, $data);
        }
        $this->updateIndex($disk, $key, $data);
    }

    public function delete(string $disk, string $key): void
    {
        if ($this->isS3Compatible($disk)) {
            // Metadata sẽ mất khi file bị xóa; xóa khỏi index
        } else {
            $metaPath = $this->sidecarPath($key);
            $fs = $this->diskManager->disk($disk);
            if ($fs->fileExists($metaPath)) {
                $fs->delete($metaPath);
            }
        }
        $this->removeFromIndex($disk, $key);
    }

    public function deleteChildren(string $disk, string $prefix): int
    {
        $index = $this->loadIndex($disk);
        $count = 0;
        foreach (array_keys($index) as $k) {
            if ($k === $prefix || strpos($k, $prefix . '/') === 0) {
                $this->delete($disk, $k);
                $count++;
            }
        }
        return $count;
    }

    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int
    {
        $this->acquireIndexLock($disk);
        try {
            $index = $this->loadIndex($disk);
            $count = 0;
            $updated = [];
            foreach ($index as $k => $meta) {
                if ($k === $oldPrefix || strpos($k, $oldPrefix . '/') === 0) {
                    $newKey = $newPrefix . substr($k, strlen($oldPrefix));
                    $updated[$newKey] = $meta;
                    unset($index[$k]);
                    // Move sidecar file for local disks
                    if (!$this->isS3Compatible($disk)) {
                        $fs = $this->diskManager->disk($disk);
                        $oldSidecar = $this->sidecarPath($k);
                        $newSidecar = $this->sidecarPath($newKey);
                        try {
                            if ($fs->fileExists($oldSidecar)) {
                                $fs->move($oldSidecar, $newSidecar);
                            }
                        } catch (\Throwable $e) {
                            // Silent
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

    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = ''): array
    {
        $index = $this->loadIndex($disk);
        $prefix = trim($pathPrefix, '/');
        $q = mb_strtolower($query);
        $results = [];

        foreach ($index as $fileKey => $meta) {
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
            if (strpos(mb_strtolower($searchable), $q) !== false) {
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

    public function findByHash(string $disk, string $hash): ?array
    {
        $index = $this->loadIndex($disk);
        foreach ($index as $fileKey => $meta) {
            if (($meta['file_hash'] ?? '') === $hash) {
                $row = ['file_key' => $fileKey];
                foreach (['title', 'alt_text', 'caption', 'tags'] as $k) {
                    if (isset($meta[$k])) {
                        $row[$k] = $meta[$k];
                    }
                }
                return $row;
            }
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
                $entries[] = [
                    'user_id'   => $ctx['user_id'] ?? '',
                    'action'    => $row['action'] ?? '',
                    'disk'      => $disk,
                    'file_key'  => $ctx['file_key'] ?? '',
                    'ip'        => $ctx['ip'] ?? null,
                    'user_agent' => $ctx['user_agent'] ?? null,
                    'created_at' => $row['ts'] ?? 0,
                ];
            }
            return $entries;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function audit(string $disk, string $action, array $context = []): void
    {
        $entry = json_encode([
            'ts' => time(),
            'action' => $action,
            'context' => $context,
        ]) . "\n";
        $fs = $this->diskManager->disk($disk);
        try {
            $content = '';
            if ($fs->fileExists(self::AUDIT_KEY)) {
                $content = $fs->read(self::AUDIT_KEY);

                // Rotate if audit log exceeds size threshold
                if (strlen($content) > self::MAX_AUDIT_BYTES) {
                    $lines = array_filter(explode("\n", $content));
                    $lines = array_slice($lines, -self::AUDIT_KEEP_LINES);
                    $content = implode("\n", $lines) . "\n";
                }
            }
            $content .= $entry;
            $fs->write(self::AUDIT_KEY, $content);
        } catch (\Throwable $e) {
            // Silent fail
        }
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
        ];

        $copySource = $bucket . '/' . $key;
        $client->copyObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'CopySource' => $copySource,
            'Metadata' => $metadata,
            'MetadataDirective' => 'REPLACE',
        ]);
    }

    private function metaFromS3Headers(array $meta): array
    {
        return [
            'title' => $meta['fm-title'] ?? null,
            'alt_text' => $meta['fm-alt'] ?? null,
            'caption' => $meta['fm-caption'] ?? null,
            'tags' => $meta['fm-tags'] ?? null,
        ];
    }

    private function getFromLocal(string $disk, string $key): ?array
    {
        $metaPath = $this->sidecarPath($key);
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists($metaPath)) {
            return null;
        }
        try {
            $json = $fs->read($metaPath);
            $data = json_decode($json, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function saveToLocal(string $disk, string $key, array $data): void
    {
        $metaPath = $this->sidecarPath($key);
        $fs = $this->diskManager->disk($disk);
        $dir = dirname($metaPath);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }
        $fs->write($metaPath, json_encode([
            'title' => $data['title'] ?? '',
            'alt_text' => $data['alt_text'] ?? '',
            'caption' => $data['caption'] ?? '',
            'tags' => $data['tags'] ?? '',
        ], JSON_UNESCAPED_UNICODE));
    }

    private function sidecarPath(string $key): string
    {
        return $key . '.meta.json';
    }

    /**
     * Acquire an exclusive lock for local disk index operations.
     * Returns the lock file handle, or null for S3 disks (no local locking possible).
     */
    private function acquireIndexLock(string $disk): void
    {
        if ($this->isS3Compatible($disk) || isset($this->indexLocks[$disk])) {
            return;
        }
        $config = $this->diskManager->config($disk);
        $root = $config['root'] ?? __DIR__ . '/../storage/uploads';
        $lockDir = $root . '/_fluxfiles';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
        $lockFile = $lockDir . '/index.lock';
        $fp = fopen($lockFile, 'c+');
        if ($fp !== false && flock($fp, LOCK_EX)) {
            $this->indexLocks[$disk] = $fp;
        }
    }

    private function releaseIndexLock(string $disk): void
    {
        if (isset($this->indexLocks[$disk])) {
            flock($this->indexLocks[$disk], LOCK_UN);
            fclose($this->indexLocks[$disk]);
            unset($this->indexLocks[$disk]);
        }
    }

    private function loadIndex(string $disk): array
    {
        $fs = $this->diskManager->disk($disk);
        if (!$fs->fileExists(self::INDEX_KEY)) {
            return [];
        }
        try {
            $json = $fs->read(self::INDEX_KEY);
            $data = json_decode($json, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function saveIndex(string $disk, array $index): void
    {
        $fs = $this->diskManager->disk($disk);
        $dir = dirname(self::INDEX_KEY);
        if ($dir !== '.' && !$fs->directoryExists($dir)) {
            $fs->createDirectory($dir);
        }
        $fs->write(self::INDEX_KEY, json_encode($index, JSON_UNESCAPED_UNICODE));
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

}

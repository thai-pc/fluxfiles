<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Rate limiter dùng JSON file — không cần SQLite.
 */
class RateLimiterFileStorage
{
    private string $filePath;
    private int $readLimit;
    private int $writeLimit;
    private int $windowSeconds;

    public function __construct(
        string $filePath,
        int $readLimit = 60,
        int $writeLimit = 10,
        int $windowSeconds = 60
    ) {
        $this->filePath = $filePath;
        $this->readLimit = $readLimit;
        $this->writeLimit = $writeLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function check(string $userId, string $actionType): void
    {
        $limit = $actionType === 'read' ? $this->readLimit : $this->writeLimit;
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Use exclusive lock for atomic read-check-write
        $isNew = !file_exists($this->filePath);
        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            throw new ApiException('Rate limiter unavailable', 500);
        }
        if ($isNew) {
            chmod($this->filePath, 0600);
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new ApiException('Rate limiter unavailable', 500);
            }

            $json = stream_get_contents($fp);
            $data = ($json !== '' && $json !== false) ? json_decode($json, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $key = $userId . ':' . $actionType;
            $entries = $data[$key] ?? [];
            $entries = array_values(array_filter($entries, fn($ts) => $ts > $windowStart));

            if (count($entries) >= $limit) {
                flock($fp, LOCK_UN);
                fclose($fp);
                if (!headers_sent()) {
                    header('Retry-After: ' . $this->windowSeconds);
                }
                throw new ApiException('Too many requests. Please try again later.', 429, 'rate_limited');
            }

            $entries[] = $now;
            $data[$key] = array_slice($entries, -$limit);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }
}

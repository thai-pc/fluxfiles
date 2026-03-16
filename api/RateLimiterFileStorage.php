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

        $data = $this->load();
        $key = $userId . ':' . $actionType;
        $entries = $data[$key] ?? [];
        $entries = array_filter($entries, fn($ts) => $ts > $windowStart);

        if (count($entries) >= $limit) {
            header('Retry-After: ' . $this->windowSeconds);
            throw new ApiException('Too many requests. Please try again later.', 429);
        }

        $entries[] = $now;
        $data[$key] = array_slice($entries, -$limit);
        $this->save($data);
    }

    private function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $json = file_get_contents($this->filePath);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function save(array $data): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->filePath, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

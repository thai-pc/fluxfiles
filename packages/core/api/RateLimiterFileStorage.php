<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Rate limiter backed by a JSON file — no SQLite needed.
 */
class RateLimiterFileStorage implements RateLimiterStorageInterface
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
        // Suppress the native warnings: under Laravel a bare mkdir()/fopen() warning
        // is promoted to a fatal ErrorException, which would fire before the guards
        // below ever run. With @ the guards turn an unwritable dir into a clear error.
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException(
                "Storage is not writable: cannot create '{$dir}' for the rate limiter. "
                . "Grant the web server user write access to the storage directory.",
                500,
                'storage_not_writable'
            );
        }

        // Use exclusive lock for atomic read-check-write
        $isNew = !file_exists($this->filePath);
        $fp = @fopen($this->filePath, 'c+');
        if ($fp === false) {
            throw new ApiException(
                "Storage is not writable: cannot open '{$this->filePath}'. Grant the "
                . "web server user write access to the storage directory.",
                500,
                'storage_not_writable'
            );
        }
        if ($isNew) {
            @chmod($this->filePath, 0600);
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

            // Prune keys whose entries have all aged out of the window. Without this
            // the file grows forever: every distinct userId:actionType pair that ever
            // hit the API stays in $data, even long after its entries expired.
            foreach ($data as $k => $entries) {
                $fresh = array_values(array_filter((array) $entries, fn($ts) => $ts > $windowStart));
                if (empty($fresh)) {
                    unset($data[$k]);
                } else {
                    $data[$k] = $fresh;
                }
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

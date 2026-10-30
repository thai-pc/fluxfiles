<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Rate limiter backed by JSON files — no SQLite needed.
 *
 * One small file per `userId:actionType` (not one global file): a request locks,
 * reads and rewrites only its own caller's timestamps, so cost stays flat as the
 * number of active users grows and two users never wait on each other's lock.
 *
 * The constructor still takes a single file path (adapters pinned to an older
 * core construct it that way); the shard directory is derived from it —
 * `/x/rate_limit.json` → `/x/rate_limit.d/<2-hex>/<sha1(key)>.json`. The old
 * global counters are never read: the file itself shrinks to an epoch marker
 * (`ffrl1:<random>`) that every shard records, so deleting `rate_limit.json`
 * still resets every limit at once, as it always did (operators and test
 * suites rely on that), while a request costs one 22-byte marker read.
 */
class RateLimiterFileStorage implements RateLimiterStorageInterface
{
    private string $filePath;
    private string $shardRoot;
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
        $this->shardRoot = dirname($filePath) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.d';
        $this->readLimit = $readLimit;
        $this->writeLimit = $writeLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function check(string $userId, string $actionType): void
    {
        $limit = $actionType === 'read' ? $this->readLimit : $this->writeLimit;
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $epoch = $this->epoch();
        $hash = sha1($userId . ':' . $actionType);
        $dir = $this->shardRoot . '/' . substr($hash, 0, 2);
        $file = $dir . '/' . $hash . '.json';

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
        $isNew = !file_exists($file);
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            throw new ApiException(
                "Storage is not writable: cannot open '{$file}'. Grant the "
                . "web server user write access to the storage directory.",
                500,
                'storage_not_writable'
            );
        }
        if ($isNew) {
            @chmod($file, 0600);
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new ApiException('Rate limiter unavailable', 500);
            }

            $json = stream_get_contents($fp);
            $data = ($json !== '' && $json !== false) ? json_decode($json, true) : null;
            // A shard written under another epoch (the marker was deleted/replaced) is a reset.
            $entries = (is_array($data) && ($data['e'] ?? null) === $epoch && is_array($data['t'] ?? null)) ? $data['t'] : [];
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

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['e' => $epoch, 't' => array_slice($entries, -$limit)]));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }

        // Without pruning, every distinct caller that ever hit the API would leave a
        // file behind. Sweep one shard directory now and then (~1 request in 64):
        // amortised, it costs a 256th of the files per sweep.
        if (mt_rand(1, 64) === 1) {
            $this->sweep($dir, $now);
        }
    }

    /**
     * The current epoch: the marker file's content. Created (atomically) when the
     * file is missing, and replaces a pre-sharding global counter file.
     */
    private function epoch(): string
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException(
                "Storage is not writable: cannot create '{$dir}' for the rate limiter. "
                . "Grant the web server user write access to the storage directory.",
                500,
                'storage_not_writable'
            );
        }
        $head = @file_get_contents($this->filePath, false, null, 0, 32);
        if (is_string($head) && preg_match('/^ffrl1:[0-9a-f]{16}$/', $head) === 1) {
            return $head;
        }
        $epoch = 'ffrl1:' . bin2hex(random_bytes(8));
        $tmp = $this->filePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $epoch) === false) {
            throw new ApiException(
                "Storage is not writable: cannot write '{$this->filePath}'. Grant the "
                . "web server user write access to the storage directory.",
                500,
                'storage_not_writable'
            );
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->filePath)) {
            @unlink($tmp);
            // Lost a creation race (the winner's marker is valid), or the file can't
            // be replaced. Never hand out a throw-away epoch: it would reset every
            // shard on every request and turn the limiter off.
            $head = @file_get_contents($this->filePath, false, null, 0, 32);
            if (is_string($head) && preg_match('/^ffrl1:[0-9a-f]{16}$/', $head) === 1) {
                return $head;
            }
            throw new ApiException(
                "Storage is not writable: cannot replace '{$this->filePath}'. Grant the "
                . "web server user write access to the storage directory.",
                500,
                'storage_not_writable'
            );
        }
        return $epoch;
    }

    /** Drop shard files whose newest entry is older than the window (nothing in them counts any more). */
    private function sweep(string $dir, int $now): void
    {
        $cutoff = $now - max($this->windowSeconds, 1) - 60;
        $names = @scandir($dir);
        if ($names === false) {
            return;
        }
        foreach ($names as $name) {
            if (substr($name, -5) !== '.json') {
                continue;
            }
            $m = @filemtime($dir . '/' . $name);
            if ($m !== false && $m < $cutoff) {
                @unlink($dir . '/' . $name);
            }
        }
    }
}

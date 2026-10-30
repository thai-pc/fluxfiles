<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\ApiException;
use FluxFiles\RateLimiterStorageInterface;

/**
 * Sliding-window-log rate limiter backed by a `rate_limits` hits table (name overridable for hosts with their own naming/prefix) —
 * behaviorally equivalent to RateLimiterFileStorage's per-key timestamp array,
 * just stored as rows instead of a JSON blob.
 */
final class RateLimiterDbStorage implements RateLimiterStorageInterface
{
    private Connection $db;
    private int $readLimit;
    private int $writeLimit;
    private int $windowSeconds;
    private string $table;

    public function __construct(
        Connection $db,
        int $readLimit = 60,
        int $writeLimit = 10,
        int $windowSeconds = 60,
        string $table = 'rate_limits'
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid rate limiter table name.');
        }
        $this->table = $table;
        $this->db = $db;
        $this->readLimit = $readLimit;
        $this->writeLimit = $writeLimit;
        $this->windowSeconds = $windowSeconds;
    }

    public function check(string $identifier, string $actionType): void
    {
        $limit = $actionType === 'read' ? $this->readLimit : $this->writeLimit;
        $now = time();
        $windowStart = $now - $this->windowSeconds;
        $pdo = $this->db->pdo();

        $this->db->beginExclusive();
        $limited = false;
        try {
            $del = $pdo->prepare("DELETE FROM {$this->table} WHERE identifier = ? AND bucket = ? AND ts <= ?");
            $del->execute([$identifier, $actionType, $windowStart]);

            $forUpdate = $this->db->dialect()->forUpdateSuffix();
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE identifier = ? AND bucket = ?{$forUpdate}");
            $cnt->execute([$identifier, $actionType]);

            if ((int) $cnt->fetchColumn() >= $limit) {
                $limited = true;
            } else {
                $ins = $pdo->prepare("INSERT INTO {$this->table} (identifier, bucket, ts) VALUES (?, ?, ?)");
                $ins->execute([$identifier, $actionType, $now]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        if ($limited) {
            if (!headers_sent()) {
                header('Retry-After: ' . $this->windowSeconds);
            }
            throw new ApiException('Too many requests. Please try again later.', 429, 'rate_limited');
        }

        // 1%-probabilistic global sweep, best-effort — never let a sweep failure break the request.
        if (random_int(1, 100) === 1) {
            try {
                $pdo->prepare("DELETE FROM {$this->table} WHERE ts < ?")->execute([$windowStart]);
            } catch (\Throwable $e) {
                // cleanup only, ignore
            }
        }
    }
}

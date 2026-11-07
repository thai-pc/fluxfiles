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

        // One injective, NUL-free key per (table, identifier, bucket): serialises hits on the
        // same key only. (libpq truncates text params at a NUL, so no "\0" separators.)
        $lockKey = json_encode([$this->table, $identifier, $actionType]);
        $driver = $this->db->driver();
        $mysqlLock = null;
        $limited = false;
        try {
            if ($driver === 'mysql') {
                // On an empty key InnoDB takes only gap locks, so two first hits both INSERT
                // and deadlock (1213); a named lock serialises them. READ COMMITTED so the
                // row count sees a hit another request committed while we waited.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $g = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                $g->execute(['fxrl_' . sha1($lockKey)]);
                if ((int) $g->fetchColumn() !== 1) {
                    throw new \RuntimeException('Rate limiter lock timeout.');
                }
                $mysqlLock = 'fxrl_' . sha1($lockKey);
            }
            $this->db->beginExclusive();
            try {
                if ($driver === 'pgsql') {
                    // REPEATABLE READ would ignore hits committed after our snapshot; the
                    // advisory lock (per key, released at COMMIT) serialises the rest.
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))')->execute([$lockKey]);
                }
                $del = $pdo->prepare("DELETE FROM {$this->table} WHERE identifier = ? AND bucket = ? AND ts <= ?");
                $del->execute([$identifier, $actionType, $windowStart]);

                $forUpdate = $this->db->dialect()->forUpdateSuffix();
                // Lock the key's hit rows and count them in PHP: PostgreSQL rejects
                // FOR UPDATE combined with an aggregate (COUNT(*)).
                $cnt = $pdo->prepare("SELECT id FROM {$this->table} WHERE identifier = ? AND bucket = ?{$forUpdate}");
                $cnt->execute([$identifier, $actionType]);

                if (count($cnt->fetchAll(\PDO::FETCH_COLUMN)) >= $limit) {
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
        } finally {
            if ($mysqlLock !== null) {
                try {
                    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$mysqlLock]);
                } catch (\Throwable $e) {
                    // session-scoped; it dies with the connection
                }
            }
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

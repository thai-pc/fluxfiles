<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- opt-in FLUXFILES_STORAGE_BACKEND=db: the operator's own MySQL/PostgreSQL/SQLite connection for FluxFiles' rate limiter, not WordPress's $wpdb (the WordPress plugin uses WpRateLimiterStorage on $wpdb)

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

        // Hits on the same (table, identifier, bucket) are serialised; other keys never wait.
        // The key halves are NUL-free (libpq truncates text params at a NUL).
        $keyJson = json_encode([$identifier, $actionType]);
        $driver = $this->db->driver();
        $mysqlLock = null;
        $limited = false;
        try {
            if ($driver === 'mysql') {
                // On an empty key InnoDB takes only gap locks, so two first hits that both
                // SELECT ... FOR UPDATE then INSERT deadlock (1213). A named lock serialises
                // the key instead, and the key's hits are read before the transaction opens
                // (below), so no locking read is involved at any isolation level.
                // (No READ COMMITTED: it is refused under binlog_format=STATEMENT, 1665.)
                $name = 'fxrl_' . sha1($this->table . "|" . $keyJson);
                $g = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                $g->execute([$name]);
                if ((int) $g->fetchColumn() !== 1) {
                    if (!headers_sent()) {
                        header('Retry-After: 1');
                    }
                    throw new ApiException('Too many requests. Please try again later.', 429, 'rate_limited');
                }
                $mysqlLock = $name;

                // Autocommit, non-locking read, after GET_LOCK so it sees the previous
                // holder's commit. Outside a transaction it takes no shared gap lock even
                // under SERIALIZABLE (inside one, InnoDB turns a plain SELECT into LOCK IN
                // SHARE MODE and distinct-key first hits deadlock).
                $sel = $pdo->prepare("SELECT id, ts FROM {$this->table} WHERE identifier = ? AND bucket = ?");
                $sel->execute([$identifier, $actionType]);
                $expired = [];
                $count = 0;
                foreach ($sel->fetchAll(\PDO::FETCH_NUM) as [$id, $ts]) {
                    if ((int) $ts <= $windowStart) {
                        $expired[] = (int) $id;
                    } else {
                        $count++;
                    }
                }
            }
            $this->db->beginExclusive();
            try {
                if ($driver === 'pgsql') {
                    // REPEATABLE READ would ignore hits committed after our snapshot; the
                    // per-key advisory lock (two-int form, PostgreSQL 9.5+ like the rest of the schema, released at
                    // COMMIT) serialises the rest.
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))')->execute([$this->table, $keyJson]);
                }
                if ($driver === 'mysql') {
                    // Expired rows go by primary key, never by range (no gap locks).
                    if ($expired) {
                        $pdo->exec("DELETE FROM {$this->table} WHERE id IN (" . implode(',', $expired) . ')');
                    }
                } else {
                    $del = $pdo->prepare("DELETE FROM {$this->table} WHERE identifier = ? AND bucket = ? AND ts <= ?");
                    $del->execute([$identifier, $actionType, $windowStart]);

                    $forUpdate = $this->db->dialect()->forUpdateSuffix();
                    // Lock the key's hit rows and count them in PHP: PostgreSQL rejects
                    // FOR UPDATE combined with an aggregate (COUNT(*)).
                    $cnt = $pdo->prepare("SELECT id FROM {$this->table} WHERE identifier = ? AND bucket = ?{$forUpdate}");
                    $cnt->execute([$identifier, $actionType]);
                    $count = count($cnt->fetchAll(\PDO::FETCH_COLUMN));
                }

                if ($count >= $limit) {
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

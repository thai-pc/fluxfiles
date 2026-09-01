<?php

declare(strict_types=1);

namespace FluxFiles\Db;

use FluxFiles\ApiException;

/**
 * Lazy PDO wrapper for the optional `db` storage backend. Never opens a
 * connection until pdo() is actually called, so a `backend=json` install
 * (the default, zero PDO extensions) never touches this class at all.
 */
class Connection
{
    private string $dsn;
    private string $user;
    private string $password;
    private ?\PDO $pdo = null;
    private ?Dialect $dialect = null;

    public function __construct(string $dsn, string $user = '', string $password = '')
    {
        $this->dsn = $dsn;
        $this->user = $user;
        $this->password = $password;
    }

    public static function fromEnv(): self
    {
        return new self(
            (string) ($_ENV['FLUXFILES_DB_DSN'] ?? ''),
            (string) ($_ENV['FLUXFILES_DB_USER'] ?? ''),
            (string) ($_ENV['FLUXFILES_DB_PASSWORD'] ?? '')
        );
    }

    public function driver(): string
    {
        $pos = strpos($this->dsn, ':');
        return $pos === false ? '' : substr($this->dsn, 0, $pos);
    }

    public function dialect(): Dialect
    {
        if ($this->dialect === null) {
            $this->dialect = match ($this->driver()) {
                'mysql' => new MysqlDialect(),
                'pgsql' => new PgsqlDialect(),
                default => new SqliteDialect(),
            };
        }
        return $this->dialect;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!extension_loaded('pdo')) {
            throw new ApiException('PDO extension is not available', 500, 'db_pdo_missing');
        }
        if ($this->dsn === '') {
            throw new ApiException('FLUXFILES_DB_DSN is not configured', 500, 'db_dsn_missing');
        }

        $pdo = new \PDO($this->dsn, $this->user !== '' ? $this->user : null, $this->password !== '' ? $this->password : null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($this->driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys=ON');
            $pdo->exec('PRAGMA journal_mode=WAL');
        }

        $this->pdo = $pdo;
        return $this->pdo;
    }

    /**
     * Start a transaction that takes an exclusive write lock up front on SQLite
     * (BEGIN IMMEDIATE) — plain beginTransaction() there is deferred and does not
     * take the lock until the first write, which is too late for callers doing a
     * read-then-decide-then-write sequence (e.g. the rate limiter). MySQL/Postgres
     * have no such distinction, so it's a plain beginTransaction() there.
     *
     * Pair with commit()/rollback() below, not $pdo->commit()/rollBack() directly:
     * PDO's own commit()/rollBack() require its internal in_txn flag to have been
     * set by ITS OWN beginTransaction(), which a raw exec('BEGIN IMMEDIATE') never
     * sets — calling $pdo->commit() after it throws "There is no active
     * transaction" even though SQLite itself is genuinely mid-transaction.
     */
    public function beginExclusive(): void
    {
        $pdo = $this->pdo();
        if ($this->driver() === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        $pdo = $this->pdo();
        if ($this->driver() === 'sqlite') {
            $pdo->exec('COMMIT');
        } else {
            $pdo->commit();
        }
    }

    /** Best-effort: swallows the "no active transaction" case so a rollback after a failed commit is always safe to call. */
    public function rollback(): void
    {
        $pdo = $this->pdo();
        try {
            if ($this->driver() === 'sqlite') {
                $pdo->exec('ROLLBACK');
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            // already rolled back / no active transaction — nothing to do
        }
    }
}

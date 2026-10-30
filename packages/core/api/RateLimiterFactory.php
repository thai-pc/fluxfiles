<?php

declare(strict_types=1);

namespace FluxFiles;

final class RateLimiterFactory
{
    /**
     * The limiter the env selects: DB-backed when FLUXFILES_STORAGE_BACKEND=db
     * (over $dbConn, or one built from FLUXFILES_DB_*), else the JSON-file one.
     */
    public static function make(
        int $readLimit,
        int $writeLimit,
        int $windowSeconds = 60,
        ?\FluxFiles\Db\Connection $dbConn = null
    ): RateLimiterStorageInterface {
        $backend = $_ENV['FLUXFILES_STORAGE_BACKEND'] ?? 'json';
        if ($backend === 'db') {
            return self::makeDb($dbConn ?? \FluxFiles\Db\Connection::fromEnv(), $readLimit, $writeLimit, $windowSeconds);
        }

        $storagePath = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
        return self::makeFile($storagePath, $readLimit, $writeLimit, $windowSeconds);
    }

    /**
     * The DB-backed limiter over an explicit connection, whatever the env says.
     * For hosts that run their own backend selection (the Laravel/WordPress proxies
     * decide "db mode" from their own config, not FLUXFILES_STORAGE_BACKEND) and can
     * build a `Db\Connection` from their DSN.
     */
    public static function makeDb(
        \FluxFiles\Db\Connection $dbConn,
        int $readLimit,
        int $writeLimit,
        int $windowSeconds = 60,
        string $table = 'rate_limits'
    ): RateLimiterStorageInterface {
        return new \FluxFiles\Db\RateLimiterDbStorage($dbConn, $readLimit, $writeLimit, $windowSeconds, $table);
    }

    /** The JSON-file limiter under $storagePath (its `rate_limit.json` + shard dir). */
    public static function makeFile(
        string $storagePath,
        int $readLimit,
        int $writeLimit,
        int $windowSeconds = 60
    ): RateLimiterStorageInterface {
        return new RateLimiterFileStorage(rtrim($storagePath, '/') . '/rate_limit.json', $readLimit, $writeLimit, $windowSeconds);
    }
}

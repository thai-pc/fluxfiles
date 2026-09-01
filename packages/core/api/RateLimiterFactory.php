<?php

declare(strict_types=1);

namespace FluxFiles;

final class RateLimiterFactory
{
    public static function make(
        int $readLimit,
        int $writeLimit,
        int $windowSeconds = 60,
        ?\FluxFiles\Db\Connection $dbConn = null
    ): RateLimiterStorageInterface {
        $backend = $_ENV['FLUXFILES_STORAGE_BACKEND'] ?? 'json';
        if ($backend === 'db') {
            return new \FluxFiles\Db\RateLimiterDbStorage(
                $dbConn ?? \FluxFiles\Db\Connection::fromEnv(),
                $readLimit,
                $writeLimit,
                $windowSeconds
            );
        }

        $storagePath = rtrim($_ENV['FLUXFILES_STORAGE_PATH'] ?? (__DIR__ . '/../storage'), '/');
        return new RateLimiterFileStorage($storagePath . '/rate_limit.json', $readLimit, $writeLimit, $windowSeconds);
    }
}

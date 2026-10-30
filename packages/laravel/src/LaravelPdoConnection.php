<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use FluxFiles\Db\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Core's `Db\Connection` over Laravel's own PDO (named connection or the app
 * default) instead of a DSN. It lets core's DB-backed classes that take a
 * `Db\Connection` — today the rate limiter (`RateLimiterFactory::makeDb()`) —
 * run on the same database as LaravelDbMetadataHandler, with no second set of
 * credentials in the env.
 */
final class LaravelPdoConnection extends Connection
{
    private ?string $name;

    public function __construct(?string $name = null)
    {
        parent::__construct('');
        $this->name = $name;
    }

    public function pdo(): \PDO
    {
        return DB::connection($this->name)->getPdo();
    }

    /** The PDO driver name ('mysql'|'pgsql'|'sqlite'); core derives the SQL dialect from it. */
    public function driver(): string
    {
        return (string) $this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}

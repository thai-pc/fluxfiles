<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string token(string|int|\Illuminate\Contracts\Auth\Authenticatable $user, array $overrides = [])
 * @method static string tokenForUser(array $overrides = [])
 * @method static string endpoint()
 * @method static string iframeSrc()
 * @method static string sdkUrl()
 *
 * @see \FluxFiles\Laravel\FluxFilesManager
 */
class FluxFilesFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FluxFilesManager::class;
    }
}

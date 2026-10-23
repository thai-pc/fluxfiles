<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string token(string|int|\Illuminate\Contracts\Auth\Authenticatable $user, array $overrides = [])
 * @method static string tokenForUser(array $overrides = [])
 * @method static string tokenForResolvedUser(string|int|\Illuminate\Contracts\Auth\Authenticatable $user, array $overrides = [])
 * @method static void resolveClaimsUsing(?callable $resolver)
 * @method static bool hasClaimsResolver()
 * @method static array claimsFor(string|int|\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static string refreshTicketForUser(array $overrides = [])
 * @method static string endpoint()
 * @method static string iframeSrc()
 * @method static string sdkUrl()
 * @method static array licenseInfo()
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

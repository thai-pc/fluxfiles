<?php

declare(strict_types=1);

namespace App\FluxFiles;

use FluxFiles\Laravel\ClaimsResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Test-only per-user policy. Since laravel-v1.0.6 (a325e97) an implicit mint — the
 * <x-fluxfiles> component, the refresh route — is refused unless the app has a
 * claims_resolver. This rig grants every key from the config `defaults` (an empty
 * array leaves them all to the defaults); each spec's ?o= overrides are merged on top
 * by the component. A real app returns a per-user scope here (prefix, perms, disks).
 */
class LiveClaims implements ClaimsResolver
{
    public function resolve(Authenticatable $user): array
    {
        return [];
    }
}

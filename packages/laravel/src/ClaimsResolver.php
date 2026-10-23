<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Per-user token policy for every token this adapter mints IMPLICITLY — i.e.
 * without the host app passing an overrides array itself: the default
 * `onTokenRefresh` route (`GET /api/fm/token`), the FluxFilesAuth session
 * fallback, `tokenForUser()`, the `<x-fluxfiles>` component and the
 * `@fluxfilesToken` directive.
 *
 * Return the same overrides array `FluxFilesManager::token()` accepts (perms,
 * prefix, disks, role, claims, …). Explicit per-call overrides (a component's
 * `:overrides`, a `tokenForUser([...])` argument) are merged ON TOP. Throw to
 * refuse a token for this user altogether.
 *
 * Register it in config/fluxfiles.php (`'claims_resolver' => MyResolver::class`,
 * resolved through the container) or at runtime with
 * `FluxFiles::resolveClaimsUsing(fn ($user) => [...])` from a service provider.
 */
interface ClaimsResolver
{
    /**
     * @return array<string,mixed>
     */
    public function resolve(Authenticatable $user): array;
}

<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

/**
 * Thrown when a token is requested for a signed-in user (component,
 * `@fluxfilesToken`, `tokenForUser()`, …) but no `fluxfiles.claims_resolver` is
 * configured. Minting from the bare config `defaults` would hand every logged-in
 * user read/write/delete on the whole disk (GHSA-m73q-w9rc-jcxh), so the adapter
 * refuses instead. The message is for the developer; never show it to end users.
 */
class ClaimsResolverRequired extends \RuntimeException
{
}

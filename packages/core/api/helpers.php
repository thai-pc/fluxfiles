<?php

declare(strict_types=1);

/**
 * Small host-shared helpers: ff_str_param(), ff_public_meta_repo(), ff_sso_rate_limit().
 *
 * The Share and Intake public handlers that used to live here (this file was
 * `PublicLinks.php`) now ship inside their modules and are served through
 * {@see \FluxFiles\PublicRoutes}. What remains is function-only on purpose: the
 * WordPress plugin pulls these in without including index.php, which would run auth and
 * routing as a side effect.
 *
 * ff_sso_rate_limit() is not a Share/Intake route: the SSO bridge's pre-auth routes are
 * the one place with no main JWT to rate-limit against, so it rides the JSON-file limiter.
 */

use FluxFiles\DiskManager;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\RateLimiterFactory;
use FluxFiles\RateLimiterStorageInterface;

/**
 * One string request parameter, safe against an array-shaped param.
 *
 * `?token[]=a` makes `$_GET['token']` an ARRAY, and `(string) $array` emits a PHP
 * warning — which, with display_errors on, lands ahead of the JSON envelope and
 * discloses the absolute server path. Anything non-scalar collapses to the default
 * instead, so a hostile query string can only ever produce a normal 4xx.
 *
 * @param array<string,mixed> $src $_GET / $_POST / a decoded JSON body
 */
function ff_str_param(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? null;
    return is_scalar($v) ? (string) $v : $default;
}

/**
 * The metadata repository a public request must use: the one the operator
 * configured (`FLUXFILES_STORAGE_BACKEND=db` → the self-hosted DB, else JSON in
 * storage) — built exactly like index.php builds `$metaRepo`, minus the
 * auto-migrate (a public request is no place to run migrations). A portal upload
 * stamped through the JSON handler on a DB-mode install landed its `uploaded_by`,
 * hash and index entry where nothing ever reads them: owner_only then treated the
 * operator's own portal files as legacy, and they were missing from search.
 *
 * A host that injects its own DiskManager (WordPress, Laravel) has its own DB
 * handler and should inject that too; without one it keeps the JSON handler it
 * always had — its env is not the standalone app's, so the standalone switch
 * isn't trusted for it.
 */
function ff_public_meta_repo(DiskManager $dm, ?MetadataRepositoryInterface $injected = null, bool $hostInjectedDisks = false): MetadataRepositoryInterface
{
    return \FluxFiles\PublicContext::resolveMeta($dm, $injected, $hostInjectedDisks);
}

/**
 * Per-client-IP rate limit for the SSO bridge's three pre-auth routes
 * (`login`/`callback`/`exchange`), mirroring ff_share_rate_limit()'s JSON-file
 * limiter. Unlike share/intake, there is no token/id to bucket by until AFTER
 * `callback` verifies `state` — so `REMOTE_ADDR` is the only key available,
 * same rotation caveat as the IP half of `share_unlock`/`intake_upload`
 * (not a safe-ONLY limit on its own, but there is no better key pre-auth).
 * `callback` gets the tightest default: it triggers a real outbound cURL to
 * the IdP's token endpoint per hit, the most expensive of the three.
 */
function ff_sso_rate_limit(string $kind): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $limiter = static function (int $limit): RateLimiterStorageInterface {
        return RateLimiterFactory::make($limit, $limit, 60);
    };

    $limits = [
        'login' => (int) ($_ENV['FLUXFILES_SSO_LOGIN_LIMIT'] ?? 20),
        'callback' => (int) ($_ENV['FLUXFILES_SSO_CALLBACK_LIMIT'] ?? 10),
        'exchange' => (int) ($_ENV['FLUXFILES_SSO_EXCHANGE_LIMIT'] ?? 30),
    ];
    $limit = max(1, $limits[$kind] ?? 20);
    $limiter($limit)->check('sso_' . $kind . ':' . $ip, 'read');
}

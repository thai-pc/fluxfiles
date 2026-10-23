<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the proxied /api/fm/* routes. The FluxFiles JWT (Bearer) is the
 * credential the controller enforces; the Laravel session only proves who the
 * caller is.
 *
 * A request that carries NO Bearer is authenticated by the session cookie alone,
 * so it gets two extra checks before any token is minted for it:
 *  - state-changing methods are refused outright (401 token_missing). The route
 *    prefix is CSRF-exempt (see README), so a cookie-only POST/PUT/DELETE is
 *    exactly the request a cross-site form can forge; the embedded UI always
 *    sends its Bearer, so nothing legitimate depends on the fallback for writes.
 *  - the request must come from this site (or `fluxfiles.allowed_origins`),
 *    judged by Origin / Sec-Fetch-Site — core's CSRF check, applied to reads too
 *    because a cookie-authenticated GET (e.g. disk/doctor) can still act.
 * A safe-method request that passes is given a token from the per-user claims
 * resolver — and only when one is registered: without it the only policy left is
 * the config defaults (rwd, empty prefix), which used to make every logged-in
 * user a full-access tenant here.
 */
class FluxFilesAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data'  => null,
                'error' => 'Unauthenticated',
            ], 401);
        }

        // A Bearer is the real credential — pass it through untouched.
        if ($request->bearerToken()) {
            return $next($request);
        }

        if (!self::isSafeMethod($request->getMethod())) {
            return self::refuse(401, 'token_missing', 'A FluxFiles Bearer token is required for this request');
        }
        if (!self::originAllowed($request)) {
            return self::refuse(403, 'origin_denied', 'Origin not allowed');
        }

        $manager = app(\FluxFiles\Laravel\FluxFilesManager::class);
        if (!$manager->hasClaimsResolver()) {
            return self::refuse(401, 'token_missing', 'A FluxFiles Bearer token is required (no fluxfiles.claims_resolver is configured for session-only requests)');
        }
        try {
            $token = $manager->tokenForResolvedUser($user);
        } catch (\Throwable $e) {
            return self::refuse(403, 'forbidden', 'No FluxFiles access for this user');
        }
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $next($request);
    }

    public static function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Whether a session-authenticated request originates from this site (or a
     * configured trusted origin). Shared with FluxFilesController::token(), the
     * other cookie-only entry point.
     */
    public static function originAllowed(Request $request): bool
    {
        $allowed = array_values(array_filter(array_map(
            static fn ($o) => rtrim(trim((string) $o), '/'),
            (array) config('fluxfiles.allowed_origins', [])
        )));

        return self::isSameSiteRequest(
            (string) $request->headers->get('Origin', ''),
            (string) $request->headers->get('Sec-Fetch-Site', ''),
            $request->getSchemeAndHttpHost(),
            $allowed
        );
    }

    /**
     * Pure decision behind originAllowed(). An Origin header is decisive (must be
     * this host or an allow-listed one, like core's CSRF check). Without one —
     * browsers omit it on plain same-origin GETs, but also on cross-site <img>/
     * navigation GETs — `Sec-Fetch-Site` decides; a client that sends neither is
     * not a browser riding a victim's cookie, so it passes.
     *
     * @param list<string> $allowedOrigins
     */
    public static function isSameSiteRequest(string $origin, string $fetchSite, string $selfOrigin, array $allowedOrigins): bool
    {
        if ($origin !== '') {
            $origin = rtrim($origin, '/');
            return $origin === rtrim($selfOrigin, '/') || in_array($origin, $allowedOrigins, true);
        }
        if ($fetchSite !== '') {
            return in_array(strtolower($fetchSite), ['same-origin', 'none'], true);
        }
        return true;
    }

    private static function refuse(int $status, string $code, string $message): Response
    {
        return response()->json(['data' => null, 'error' => $message, 'error_code' => $code], $status);
    }
}

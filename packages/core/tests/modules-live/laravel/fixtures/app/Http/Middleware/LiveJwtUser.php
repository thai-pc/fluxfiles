<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use FluxFiles\JwtMiddleware;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only guard (same as tests/apps' E2eJwtUser): the proxied /api/fm/* routes
 * need $request->user() for the adapter's FluxFilesAuth, so the user is taken
 * from the FluxFiles Bearer itself — a stateless API-token deployment of the
 * proxy, no DB-backed session. A production app uses its own session/guard.
 */
class LiveJwtUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token) {
            try {
                $claims = JwtMiddleware::handle($token, (string) config('fluxfiles.secret'));
                $request->setUserResolver(fn () => new GenericUser(['id' => $claims->userId]));
            } catch (\Throwable $e) {
                // unauthenticated → FluxFilesAuth answers 401
            }
        }
        return $next($request);
    }
}

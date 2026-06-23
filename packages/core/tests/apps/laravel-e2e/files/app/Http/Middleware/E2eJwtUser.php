<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use FluxFiles\JwtMiddleware;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E2E-only: authenticate the request from the FluxFiles JWT Bearer token so the
 * adapter's FluxFilesAuth (which requires $request->user()) is satisfied without a
 * DB-backed Laravel session. This models a stateless API-token deployment of the
 * proxy; in production you'd use Laravel's session/auth or a token guard instead.
 */
class E2eJwtUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token) {
            try {
                $claims = JwtMiddleware::handle($token, (string) config('fluxfiles.secret'));
                $request->setUserResolver(fn () => new GenericUser(['id' => $claims->userId]));
            } catch (\Throwable $e) {
                // Leave the request unauthenticated → FluxFilesAuth returns 401.
            }
        }
        return $next($request);
    }
}

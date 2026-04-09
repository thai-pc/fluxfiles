<?php

declare(strict_types=1);

namespace FluxFiles\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts Laravel's authenticated user into a FluxFiles JWT Bearer token,
 * injecting it into the request so the FluxFiles controller can use it.
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

        // If the request already has a Bearer token, let it pass through
        if (!$request->bearerToken()) {
            $manager = app(\FluxFiles\Laravel\FluxFilesManager::class);
            $token = $manager->token($user);
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}

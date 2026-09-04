<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces Sanctum token abilities on role-scoped API routes.
 *
 * Registered as the `ability` alias in bootstrap/app.php. Routes declare it as
 * `->middleware(['auth:sanctum', 'ability:customer'])` and the token issued by
 * AuthController carries exactly one ability (customer|merchant|rider|admin).
 *
 * A token with no abilities at all (wildcard) still passes — this mirrors
 * Sanctum's default `tokenCan` behaviour and is harmless here because every
 * issued token is created with its role ability.
 */
class EnsureTokenHasAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        foreach ($abilities as $ability) {
            if ($user->tokenCan($ability)) {
                return $next($request);
            }
        }

        abort(403, 'Missing required ability: ' . implode('|', $abilities));
    }
}

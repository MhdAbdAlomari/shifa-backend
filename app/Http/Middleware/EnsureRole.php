<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,coordinator')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiError::make('Unauthenticated.', 'unauthenticated', 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return ApiError::make(
                'Forbidden. Requires role: ' . implode(' or ', $roles),
                'role_forbidden',
                403,
                ['required_role' => count($roles) === 1 ? $roles[0] : array_values($roles)],
            );
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to one or more high-level roles.
 *
 * The check is delegated to the Gate, so the centralised super-admin bypass in
 * App\Providers\AuthServiceProvider applies here exactly as it does to permissions.
 *
 * Usage: ->middleware('role:ADMIN,REGISTRAR')
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::unauthenticated();
        }

        foreach ($roles as $role) {
            if (Gate::forUser($user)->allows('role', $role)) {
                return $next($request);
            }
        }

        return ApiResponse::forbidden('This action is unauthorized.');
    }
}

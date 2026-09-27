<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to holders of one or more permissions.
 *
 * The check is delegated to the Gate, so the super-admin bypass and permission
 * resolution stay in App\Providers\AuthServiceProvider.
 *
 * Usage: ->middleware('permission:users.view,users.update')
 */
class EnsureUserHasPermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::unauthenticated();
        }

        foreach ($permissions as $permission) {
            if (Gate::forUser($user)->allows($permission)) {
                return $next($request);
            }
        }

        return ApiResponse::forbidden('This action is unauthorized.');
    }
}

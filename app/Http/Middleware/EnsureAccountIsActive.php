<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from accounts that are not ACTIVE. Applied after authentication so
 * that a suspension or deactivation revokes access immediately instead of taking
 * effect only at the next login.
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::unauthenticated();
        }

        if ($user->isActive()) {
            return $next($request);
        }

        // Revoke every outstanding token so the account cannot be used elsewhere.
        $user->tokens()->delete();

        return ApiResponse::forbidden(
            $user->isSuspended()
                ? 'This account has been suspended.'
                : 'This account is not active.'
        );
    }
}

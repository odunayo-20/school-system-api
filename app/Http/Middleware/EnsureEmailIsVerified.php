<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request from an account whose email address is not verified.
 *
 * This replaces the framework's EnsureEmailIsVerified, which decides between a 403 and
 * an HTML redirect using $request->expectsJson(). That test is the problem: a client
 * that omits "Accept: application/json" makes the framework redirect to
 * route("verification.notice"), which a headless API does not define, so the request
 * dies with a 500 instead of a 403. This middleware always answers with the API
 * envelope, whatever the client negotiated.
 */
class EnsureEmailIsVerified
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

        if ($user->hasVerifiedEmail()) {
            return $next($request);
        }

        return ApiResponse::forbidden(
            'Your email address is not verified. Request a new link with POST /api/v1/auth/email/verification-notification.'
        );
    }
}

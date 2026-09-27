<?php

use App\Exceptions\BusinessRuleViolation;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The API is stateless: a bearer token is the only credential. Sanctum's
         * "EnsureFrontendRequestsAreStateful" middleware is deliberately NOT enabled,
         * so no session or CSRF cookie is ever involved in an API request.
         */
        $middleware->api(prepend: [
            'throttle:api',
        ]);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'role' => EnsureUserHasRole::class,
            'permission' => EnsureUserHasPermission::class,
            'verified' => EnsureEmailIsVerified::class,
        ]);

        /*
         * Laravel's "auth" middleware redirects an unauthenticated guest to
         * route("login") unless the request expects JSON. This application is headless
         * and defines no "login" route, so without this a plain client that omits
         * Accept: application/json would receive a 500 RouteNotFoundException instead of
         * a 401. Returning null always lets the AuthenticationException bubble up to the
         * JSON handler below. If a browser-based admin panel is ever added, point this at
         * that panel's own route.
         */
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* request is answered with JSON, so a client never has to parse an
        // HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::unauthenticated($e->getMessage() ?: 'Unauthenticated.');
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::forbidden($e->getMessage() ?: 'This action is unauthorized.');
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        /*
         * A business rule rejected a well formed, permitted request: activating a second
         * term, deleting the current session, removing a class level that still has
         * classes. It shares the 422 status with a validation failure because the remedy is
         * the same from a client's point of view, but it is rendered before the catch-all
         * below so the message names the actual obstacle instead of being flattened to
         * "Server error."
         */
        $exceptions->render(function (BusinessRuleViolation $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422, $e->errors());
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::tooManyRequests($e->getMessage() ?: 'Too many requests.');
            }
        });

        /*
         * A 405 has to name the methods that WOULD work, and RFC 9110 requires that list in
         * an Allow header, not only in the body. The catch-all below builds a fresh JSON
         * response and so drops whatever headers the exception carried, which loses Allow:
         * a client that sent PATCH to a PUT-only endpoint got a sentence in the message but
         * a response a generic HTTP client could not act on.
         *
         * This is additive. It applies to MethodNotAllowedHttpException only, changes no
         * body, and no route in Modules 01 to 03 relies on the missing header.
         */
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = ApiResponse::error(
                $e->getMessage() ?: 'Request failed.',
                405,
            );

            if ($e->getHeaders() !== []) {
                $response->headers->add($e->getHeaders());
            }

            return $response;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Resource not found.', 404);
            }
        });

        // Last resort for /api/*: a generic message, never an internal detail, file
        // path or stack trace, regardless of the APP_DEBUG setting.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return ApiResponse::error(
                $status === 500 ? 'Server error.' : ($e->getMessage() ?: 'Request failed.'),
                $status
            );
        });
    })->create();

<?php

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

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::tooManyRequests($e->getMessage() ?: 'Too many requests.');
            }
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

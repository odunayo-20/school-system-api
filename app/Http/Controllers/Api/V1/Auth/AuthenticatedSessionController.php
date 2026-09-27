<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthenticationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;

class AuthenticatedSessionController extends Controller
{
    public function __construct(protected AuthenticationService $authentication) {}

    /**
     * Exchange credentials for an API token.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $token = $this->authentication->authenticate($request->credentials());

        $user = $token->accessToken->tokenable;

        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->expiryFor($token),
        ], 'Authenticated successfully.');
    }

    /**
     * Invalidate the token used for this request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->authentication->logout($request);

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    /**
     * The authenticated user's profile and authorization context.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    /**
     * When the issued token stops being accepted.
     *
     * Sanctum enforces the lifetime from config("sanctum.expiration") while validating
     * a token, and does not copy it onto the personal_access_tokens row, so the row
     * column would always be null. Report the lifetime that is actually enforced, and
     * store it on the row as well so it can be audited later.
     */
    protected function expiryFor(NewAccessToken $token): ?string
    {
        $minutes = config('sanctum.expiration');

        if (! $minutes) {
            return null;
        }

        $expiresAt = Carbon::now()->addMinutes((int) $minutes);

        $token->accessToken->forceFill(['expires_at' => $expiresAt])->save();

        return $expiresAt->toIso8601String();
    }
}

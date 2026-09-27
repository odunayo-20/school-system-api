<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthenticationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
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
}

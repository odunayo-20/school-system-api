<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Send a password reset link.
     *
     * The status code and message are identical whether or not the address belongs to
     * an account, so this endpoint cannot be used to discover which email addresses
     * are registered. Laravel's broker enforces the per-address throttle as well.
     */
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->email()]);

        return ApiResponse::success(
            null,
            'If the account exists, password reset instructions have been sent.'
        );
    }
}

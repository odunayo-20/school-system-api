<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class NewPasswordController extends Controller
{
    /**
     * Consume a password reset token and set a new password.
     *
     * The token is issued, stored hashed and time limited by Laravel's password reset
     * broker. A successful reset deletes the token so it cannot be replayed, and every
     * existing API token is revoked so a stolen session cannot outlive the reset.
     */
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        $credentials = $request->credentials();

        $status = Password::reset(
            [...$credentials, 'password_confirmation' => $credentials['password'], 'token' => $request->token()],
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('The password reset token is invalid or has expired.', 422, [
                'email' => [__($status)],
            ]);
        }

        return ApiResponse::success(null, 'Password has been reset.');
    }
}

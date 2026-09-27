<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication routes (v1)
|--------------------------------------------------------------------------
|
| Every route below is served under the /api/v1 prefix configured in
| bootstrap/app.php. Authentication is stateless: requests carry a Sanctum
| bearer token, never a session cookie.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    /*
     * Public endpoints. "throttle:login" protects credential guessing, and
     * "throttle:auth" additionally throttles password reset link generation.
     */
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('password.email');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('password.store');

    /*
     * Email verification. The link sent by Laravel's built-in notification points at
     * the signed route below, so the client never has to build the URL itself.
     *
     * Unverified accounts are NOT blocked from logging in: administrators provision
     * accounts by email, so requiring verification would lock out the very first Super
     * Admin. Sensitive endpoints opt in with the "verified" middleware instead.
     */
    Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'auth:api', 'active'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['auth:api', 'active'])
        ->name('verification.send');

    /*
     * Authenticated endpoints.
     */
    Route::middleware(['auth:api', 'active'])->group(function (): void {
        Route::get('me', [AuthenticatedSessionController::class, 'me'])->name('me');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

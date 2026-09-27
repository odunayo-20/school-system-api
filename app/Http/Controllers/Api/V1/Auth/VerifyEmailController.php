<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * The route is signed and carries an expiry, which is Laravel's standard signed URL
     * scheme. An invalid, expired or already used link is answered with 403 rather
     * than revealing anything about the account.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return ApiResponse::error('Email address is already verified.', 422);
        }

        if (! hash_equals((string) $request->user()->getKey(), (string) $request->route('id'))) {
            return ApiResponse::forbidden('Invalid verification link.');
        }

        if (! hash_equals(sha1($request->user()->getEmailForVerification()), (string) $request->route('hash'))) {
            return ApiResponse::forbidden('Invalid verification link.');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return ApiResponse::success(null, 'Email address verified.');
    }
}

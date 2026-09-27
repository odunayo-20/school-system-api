<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark the email address identified by the signed link as verified.
     *
     * The link is opened in a browser, not by the API client, so it carries no bearer
     * token. Trust comes from two independent checks performed by the framework and
     * this method: the "signed" middleware rejects a tampered or expired URL, and the
     * SHA-1 hash below must belong to the user id in the same URL.
     *
     * A mismatch is reported as 403 with a deliberately vague message, so the endpoint
     * cannot be used to probe which accounts exist.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $hash = (string) $request->route('hash');

        if (! ctype_digit($id)) {
            return ApiResponse::forbidden('Invalid verification link.');
        }

        $user = User::query()->find((int) $id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return ApiResponse::forbidden('Invalid verification link.');
        }

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::error('Email address is already verified.', 422);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return ApiResponse::success(null, 'Email address verified.');
    }
}

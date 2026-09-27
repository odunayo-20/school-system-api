<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Re-send the verification notification. Sending a second notification revokes the
     * previous link so only the newest one works.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return ApiResponse::error('Email address is already verified.', 422);
        }

        $request->user()->sendEmailVerificationNotification();

        return ApiResponse::success(null, 'Verification link sent.');
    }
}

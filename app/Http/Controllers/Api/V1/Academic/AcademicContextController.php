<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Services\Academic\AcademicContextService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * One read of "what year and term is this school in".
 *
 * Assembled by a service rather than a resource because the three parts are independent:
 * a school can be configured with no session, and a session can exist with no active term.
 * A client can therefore show an accurate state per level, and can tell an unconfigured
 * installation apart from an ordinary start of year.
 */
class AcademicContextController extends Controller
{
    public function __construct(protected AcademicContextService $context) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->context->current(), message: $this->message());
    }

    /**
     * A client that only wants to know whether setup is finished gets an unambiguous answer
     * in one field, without having to interpret three nullable objects.
     */
    protected function message(): string
    {
        return $this->context->isConfigured()
            ? 'Current academic context.'
            : 'The school academic setup is incomplete. A profile, a current session and a current term are all required.';
    }
}

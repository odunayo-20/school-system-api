<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\UpdateSchoolRequest;
use App\Http\Resources\Academic\SchoolResource;
use App\Services\Academic\SchoolConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The school profile.
 *
 * There is no store and no destroy. The profile is the configuration everything else hangs
 * off, so the same PUT both creates and amends it; see SchoolConfigurationService.
 */
class SchoolController extends Controller
{
    public function __construct(protected SchoolConfigurationService $school) {}

    /**
     * The profile, or a null payload on an installation that has not been configured yet.
     *
     * Answering 200 with data: null rather than 404 is deliberate. The URL is the singleton
     * itself, not a member of a collection, and "not configured yet" is the normal state of
     * a fresh installation rather than a client error. A client that polls this on start up
     * should be able to render a "finish setting up" prompt without treating a normal
     * condition as an exception, and it can tell the two apart because the message says
     * which it is.
     */
    public function show(): JsonResponse
    {
        $school = $this->school->profile();

        if (! $school) {
            return ApiResponse::nullData(
                'The school profile has not been configured yet. Send a PUT to /api/v1/school to create it.'
            );
        }

        return ApiResponse::success(new SchoolResource($school));
    }

    /**
     * Create the profile if it is absent, otherwise amend it.
     */
    public function update(UpdateSchoolRequest $request): JsonResponse
    {
        $school = $this->school->update($request->validated());

        return ApiResponse::success(
            new SchoolResource($school),
            'School profile saved.',
        );
    }
}

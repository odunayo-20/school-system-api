<?php

namespace App\Http\Controllers\Api\V1\Enrollment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\DecideEnrollmentRequest;
use App\Http\Requests\Enrollment\EnrollmentListRequest;
use App\Http\Requests\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Enrollment;
use App\Services\Enrollment\EnrollmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Student enrollment: the authoritative academic placement for one academic session.
 *
 * There is no destroy(). An enrollment is academic history that a future results,
 * attendance, promotion or report-card module will reference - the identical reasoning
 * Module 03, 04 and 05 already give their own records, made stronger here because more is
 * expected to hang off this one. `cancel()` is the record-preserving replacement for a delete:
 * a mistaken enrollment is voided in place, not erased.
 *
 * There is no PATCH, only PUT, and PUT touches only `enrollment_date` and `notes` - see
 * UpdateEnrollmentRequest. The placement itself (student, session, class, section) has no
 * mutation path at all once created; a transfer or class change, if this project ever needs
 * one, is a deliberate future operation, not a field on this endpoint.
 */
class EnrollmentController extends Controller
{
    public function __construct(protected EnrollmentService $enrollments) {}

    public function index(EnrollmentListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            EnrollmentResource::collection(
                $this->enrollments->paginate($request->filters())
            )
        );
    }

    /**
     * Place a student in a class and section for an academic session. Always ACTIVE - see
     * StoreEnrollmentRequest.
     */
    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = $this->enrollments->create($request->enrollmentAttributes());

        return ApiResponse::success(
            new EnrollmentResource($enrollment->load(['student', 'academicSession', 'schoolClass', 'section'])),
            'Enrollment created.',
            201,
        );
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        return ApiResponse::success(
            new EnrollmentResource($enrollment->load(['student', 'academicSession', 'schoolClass', 'section']))
        );
    }

    /**
     * Amend an active enrollment's date and notes. See UpdateEnrollmentRequest for what
     * cannot be reached this way - the placement itself, in particular.
     */
    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $updated = $this->enrollments->update($enrollment, $request->enrollmentAttributes());

        return ApiResponse::success(
            new EnrollmentResource($updated->load(['student', 'academicSession', 'schoolClass', 'section'])),
            'Enrollment updated.',
        );
    }

    /**
     * Record that the student left this placement before the session ended.
     */
    public function withdraw(DecideEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $updated = $this->enrollments->withdraw($enrollment, $request->notes());

        return ApiResponse::success(
            new EnrollmentResource($updated->load(['student', 'academicSession', 'schoolClass', 'section'])),
            'Enrollment marked as withdrawn.',
        );
    }

    /**
     * Void an enrollment that should not have been created. Replaces a delete - see the class
     * docblock.
     */
    public function cancel(DecideEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $updated = $this->enrollments->cancel($enrollment, $request->notes());

        return ApiResponse::success(
            new EnrollmentResource($updated->load(['student', 'academicSession', 'schoolClass', 'section'])),
            'Enrollment cancelled.',
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\StudentListRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\Student\StudentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The pupil roll: who is on it, and who has left it.
 *
 * There is no destroy(), and the reason is not "we have not built it yet" - it is that a
 * pupil is a child and a person, and the school does not delete them. They leave the roll by
 * status, and the record stays: a graduated pupil is still a graduate, a withdrawal is a
 * fact about a date, and a school that could delete a child's entire history with one call
 * would eventually do it by accident, or on request, or because a registrar mistyped an id.
 *
 * Module 03 left staff deletion out on narrower grounds - nothing referenced a staff record
 * yet, so a dependents guard would have been a check that could never fail. Here the guard
 * would be meaningful and the answer is still no. When the enrollment, attendance and
 * result tables arrive, their own restrictOnDelete keys become a second line of defence, but
 * they are not the reason this endpoint is absent.
 *
 * There is also no activate/deactivate pair, unlike staff. A pupil's lifecycle is one
 * orthogonal question - is this child currently on the roll - and it is amendable through
 * the same PUT as the name. Splitting it into two more endpoints would have meant two more
 * routes, two more permissions and a registrar making two calls to correct a typo in a
 * surname on a child who has just moved away.
 */
class StudentController extends Controller
{
    public function __construct(protected StudentService $students) {}

    public function index(StudentListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            StudentResource::collection(
                $this->students->paginate($request->filters())
            )
        );
    }

    /**
     * Add a pupil to the roll.
     *
     * No account is created, so there is no credential in the request and none in the
     * response. account_status in the payload is null until the portal module provisions a
     * login, which is the intended state rather than a missing feature.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new StudentResource($this->students->create($request->studentAttributes())),
            'Student added to the roll. No login account was created.',
            201,
        );
    }

    public function show(Student $student): JsonResponse
    {
        return ApiResponse::success(
            new StudentResource($student->load('user'))
        );
    }

    /**
     * Amend a pupil record. PUT only, and a whole-record write: first_name is required so a
     * client cannot half-update the record by omitting it.
     *
     * user_id is not amendable here, and neither is any account field - see
     * UpdateStudentRequest for why accepting one would be a privilege escalation.
     */
    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $updated = $this->students->update(
            $student,
            $request->studentAttributes(),
            $request->requestedStatus(),
        );

        return ApiResponse::success(
            new StudentResource($updated),
            'Student updated.',
        );
    }
}

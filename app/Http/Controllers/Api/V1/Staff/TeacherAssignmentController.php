<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\DecideTeacherAssignmentRequest;
use App\Http\Requests\Staff\StoreTeacherAssignmentRequest;
use App\Http\Requests\Staff\TeacherAssignmentListRequest;
use App\Http\Requests\Staff\UpdateTeacherAssignmentRequest;
use App\Http\Resources\TeacherAssignmentResource;
use App\Models\TeacherAssignment;
use App\Services\Staff\TeacherAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Teacher, class and subject assignment: which teaching staff member is responsible for a
 * class subject, for one academic session.
 *
 * There is no destroy(). An assignment is academic history that a future assessment, score
 * and result chain will reference - the identical reasoning Module 06 and Module 07 already
 * give their own anchor records, made concrete here because "who taught this subject when" is
 * exactly the question a future report card needs answered. `cancel()` is the
 * record-preserving replacement for a delete: a mistaken assignment is voided in place, not
 * erased.
 *
 * There is no PATCH, only PUT, and PUT touches only `notes` - see
 * UpdateTeacherAssignmentRequest. The assignment itself (teacher, class subject, session) has
 * no mutation path at all once created; reassigning a class subject to a different teacher is
 * end() the current assignment, then a fresh POST for the new one - composing two existing
 * primitives rather than a third, coupled "reassign" operation this module does not build.
 */
class TeacherAssignmentController extends Controller
{
    protected const WITH = ['teachingStaff.user', 'classSubject.schoolClass', 'classSubject.subject', 'academicSession'];

    public function __construct(protected TeacherAssignmentService $assignments) {}

    public function index(TeacherAssignmentListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            TeacherAssignmentResource::collection(
                $this->assignments->paginate($request->filters())
            )
        );
    }

    /**
     * Assign a teacher to a class subject for a session. Always ACTIVE - see
     * StoreTeacherAssignmentRequest.
     */
    public function store(StoreTeacherAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignments->create($request->assignmentAttributes());

        return ApiResponse::success(
            new TeacherAssignmentResource($assignment->load(self::WITH)),
            'Teacher assignment created.',
            201,
        );
    }

    public function show(TeacherAssignment $teacherAssignment): JsonResponse
    {
        return ApiResponse::success(
            new TeacherAssignmentResource($teacherAssignment->load(self::WITH))
        );
    }

    /**
     * Amend an active assignment's notes. See UpdateTeacherAssignmentRequest for what cannot
     * be reached this way - the assignment itself, in particular.
     */
    public function update(UpdateTeacherAssignmentRequest $request, TeacherAssignment $teacherAssignment): JsonResponse
    {
        $updated = $this->assignments->update($teacherAssignment, $request->assignmentAttributes());

        return ApiResponse::success(
            new TeacherAssignmentResource($updated->load(self::WITH)),
            'Teacher assignment updated.',
        );
    }

    /**
     * Record that the teacher stopped teaching this class subject this session.
     */
    public function end(DecideTeacherAssignmentRequest $request, TeacherAssignment $teacherAssignment): JsonResponse
    {
        $updated = $this->assignments->end($teacherAssignment, $request->notes());

        return ApiResponse::success(
            new TeacherAssignmentResource($updated->load(self::WITH)),
            'Teacher assignment ended.',
        );
    }

    /**
     * Void an assignment that should not have been created. Replaces a delete - see the class
     * docblock.
     */
    public function cancel(DecideTeacherAssignmentRequest $request, TeacherAssignment $teacherAssignment): JsonResponse
    {
        $updated = $this->assignments->cancel($teacherAssignment, $request->notes());

        return ApiResponse::success(
            new TeacherAssignmentResource($updated->load(self::WITH)),
            'Teacher assignment cancelled.',
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Subject;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\SubjectListRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Services\Subject\SubjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The subject catalogue: Mathematics, English Language, Biology.
 *
 * DELETE exists, unlike Staff/Student/Admission/Enrollment. A subject is structurally a
 * catalogue entry like a class level, not a person or a one-shot decision, and
 * SubjectService::deleteSubject() guards it exactly as
 * AcademicStructureService::deleteClassLevel() guards its own: refused while any class still
 * offers it, whatever that offering's own status.
 */
class SubjectController extends Controller
{
    public function __construct(protected SubjectService $subjects) {}

    public function index(SubjectListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            SubjectResource::collection(
                $this->subjects->paginateSubjects($request->filters())
            )
        );
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = $this->subjects->createSubject($request->catalogAttributes());

        return ApiResponse::success(new SubjectResource($subject), 'Subject created.', 201);
    }

    public function show(Subject $subject): JsonResponse
    {
        return ApiResponse::success(
            new SubjectResource($subject->loadCount('classSubjects'))
        );
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $updated = $this->subjects->updateSubject($subject, $request->catalogAttributes());

        return ApiResponse::success(new SubjectResource($updated), 'Subject updated.');
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $this->subjects->deleteSubject($subject);

        return ApiResponse::success(null, 'Subject deleted.');
    }
}

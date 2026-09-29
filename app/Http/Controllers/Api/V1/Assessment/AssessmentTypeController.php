<?php

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\AssessmentTypeListRequest;
use App\Http\Requests\Assessment\StoreAssessmentTypeRequest;
use App\Http\Requests\Assessment\UpdateAssessmentTypeRequest;
use App\Http\Resources\AssessmentTypeResource;
use App\Models\AssessmentType;
use App\Services\Assessment\AssessmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The assessment category catalogue: CA, Test, Examination, Project.
 *
 * DELETE exists, unlike Assessment. A category is structurally a catalogue entry like a
 * subject or a class level, not a person or a one-shot decision, and
 * AssessmentService::deleteAssessmentType() guards it exactly as
 * SubjectService::deleteSubject() guards its own: refused while any assessment still uses it.
 */
class AssessmentTypeController extends Controller
{
    public function __construct(protected AssessmentService $assessments) {}

    public function index(AssessmentTypeListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            AssessmentTypeResource::collection(
                $this->assessments->paginateAssessmentTypes($request->filters())
            )
        );
    }

    public function store(StoreAssessmentTypeRequest $request): JsonResponse
    {
        $assessmentType = $this->assessments->createAssessmentType($request->catalogAttributes());

        return ApiResponse::success(new AssessmentTypeResource($assessmentType), 'Assessment type created.', 201);
    }

    public function show(AssessmentType $assessmentType): JsonResponse
    {
        return ApiResponse::success(
            new AssessmentTypeResource($assessmentType->loadCount('assessments'))
        );
    }

    public function update(UpdateAssessmentTypeRequest $request, AssessmentType $assessmentType): JsonResponse
    {
        $updated = $this->assessments->updateAssessmentType($assessmentType, $request->catalogAttributes());

        return ApiResponse::success(new AssessmentTypeResource($updated), 'Assessment type updated.');
    }

    public function destroy(AssessmentType $assessmentType): JsonResponse
    {
        $this->assessments->deleteAssessmentType($assessmentType);

        return ApiResponse::success(null, 'Assessment type deleted.');
    }
}

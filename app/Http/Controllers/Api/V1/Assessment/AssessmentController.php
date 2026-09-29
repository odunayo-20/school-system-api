<?php

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\AssessmentListRequest;
use App\Http\Requests\Assessment\StoreAssessmentRequest;
use App\Http\Requests\Assessment\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Services\Assessment\AssessmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Configured assessments: which class subject and term each one belongs to, its category,
 * name, score ceiling and weight.
 *
 * There is no destroy(). An assessment is the anchor a future score will reference - the
 * identical reasoning Module 06, Module 07 and Module 08 already give their own anchor
 * records (Enrollment, ClassSubject, TeacherAssignment), made concrete here because "what was
 * this assessment worth, and out of how much" is exactly the question a future score and
 * result chain needs answered forever. Retiring one that should not have been configured is
 * `status: INACTIVE` through the ordinary update, not a delete.
 *
 * There is no PATCH, only PUT, and PUT never touches class_subject_id, term_id or
 * assessment_type_id - see UpdateAssessmentRequest. The academic context an assessment names
 * has no mutation path at all once created; configuring the wrong one is retired via status
 * and a fresh POST replaces it, composing two existing primitives rather than a third,
 * coupled "reassign" operation this module does not build.
 */
class AssessmentController extends Controller
{
    protected const WITH = ['classSubject.schoolClass', 'classSubject.subject', 'term', 'assessmentType'];

    public function __construct(protected AssessmentService $assessments) {}

    public function index(AssessmentListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            AssessmentResource::collection(
                $this->assessments->paginateAssessments($request->filters())
            )
        );
    }

    public function store(StoreAssessmentRequest $request): JsonResponse
    {
        $assessment = $this->assessments->create($request->assessmentAttributes());

        return ApiResponse::success(
            new AssessmentResource($assessment->load(self::WITH)),
            'Assessment created.',
            201,
        );
    }

    public function show(Assessment $assessment): JsonResponse
    {
        return ApiResponse::success(
            new AssessmentResource($assessment->load(self::WITH))
        );
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment): JsonResponse
    {
        $updated = $this->assessments->update($assessment, $request->assessmentAttributes());

        return ApiResponse::success(
            new AssessmentResource($updated->load(self::WITH)),
            'Assessment updated.',
        );
    }
}

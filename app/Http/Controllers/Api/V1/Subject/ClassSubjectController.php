<?php

namespace App\Http\Controllers\Api\V1\Subject;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\ClassSubjectListRequest;
use App\Http\Requests\Subject\StoreClassSubjectRequest;
use App\Http\Requests\Subject\UpdateClassSubjectRequest;
use App\Http\Resources\ClassSubjectResource;
use App\Models\ClassSubject;
use App\Services\Subject\SubjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Class-subject offerings: which classes teach which subjects.
 *
 * There is no destroy(). A class subject is the anchor a future teacher assignment and a
 * future assessment will reference, so it is never deleted - the identical posture Module 06
 * takes toward enrollments. "Removing a subject from a class" is `status: INACTIVE` through
 * the ordinary update, not a delete, and not a dedicated endpoint either: unlike an
 * admission or an enrollment ending, deactivating an offering is a freely reversible toggle
 * with no side effect beyond the record itself, so it needs no workflow endpoint of its own.
 */
class ClassSubjectController extends Controller
{
    public function __construct(protected SubjectService $subjects) {}

    public function index(ClassSubjectListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            ClassSubjectResource::collection(
                $this->subjects->paginateClassSubjects($request->filters())
            )
        );
    }

    public function store(StoreClassSubjectRequest $request): JsonResponse
    {
        $classSubject = $this->subjects->createClassSubject($request->classSubjectAttributes());

        return ApiResponse::success(
            new ClassSubjectResource($classSubject->load(['schoolClass', 'subject'])),
            'Class subject created.',
            201,
        );
    }

    public function show(ClassSubject $classSubject): JsonResponse
    {
        return ApiResponse::success(
            new ClassSubjectResource($classSubject->load(['schoolClass', 'subject']))
        );
    }

    public function update(UpdateClassSubjectRequest $request, ClassSubject $classSubject): JsonResponse
    {
        $updated = $this->subjects->updateClassSubject($classSubject, $request->classSubjectAttributes());

        return ApiResponse::success(
            new ClassSubjectResource($updated->load(['schoolClass', 'subject'])),
            'Class subject updated.',
        );
    }
}

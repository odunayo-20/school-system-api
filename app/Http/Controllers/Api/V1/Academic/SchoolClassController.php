<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ListSchoolClassesRequest;
use App\Http\Requests\Academic\StoreSchoolClassRequest;
use App\Http\Requests\Academic\UpdateSchoolClassRequest;
use App\Http\Resources\Academic\SchoolClassResource;
use App\Models\SchoolClass;
use App\Services\Academic\AcademicStructureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SchoolClassController extends Controller
{
    public function __construct(protected AcademicStructureService $structure) {}

    /**
     * Classes across the whole school, or filtered to one class level.
     *
     * The level is a query parameter rather than a nested route so that one endpoint serves
     * both the full structure screen and the "which classes are in this level" picker.
     */
    public function index(ListSchoolClassesRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            SchoolClassResource::collection(
                $this->structure->paginateClasses($request->filters())
            )
        );
    }

    public function store(StoreSchoolClassRequest $request): JsonResponse
    {
        $schoolClass = $this->structure->createClass($request->catalogAttributes());

        return ApiResponse::success(
            new SchoolClassResource($schoolClass->load('classLevel')),
            'Class created.',
            201,
        );
    }

    public function show(SchoolClass $schoolClass): JsonResponse
    {
        return ApiResponse::success(
            new SchoolClassResource($schoolClass->load('classLevel')->loadCount('sections'))
        );
    }

    public function update(UpdateSchoolClassRequest $request, SchoolClass $schoolClass): JsonResponse
    {
        $model = $this->structure->updateClass(
            $schoolClass,
            $request->catalogAttributes(),
        );

        return ApiResponse::success(
            new SchoolClassResource($model->load('classLevel')),
            'Class updated.',
        );
    }

    public function destroy(SchoolClass $schoolClass): JsonResponse
    {
        $this->structure->deleteClass($schoolClass);

        return ApiResponse::success(null, 'Class deleted.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ListClassLevelsRequest;
use App\Http\Requests\Academic\StoreClassLevelRequest;
use App\Http\Requests\Academic\UpdateClassLevelRequest;
use App\Http\Resources\Academic\ClassLevelResource;
use App\Models\ClassLevel;
use App\Services\Academic\AcademicStructureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Class levels: the top of the academic structure (Nursery, Primary, and so on).
 *
 * Two endpoints deliberately have no counterpart here. There is no "reorder" endpoint:
 * sort_order is amended through the ordinary update, so a drag-and-drop reorder in a client
 * is a sequence of ordinary PATCHes and needs no privileged operation of its own. There is
 * also no separate "archive" endpoint: retiring a record is a status value, and adding a
 * second way to set it would leave two code paths for one meaning.
 */
class ClassLevelController extends Controller
{
    public function __construct(protected AcademicStructureService $structure) {}

    public function index(ListClassLevelsRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            ClassLevelResource::collection(
                $this->structure->paginateClassLevels($request->filters())
            )
        );
    }

    public function store(StoreClassLevelRequest $request): JsonResponse
    {
        $classLevel = $this->structure->createClassLevel($request->catalogAttributes());

        return ApiResponse::success(new ClassLevelResource($classLevel), 'Class level created.', 201);
    }

    public function show(ClassLevel $classLevel): JsonResponse
    {
        return ApiResponse::success(
            new ClassLevelResource($classLevel->loadCount('classes'))
        );
    }

    public function update(UpdateClassLevelRequest $request, ClassLevel $classLevel): JsonResponse
    {
        $model = $this->structure->updateClassLevel(
            $classLevel,
            $request->catalogAttributes(),
        );

        return ApiResponse::success(new ClassLevelResource($model), 'Class level updated.');
    }

    public function destroy(ClassLevel $classLevel): JsonResponse
    {
        $this->structure->deleteClassLevel($classLevel);

        return ApiResponse::success(null, 'Class level deleted.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ListSectionsRequest;
use App\Http\Requests\Academic\StoreSectionRequest;
use App\Http\Requests\Academic\UpdateSectionRequest;
use App\Http\Resources\Academic\SectionResource;
use App\Models\Section;
use App\Services\Academic\AcademicStructureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    public function __construct(protected AcademicStructureService $structure) {}

    /**
     * Sections across the whole school, or filtered to one class.
     *
     * Sections belong to a class rather than to a school-wide catalogue, so the class is
     * available as a filter but the default view spans every class: the screen that matters
     * day to day is "every section in the school", and per-class filtering is a refinement
     * of it.
     */
    public function index(ListSectionsRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            SectionResource::collection(
                $this->structure->paginateSections($request->filters())
            )
        );
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $section = $this->structure->createSection($request->catalogAttributes());

        return ApiResponse::success(
            new SectionResource($section->load('schoolClass')),
            'Section created.',
            201,
        );
    }

    public function show(Section $section): JsonResponse
    {
        return ApiResponse::success(
            new SectionResource($section->load('schoolClass'))
        );
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $model = $this->structure->updateSection(
            $section,
            $request->catalogAttributes(),
        );

        return ApiResponse::success(
            new SectionResource($model->load('schoolClass')),
            'Section updated.',
        );
    }

    public function destroy(Section $section): JsonResponse
    {
        $this->structure->deleteSection($section);

        return ApiResponse::success(null, 'Section deleted.');
    }
}

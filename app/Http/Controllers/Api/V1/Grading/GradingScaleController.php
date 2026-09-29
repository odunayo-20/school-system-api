<?php

namespace App\Http\Controllers\Api\V1\Grading;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\CalculateGradingScaleRequest;
use App\Http\Requests\Grading\GradingScaleListRequest;
use App\Http\Requests\Grading\StoreGradingScaleRequest;
use App\Http\Requests\Grading\UpdateGradingScaleRequest;
use App\Http\Resources\GradingScaleItemResource;
use App\Http\Resources\GradingScaleResource;
use App\Models\GradingScale;
use App\Services\Grading\GradingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Grading scales: reusable, class-level-scoped grading schemes, and the percentage bands that
 * make each one up.
 *
 * There is no destroy(). A scale is exactly the kind of academic-policy anchor a future
 * grading/result compilation module will reference to interpret a historical result - the
 * identical posture Module 06 through Module 10 already take toward their own anchor records.
 * Retiring one that should no longer be used is `status: INACTIVE`/`ARCHIVED` through the
 * ordinary update, not a delete.
 *
 * There is no PATCH, only PUT, and PUT never touches class_level_id - see
 * UpdateGradingScaleRequest. The class level a scale applies to has no mutation path at all
 * once created.
 *
 * calculate() is a read-only, side-effect-free preview: percentage in, grade information out.
 * It never creates, amends or reads a Score or any future Result - see GradingService's own
 * docblock.
 */
class GradingScaleController extends Controller
{
    public function __construct(protected GradingService $grading) {}

    public function index(GradingScaleListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            GradingScaleResource::collection(
                $this->grading->paginate($request->filters())
            )
        );
    }

    public function store(StoreGradingScaleRequest $request): JsonResponse
    {
        $scale = $this->grading->create($request->gradingScaleAttributes());

        return ApiResponse::success(
            new GradingScaleResource($scale->load('classLevel')),
            'Grading scale created.',
            201,
        );
    }

    public function show(GradingScale $gradingScale): JsonResponse
    {
        return ApiResponse::success(
            new GradingScaleResource($gradingScale->load(['classLevel', 'items']))
        );
    }

    public function update(UpdateGradingScaleRequest $request, GradingScale $gradingScale): JsonResponse
    {
        $updated = $this->grading->update($gradingScale, $request->gradingScaleAttributes());

        return ApiResponse::success(
            new GradingScaleResource($updated->load('classLevel')),
            'Grading scale updated.',
        );
    }

    /**
     * Percentage in, grade information out. Null grade fields, not a 404 or a 422, when no
     * band in this scale covers the given percentage - see GradingService::calculate().
     */
    public function calculate(CalculateGradingScaleRequest $request, GradingScale $gradingScale): JsonResponse
    {
        $gradingScale->loadMissing('items');

        $item = $this->grading->calculate($gradingScale, $request->percentage());

        return ApiResponse::success([
            'percentage' => $request->percentage(),
            'grade' => $item?->grade,
            'grade_point' => $item?->grade_point,
            'remark' => $item?->remark,
            'matched_band' => $item ? new GradingScaleItemResource($item) : null,
        ]);
    }
}

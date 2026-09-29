<?php

namespace App\Http\Controllers\Api\V1\Promotion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\PromoteStudentRequest;
use App\Http\Requests\Promotion\PromotionListRequest;
use App\Http\Resources\Promotion\PromotionResource;
use App\Models\Promotion;
use App\Models\Student;
use App\Services\Promotion\PromotionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Student promotion: recording what happens to a student's placement going into a new
 * academic session - PROMOTED, RETAINED, GRADUATED or NOT_ELIGIBLE.
 *
 * There is no destroy() and no update(). A promotion decision is a one-shot historical fact,
 * exactly like an Admission's decision - once recorded, it is never amended or reversed
 * through this API. There is also no bulk endpoint: see the Module 15 audit for why bulk
 * promotion is deliberately deferred rather than built speculatively.
 */
class PromotionController extends Controller
{
    public function __construct(protected PromotionService $promotions) {}

    public function index(PromotionListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            PromotionResource::collection(
                $this->promotions->paginate($request->filters())
            )
        );
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return ApiResponse::success(new PromotionResource($this->promotions->view($promotion)));
    }

    /**
     * Record a promotion decision for one student.
     */
    public function store(PromoteStudentRequest $request, Student $student): JsonResponse
    {
        $promotion = $this->promotions->promote($student, $request->promotionAttributes(), $request->user());

        return ApiResponse::success(
            new PromotionResource($promotion),
            'Promotion decision recorded.',
            201,
        );
    }
}

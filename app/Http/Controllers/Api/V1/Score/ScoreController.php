<?php

namespace App\Http\Controllers\Api\V1\Score;

use App\Http\Controllers\Controller;
use App\Http\Requests\Score\ScoreListRequest;
use App\Http\Requests\Score\StoreScoreBulkRequest;
use App\Http\Requests\Score\StoreScoreRequest;
use App\Http\Requests\Score\UpdateScoreRequest;
use App\Http\Resources\ScoreResource;
use App\Models\Score;
use App\Services\Score\ScoreService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recording and amending what a specific student obtained against a specific configured
 * assessment.
 *
 * There is no destroy(). A score is exactly the kind of academic-history anchor a future
 * grading/result module will reference, the identical posture Module 06, Module 07, Module 08
 * and Module 09 already take toward their own anchor records. A mistaken score is corrected
 * through the ordinary PUT, not erased.
 *
 * There is no PATCH, only PUT, and PUT never touches assessment_id or enrollment_id - see
 * UpdateScoreRequest. The pair a score names has no mutation path at all once created.
 *
 * Every read and write here is additionally scoped to the acting user by
 * ScoreService - a holder of scores.create/scores.view is not thereby entitled to every score
 * in the school; see the service's own docblock.
 */
class ScoreController extends Controller
{
    protected const WITH = [
        'assessment.classSubject.schoolClass',
        'assessment.classSubject.subject',
        'assessment.term.academicSession',
        'assessment.assessmentType',
        'enrollment.student.user',
        'enrollment.academicSession',
        'enrollment.schoolClass',
        'enrollment.section',
    ];

    public function __construct(protected ScoreService $scores) {}

    public function index(ScoreListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            ScoreResource::collection(
                $this->scores->paginate($request->filters(), $request->user())
            )
        );
    }

    /**
     * Record a single student's mark against an assessment.
     */
    public function store(StoreScoreRequest $request): JsonResponse
    {
        $score = $this->scores->create($request->scoreAttributes(), $request->user());

        return ApiResponse::success(
            new ScoreResource($score->load(self::WITH)),
            'Score recorded.',
            201,
        );
    }

    /**
     * Record a batch of scores against one assessment - a class roster entered in one call.
     */
    public function bulkStore(StoreScoreBulkRequest $request): JsonResponse
    {
        $scores = $this->scores->createBulk($request->bulkAttributes(), $request->user());

        return ApiResponse::success(
            ScoreResource::collection(collect($scores)->each->load(self::WITH)),
            'Scores recorded.',
            201,
        );
    }

    public function show(Request $request, Score $score): JsonResponse
    {
        $score = $this->scores->view($score, $request->user());

        return ApiResponse::success(new ScoreResource($score));
    }

    /**
     * Amend a score's mark or remarks. See UpdateScoreRequest for what cannot be reached this
     * way - the pair it names, in particular.
     */
    public function update(UpdateScoreRequest $request, Score $score): JsonResponse
    {
        $updated = $this->scores->update($score, $request->scoreAttributes(), $request->user());

        return ApiResponse::success(
            new ScoreResource($updated->load(self::WITH)),
            'Score updated.',
        );
    }
}

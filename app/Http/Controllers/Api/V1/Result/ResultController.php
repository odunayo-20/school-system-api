<?php

namespace App\Http\Controllers\Api\V1\Result;

use App\Http\Controllers\Controller;
use App\Http\Requests\Result\CompileResultBulkRequest;
use App\Http\Requests\Result\CompileResultRequest;
use App\Http\Requests\Result\ResultListRequest;
use App\Http\Resources\ResultResource;
use App\Models\Result;
use App\Services\Result\ResultService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Result compilation: transforming raw assessment scores into a compiled subject result for
 * one enrollment, one class subject and one term.
 *
 * There is no destroy() and no plain store()/update(). A result is written only through
 * compile()/bulkCompile() - a calculation, never a raw create or amend of client-supplied
 * values - and never deleted, matching every academic-history anchor introduced since Module
 * 05. Recompiling (the same operation, run again after a score correction) is how an existing
 * result changes; see ResultService for the idempotent upsert this relies on.
 *
 * Every read and write here is additionally scoped to the acting user by ResultService - a
 * holder of results.compile is not thereby entitled to compile every class subject in the
 * school; see the service's own docblock.
 */
class ResultController extends Controller
{
    protected const WITH = [
        'enrollment.student.user',
        'enrollment.academicSession',
        'enrollment.schoolClass',
        'enrollment.section',
        'classSubject.schoolClass',
        'classSubject.subject',
        'term.academicSession',
    ];

    public function __construct(protected ResultService $results) {}

    public function index(ResultListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            ResultResource::collection(
                $this->results->paginate($request->filters(), $request->user())
            )
        );
    }

    /**
     * Compile (or recompile) one enrollment's result. 200, not 201: this is a calculation
     * that may create or update the underlying row depending on whether one already exists -
     * see ResultService::persist() - and the client is always asking for the same thing
     * either way, the current compiled result.
     */
    public function compile(CompileResultRequest $request): JsonResponse
    {
        $result = $this->results->compile($request->compileAttributes(), $request->user());

        return ApiResponse::success(
            new ResultResource($result->load(self::WITH)),
            'Result compiled.',
        );
    }

    /**
     * Compile (or recompile) every currently active enrollment in a class subject's class,
     * for one term. Each student's outcome is reported independently - see
     * ResultService::compileClassSubject() for why this is not all-or-nothing.
     */
    public function bulkCompile(CompileResultBulkRequest $request): JsonResponse
    {
        $outcomes = $this->results->compileClassSubject($request->bulkCompileAttributes(), $request->user());

        $data = collect($outcomes)->map(fn (array $outcome): array => [
            'enrollment_id' => $outcome['enrollment_id'],
            'result' => $outcome['result'] ? new ResultResource($outcome['result']->load(self::WITH)) : null,
            'error' => $outcome['error'],
        ])->all();

        return ApiResponse::success($data, 'Results compiled.');
    }

    public function show(Request $request, Result $result): JsonResponse
    {
        $result = $this->results->view($result, $request->user());

        return ApiResponse::success(new ResultResource($result));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\ReportCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportCard\ReportCardListRequest;
use App\Http\Resources\ReportCard\ReportCardResource;
use App\Http\Resources\ReportCard\ReportCardSummaryResource;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Term;
use App\Services\ReportCard\ReportCardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A read-only presentation of a student's finalized academic results - never a second
 * calculation of them. Every value rendered here is read directly from Module 12's Result
 * rows as Module 13 left them; see ReportCardService's own docblock for the full reasoning.
 *
 * Two endpoints only, both GET: the full card for one enrollment and one term, and a
 * lightweight history list across a student's whole enrollment record. Nothing here writes
 * anything - a report card has no lifecycle of its own to mutate (see the Module 14 audit
 * §6 for why it deliberately does not duplicate Module 13's workflow).
 */
class ReportCardController extends Controller
{
    public function __construct(protected ReportCardService $reportCards) {}

    /**
     * The full report card for one enrollment, one term.
     */
    public function show(Request $request, Enrollment $enrollment, Term $term): JsonResponse
    {
        $data = $this->reportCards->forEnrollmentAndTerm($enrollment, $term, $request->user());

        return ApiResponse::success(new ReportCardResource($data));
    }

    /**
     * Every term across this student's enrollment history with at least one finalized result,
     * newest first.
     */
    public function forStudent(ReportCardListRequest $request, Student $student): JsonResponse
    {
        return ApiResponse::paginated(
            ReportCardSummaryResource::collection(
                $this->reportCards->paginateForStudent($student, $request->user(), $request->filters())
            )
        );
    }
}

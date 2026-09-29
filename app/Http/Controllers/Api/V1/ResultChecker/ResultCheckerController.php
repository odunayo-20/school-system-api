<?php

namespace App\Http\Controllers\Api\V1\ResultChecker;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResultChecker\ResultCheckRequest;
use App\Http\Resources\ReportCard\ReportCardResource;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Term;
use App\Services\ReportCard\ReportCardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A public, unauthenticated way to retrieve a student's already-PUBLISHED (or LOCKED) result -
 * for a parent or guardian who holds no portal login, the audience Module 14's own report-card
 * docs already anticipated this module for.
 *
 * NOT a second result-calculation engine and NOT a second report-card presentation: every
 * figure returned here comes straight from ReportCardService::forEnrollmentAndTermUnguarded(),
 * the exact same lookup and the exact same PUBLISHED/LOCKED availability gate the authenticated
 * Module 14 endpoints already use, rendered through the same ReportCardResource.
 *
 * The credential is student_number + date_of_birth - information a parent already has, not a
 * PIN this project would have to generate, store and somehow hand to them (there is no
 * notification/printing module in scope to do that). Every failure - unknown student number,
 * wrong date of birth, no enrollment in the term's session, or no finalized result yet -
 * answers with the identical 404 `abort()` already produces, so a caller can never tell which
 * of those four is true. That is deliberate: distinguishing them would let an attacker enumerate
 * valid student numbers one guess at a time.
 */
class ResultCheckerController extends Controller
{
    public function __construct(protected ReportCardService $reportCards) {}

    public function check(ResultCheckRequest $request): JsonResponse
    {
        $term = Term::query()->findOrFail($request->integer('term_id'));

        $student = Student::query()
            ->where('student_number', (string) $request->string('student_number'))
            ->whereDate('date_of_birth', $request->date('date_of_birth'))
            ->first();

        if (! $student) {
            abort(404);
        }

        $enrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_session_id', $term->academic_session_id)
            ->first();

        if (! $enrollment) {
            abort(404);
        }

        $data = $this->reportCards->forEnrollmentAndTermUnguarded($enrollment, $term);

        return ApiResponse::success(new ReportCardResource($data));
    }
}

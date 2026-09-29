<?php

namespace App\Services\ReportCard;

use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\Result;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A structured, read-only presentation of a student's finalized subject results for one
 * enrollment and one term.
 *
 * THIS IS NOT A SECOND RESULT-CALCULATION ENGINE. Every percentage, grade, grade point and
 * remark rendered here is read directly off Module 12's own `Result` rows, exactly as Module
 * 13 left them - nothing here re-derives a score, re-applies a grading scale, or re-weights an
 * assessment. The only arithmetic this class performs is a plain, documented aggregate over
 * ALREADY-authoritative per-subject values (an arithmetic mean of `percentage`, and separately
 * of `grade_point`) purely for presentation - see summarize().
 *
 * AVAILABILITY THRESHOLD: only `PUBLISHED` and `LOCKED` results are ever included. A result
 * still `INCOMPLETE`, `COMPILED`, `SUBMITTED` or `APPROVED` never appears in a report card -
 * Module 13's own workflow, unmodified, is the sole gate for what counts as "finalized" here.
 * See the Module 14 audit §3 for why this threshold is applied uniformly to every caller,
 * including SUPER_ADMIN/ADMIN, rather than offering a separate "preview" mode.
 */
class ReportCardService
{
    /**
     * @var list<string>
     */
    protected const AVAILABLE_STATUSES = [ResultStatus::PUBLISHED->value, ResultStatus::LOCKED->value];

    /**
     * @var list<string>
     */
    protected const WITH = [
        'enrollment.student',
        'enrollment.academicSession',
        'enrollment.schoolClass',
        'enrollment.section',
        'term.academicSession',
    ];

    /**
     * The full report card for one enrollment, one term: every finalized subject result,
     * sorted for display, plus a summary. Throws a plain 404 (via abort()) when the
     * enrollment/term pairing yields no finalized results at all - whether because nothing has
     * been published yet, or because the two simply do not belong to the same academic
     * session (Result's own unique index already makes that combination impossible to have
     * produced any row in the first place, so no separate mismatch check is needed - see the
     * audit §8).
     *
     * @return array{enrollment: Enrollment, term: Term, results: Collection<int, Result>, summary: array<string, mixed>}
     */
    public function forEnrollmentAndTerm(Enrollment $enrollment, Term $term, User $user): array
    {
        $this->assertEnrollmentViewable($enrollment, $user);

        $results = Result::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('term_id', $term->id)
            ->whereIn('status', self::AVAILABLE_STATUSES)
            ->with(['classSubject.subject', 'classSubject.schoolClass'])
            ->get();

        if ($results->isEmpty()) {
            abort(404);
        }

        $sorted = $this->sortBySubject($results);

        return [
            'enrollment' => $enrollment->loadMissing(['student', 'academicSession', 'schoolClass', 'section']),
            'term' => $term->loadMissing('academicSession'),
            'results' => $sorted,
            'summary' => $this->summarize($sorted),
        ];
    }

    /**
     * Every (enrollment, term) pair across this student's whole enrollment history that has at
     * least one finalized result, newest first, as a lightweight summary row per pair - not
     * the full subject breakdown, which a client fetches per pair through
     * forEnrollmentAndTerm().
     *
     * Paginated in PHP rather than at the database level. A student's entire report-card
     * history is inherently small - bounded by (terms per session) x (sessions enrolled), a
     * few dozen rows at the very most over a whole school career - so grouping and paginating
     * the already-small, already-fetched result set here is simpler and more portable across
     * this project's four supported drivers than a GROUP BY combined with a COUNT-for-pagination
     * query, for a dataset this endpoint will never see at a scale where that would matter.
     *
     * N+1-free: one query for this student's enrollment ids, one for every matching Result row
     * (a handful of columns only), and two bulk lookups (Enrollment, Term) scoped to exactly
     * the ids appearing on the returned page - never per-row.
     *
     * @param  array{per_page?: int}  $filters
     */
    public function paginateForStudent(Student $student, User $user, array $filters): LengthAwarePaginator
    {
        $this->assertStudentViewable($student, $user);

        $enrollmentIds = Enrollment::query()->where('student_id', $student->id)->pluck('id');

        $perPage = $filters['per_page'] ?? 15;
        $page = LengthAwarePaginator::resolveCurrentPage();

        if ($enrollmentIds->isEmpty()) {
            return new LengthAwarePaginator([], 0, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);
        }

        $groups = Result::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('status', self::AVAILABLE_STATUSES)
            ->get(['id', 'enrollment_id', 'term_id', 'percentage', 'grade_point'])
            ->groupBy(fn (Result $result): string => "{$result->enrollment_id}:{$result->term_id}")
            ->map(fn (Collection $rows): array => [
                'enrollment_id' => $rows->first()->enrollment_id,
                'term_id' => $rows->first()->term_id,
                'latest_result_id' => $rows->max('id'),
                ...$this->summarize($rows),
            ])
            ->sortByDesc('latest_result_id')
            ->values();

        $slice = $groups->forPage($page, $perPage)->values();

        $enrollments = Enrollment::query()
            ->whereIn('id', $slice->pluck('enrollment_id'))
            ->with(['student', 'academicSession', 'schoolClass', 'section'])
            ->get()
            ->keyBy('id');

        $terms = Term::query()
            ->whereIn('id', $slice->pluck('term_id'))
            ->with('academicSession')
            ->get()
            ->keyBy('id');

        $items = $slice->map(fn (array $row): array => [
            ...$row,
            'enrollment' => $enrollments->get($row['enrollment_id']),
            'term' => $terms->get($row['term_id']),
        ]);

        return new LengthAwarePaginator($items, $groups->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Subjects in display order: a subject catalogue's own sort_order, then name -
     * Subject::scopeOrderForDisplay()'s own ordering, applied here without re-implementing it,
     * since the report card lists the same catalogue entries a class-subject list already
     * orders.
     *
     * @param  Collection<int, Result>  $results
     * @return Collection<int, Result>
     */
    protected function sortBySubject(Collection $results): Collection
    {
        return $results
            ->sortBy(fn (Result $result): string => sprintf(
                '%05d-%s',
                $result->classSubject->subject->sort_order,
                $result->classSubject->subject->name,
            ))
            ->values();
    }

    /**
     * The only two derived values this module computes, both plain arithmetic means over
     * already-authoritative per-subject figures - never a re-application of Module 09's
     * weighting or Module 11's grading:
     *
     *  - overall_percentage: the unweighted mean of every included subject's own already-final
     *    `percentage`. Each subject's percentage is already normalized to a 0-100 scale by
     *    Module 12 regardless of how many assessments or what weighting scheme produced it, so
     *    averaging them introduces no new weighting decision - every subject counts equally,
     *    which is the only defensible default in a project with no subject-credit or
     *    subject-weight concept anywhere in its schema (confirmed by audit: neither `subjects`
     *    nor `class_subjects` carries one).
     *  - average_grade_point: the mean of every included subject's `grade_point`, EXCLUDING
     *    subjects where it is null (no grading scale configured for the class level, or the
     *    percentage fell in a gap no band covers) - never treated as zero, the identical
     *    missing-data discipline Module 12 itself established. Null when no included subject
     *    has one.
     *
     * DELIBERATELY NO overall_grade. Re-running the averaged percentage back through
     * GradingService::calculate() was considered and rejected: nothing in this project defines
     * that a class-level grading scale is meant to interpret anything other than one subject's
     * own percentage, and inventing that meaning here would be exactly the "do not invent an
     * overall grade the project does not already define" the brief warns against. See the
     * Module 14 audit §12.
     *
     * @param  Collection<int, Result>  $results
     * @return array{subjects_count: int, overall_percentage: string, average_grade_point: string|null}
     */
    protected function summarize(Collection $results): array
    {
        $gradePoints = $results->pluck('grade_point')
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): float => (float) $value);

        return [
            'subjects_count' => $results->count(),
            'overall_percentage' => number_format((float) $results->avg('percentage'), 2, '.', ''),
            'average_grade_point' => $gradePoints->isNotEmpty()
                ? number_format($gradePoints->avg(), 2, '.', '')
                : null,
        ];
    }

    /**
     * A STUDENT may view only their own enrollment's report card - the ownership check this
     * whole module turns on, since a report card's route names an enrollment/term pair, not
     * "yourself" the way /me does. STAFF is refused outright: this project's teacher-scope
     * primitive (TeacherAssignment) is scoped per CLASS SUBJECT, and a report card spans every
     * subject in a term - there is no "form teacher" or "class teacher" concept anywhere in
     * this codebase a single-subject assignment could safely be widened into, so granting
     * STAFF access here would either leak subjects a teacher was never assigned to, or require
     * inventing a scope this project does not have. SUPER_ADMIN/ADMIN/REGISTRAR are
     * unrestricted, matching their existing results.view access.
     */
    protected function assertEnrollmentViewable(Enrollment $enrollment, User $user): void
    {
        if ($user->hasRole(Role::STUDENT)) {
            if ($user->student?->id !== $enrollment->student_id) {
                throw new AuthorizationException('You may only view your own report card.');
            }

            return;
        }

        if ($user->hasRole(Role::STAFF)) {
            throw new AuthorizationException('Report cards are not available to teaching staff.');
        }
    }

    /**
     * The identical rule as assertEnrollmentViewable(), applied to the student-scoped history
     * listing instead of one enrollment: a STUDENT may list only their OWN report-card history.
     */
    protected function assertStudentViewable(Student $student, User $user): void
    {
        if ($user->hasRole(Role::STUDENT)) {
            if ($user->student?->id !== $student->id) {
                throw new AuthorizationException('You may only view your own report card history.');
            }

            return;
        }

        if ($user->hasRole(Role::STAFF)) {
            throw new AuthorizationException('Report cards are not available to teaching staff.');
        }
    }
}

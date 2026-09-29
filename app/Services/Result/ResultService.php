<?php

namespace App\Services\Result;

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Result;
use App\Models\Score;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use App\Services\Grading\GradingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compiling raw assessment scores into a subject result: one enrollment, one class subject,
 * one term.
 *
 * There is exactly ONE calculation path, here, in calculateOutcome() and its two private
 * helpers - never duplicated in the controller, the resource, or anywhere else, per this
 * module's own brief. Grading resolution itself is never re-implemented: GradingService is
 * injected and its calculate()/findActiveForClassLevel() are the sole source of grade,
 * grade point and remark, matching Score's own established discipline of never trusting a
 * client-supplied calculated value and always deriving it server-side from a single
 * authoritative path.
 *
 * The teacher-assignment scope (isAssignedTeacher()/activeTeachingAssignments()) is the exact
 * same shape ScoreService already established, adapted from an Assessment's own
 * (class_subject_id, academic_session_id) pair to a Result's identical pair. It is
 * deliberately re-implemented here rather than shared through a trait: it is three lines, and
 * every module since TeacherAssignment keeps its own copy of a check this size rather than
 * extracting one for two callers.
 */
class ResultService
{
    public function __construct(protected GradingService $grading) {}

    /**
     * Every relation the resource needs, loaded once here rather than per row - the same
     * eager-loading discipline every prior module's service already follows.
     *
     * @var list<string>
     */
    protected const WITH = [
        'enrollment.student.user',
        'enrollment.academicSession',
        'enrollment.schoolClass',
        'enrollment.section',
        'classSubject.schoolClass',
        'classSubject.subject',
        'term.academicSession',
    ];

    /**
     * @param  array{enrollment_id?: int, class_subject_id?: int, term_id?: int, student_id?: int, school_class_id?: int, section_id?: int, subject_id?: int, academic_session_id?: int, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Result>
     */
    public function paginate(array $filters, User $user): LengthAwarePaginator
    {
        return $this->query($filters, $user)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Result>
     */
    protected function query(array $filters, User $user): Builder
    {
        $query = Result::query()->with(self::WITH);

        // Applied UNCONDITIONALLY, before any filter, so a teacher cannot widen what they see
        // by adding a filter naming data outside their own assignments - the identical
        // discipline ScoreService::query() already documents.
        if ($user->hasRole(Role::STAFF)) {
            $assignments = $this->activeTeachingAssignments($user);

            $query->where(function (Builder $scoped) use ($assignments): void {
                if ($assignments->isEmpty()) {
                    $scoped->whereRaw('1 = 0');

                    return;
                }

                foreach ($assignments as $assignment) {
                    $scoped->orWhere(function (Builder $q) use ($assignment): void {
                        $q->where('class_subject_id', $assignment->class_subject_id)
                            ->whereHas(
                                'term',
                                fn (Builder $t): Builder => $t->where('academic_session_id', $assignment->academic_session_id)
                            );
                    });
                }
            });
        }

        return $query
            ->when($filters['enrollment_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('results.enrollment_id', $id))
            ->when($filters['class_subject_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('results.class_subject_id', $id))
            ->when($filters['term_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('results.term_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('results.status', $status))
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'enrollment',
                fn (Builder $qq): Builder => $qq->where('student_id', $id)
            ))
            ->when($filters['school_class_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'enrollment',
                fn (Builder $qq): Builder => $qq->where('school_class_id', $id)
            ))
            ->when($filters['section_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'enrollment',
                fn (Builder $qq): Builder => $qq->where('section_id', $id)
            ))
            ->when($filters['subject_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'classSubject',
                fn (Builder $qq): Builder => $qq->where('subject_id', $id)
            ))
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'enrollment',
                fn (Builder $qq): Builder => $qq->where('academic_session_id', $id)
            ));
    }

    /**
     * Authorize and fully load an already-resolved result for a single read - the identical
     * "reuse the controller's route-model-bound instance" pattern ScoreService::view() uses.
     */
    public function view(Result $result, User $user): Result
    {
        $result->loadMissing(self::WITH);

        $this->assertViewable($result, $user);

        return $result;
    }

    /**
     * Compile (or recompile) one enrollment's result for one class subject and term.
     *
     * Every reference is validated and every calculated value is derived server-side from
     * Scores, Assessments and the applicable GradingScale - never accepted from the client,
     * which sends only the identifying triple. See calculateOutcome() for the one calculation
     * path, and persist() for the idempotent upsert and its concurrency handling.
     *
     * @param  array{enrollment_id: int, class_subject_id: int, term_id: int}  $attributes
     */
    public function compile(array $attributes, User $user): Result
    {
        $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);
        $classSubject = ClassSubject::query()
            ->with('schoolClass.classLevel')
            ->findOrFail($attributes['class_subject_id']);
        $term = Term::query()->findOrFail($attributes['term_id']);

        $this->assertClassSubjectSelectable($classSubject);
        $this->assertContextMatches($enrollment, $classSubject, $term);
        $this->assertTeacherAuthorized($user, $classSubject->id, $term->academic_session_id);

        return $this->persist($enrollment, $classSubject, $term);
    }

    /**
     * Compile (or recompile) every currently ACTIVE enrollment in a class subject's own class,
     * for one term - the natural "I've finished entering scores for my whole class" workflow.
     *
     * Deliberately NOT all-or-nothing, unlike Module 10's bulk score entry: each student's
     * compile is an independent, idempotent CALCULATION over already-valid data, not a new
     * fact being asserted that could be a client's mistake. One student having no scores yet
     * (INCOMPLETE, not an error) must never stop the rest of the class from compiling - see
     * the Module 12 audit for the full reasoning behind this deliberate difference from Score's
     * own bulk design.
     *
     * Authorization and the class subject's own selectability are checked ONCE against the
     * shared context, exactly like StoreScoreBulkRequest checks the shared assessment_id once
     * rather than per row.
     *
     * @param  array{class_subject_id: int, term_id: int}  $attributes
     * @return list<array{enrollment_id: int, result: Result|null, error: string|null}>
     */
    public function compileClassSubject(array $attributes, User $user): array
    {
        $classSubject = ClassSubject::query()
            ->with('schoolClass.classLevel')
            ->findOrFail($attributes['class_subject_id']);
        $term = Term::query()->findOrFail($attributes['term_id']);

        $this->assertClassSubjectSelectable($classSubject);
        $this->assertTeacherAuthorized($user, $classSubject->id, $term->academic_session_id);

        // Only CURRENTLY active enrollments in this exact class and session - a class-wide
        // sweep compiles the roster as it stands today. A withdrawn student's result is still
        // reachable through the single compile() above by naming their enrollment directly;
        // bulk does not go looking for them.
        $enrollments = Enrollment::query()
            ->where('school_class_id', $classSubject->school_class_id)
            ->where('academic_session_id', $term->academic_session_id)
            ->where('status', EnrollmentStatus::ACTIVE->value)
            ->get();

        return $enrollments
            ->map(function (Enrollment $enrollment) use ($classSubject, $term): array {
                try {
                    return [
                        'enrollment_id' => $enrollment->id,
                        'result' => $this->persist($enrollment, $classSubject, $term),
                        'error' => null,
                    ];
                } catch (BusinessRuleViolation $e) {
                    return [
                        'enrollment_id' => $enrollment->id,
                        'result' => null,
                        'error' => $e->getMessage(),
                    ];
                }
            })
            ->all();
    }

    /**
     * The one calculation path, wrapped in the idempotent upsert every compile (single or
     * bulk) goes through.
     *
     * Row-locked inside a transaction so two concurrent RECOMPILES of the same existing result
     * serialize cleanly; the unique index is the backstop for the narrower race on the very
     * FIRST compile of a triple, where there is no row yet to lock - the identical two-layer
     * concurrency guarantee every prior module's own create() uses for its own unique index,
     * extended here to an upsert rather than a pure insert.
     */
    protected function persist(Enrollment $enrollment, ClassSubject $classSubject, Term $term): Result
    {
        $outcome = $this->calculateOutcome($classSubject, $term, $enrollment);

        try {
            return DB::transaction(function () use ($enrollment, $classSubject, $term, $outcome): Result {
                $result = Result::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('class_subject_id', $classSubject->id)
                    ->where('term_id', $term->id)
                    ->lockForUpdate()
                    ->first() ?? new Result;

                if ($result->exists && $result->isLocked()) {
                    throw new BusinessRuleViolation(
                        'This result has been locked and can no longer be recompiled.'
                    );
                }

                $result->forceFill([
                    'enrollment_id' => $enrollment->id,
                    'class_subject_id' => $classSubject->id,
                    'term_id' => $term->id,
                    'percentage' => $outcome['percentage'],
                    'grade' => $outcome['grade'],
                    'grade_point' => $outcome['grade_point'],
                    'remark' => $outcome['remark'],
                    'status' => $outcome['status'],
                ])->save();

                return $result;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'This result was compiled concurrently by another request. Please try again.'
                );
            }

            throw $e;
        }
    }

    /**
     * The one authoritative calculation: gather every ACTIVE assessment configured for this
     * class subject and term, gather this enrollment's scores against them, and derive a
     * percentage, completeness, and (when complete) a grade.
     *
     * WEIGHTING - consuming Module 09's existing, unmodified design:
     *
     *  - If at least one SCORED assessment carries a configured weight, the percentage is the
     *    sum of each scored+weighted assessment's own contribution
     *    (score/max_score * weight), with any scored-but-UNWEIGHTED assessment simply excluded
     *    from the sum - weight=null already means "not part of the school's configured
     *    weighting scheme" under Module 09's own design, so it contributes nothing here either.
     *  - If NO scored assessment carries a weight at all (the class subject does not use
     *    weighting), the percentage falls back to a plain ratio: total scored marks over total
     *    POSSIBLE marks. The denominator deliberately includes every ACTIVE assessment's
     *    max_score, scored or not - see below for why.
     *
     * In BOTH branches, a missing score is never treated as zero, and the result is never
     * silently renormalized to look more complete than it is: a missing assessment's weight
     * (or its max_score, in the unweighted branch's denominator) is simply never recovered by
     * the assessments that WERE scored, so an incomplete result's percentage is always honestly
     * capped below what a fully-scored one could reach - never inflated by averaging over a
     * smaller pool. This is the one deliberate design decision this module makes about
     * "missing scores" (see the Module 12 audit), and it applies identically whether one
     * assessment or every assessment is still unscored.
     *
     * Rounding happens exactly ONCE, on the final percentage - every intermediate value
     * (a normalized per-assessment ratio, a weighted contribution, a running sum) is carried
     * as a plain PHP float and never rounded until this single final step, matching
     * ScoreResource::scorePercentage()'s own established precedent for this exact class of
     * calculation rather than introducing a new (e.g. BCMath) technique this codebase does not
     * otherwise use.
     *
     * Grade/grade point/remark are resolved ONLY when the result is complete - grading a
     * partial picture would misrepresent it as final. A missing grading scale, or a percentage
     * no band covers, both resolve to null rather than an error - the identical "no match is
     * not a failure" posture GradingService::calculate() already established in Module 11.
     *
     * @return array{percentage: float, status: ResultStatus, grade: ?string, grade_point: ?string, remark: ?string}
     */
    protected function calculateOutcome(ClassSubject $classSubject, Term $term, Enrollment $enrollment): array
    {
        $assessments = Assessment::query()
            ->where('class_subject_id', $classSubject->id)
            ->where('term_id', $term->id)
            ->where('status', CatalogStatus::ACTIVE->value)
            ->get();

        if ($assessments->isEmpty()) {
            throw new BusinessRuleViolation(
                'No assessments are configured for this class subject and term, so a result cannot be compiled.'
            );
        }

        /** @var Collection<int, Score> $scoresByAssessmentId */
        $scoresByAssessmentId = Score::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->keyBy('assessment_id');

        $scoredAssessments = $assessments->filter(fn (Assessment $a): bool => $scoresByAssessmentId->has($a->id));
        $isComplete = $scoredAssessments->count() === $assessments->count();

        $percentage = round($this->calculatePercentage($assessments, $scoredAssessments, $scoresByAssessmentId), 2);

        $status = $isComplete ? ResultStatus::COMPILED : ResultStatus::INCOMPLETE;
        $grade = $gradePoint = $remark = null;

        if ($isComplete) {
            $classLevelId = $classSubject->schoolClass->classLevel->id;
            $scale = $this->grading->findActiveForClassLevel($classLevelId);
            $item = $scale ? $this->grading->calculate($scale, $percentage) : null;

            if ($item) {
                $grade = $item->grade;
                $gradePoint = $item->grade_point;
                $remark = $item->remark;
            }
        }

        return [
            'percentage' => $percentage,
            'status' => $status,
            'grade' => $grade,
            'grade_point' => $gradePoint,
            'remark' => $remark,
        ];
    }

    /**
     * @param  EloquentCollection<int, Assessment>  $assessments  every active assessment in scope
     * @param  Collection<int, Assessment>  $scoredAssessments  the subset that has a score
     * @param  Collection<int, Score>  $scoresByAssessmentId
     */
    protected function calculatePercentage(
        EloquentCollection $assessments,
        Collection $scoredAssessments,
        Collection $scoresByAssessmentId,
    ): float {
        $weightedAssessments = $scoredAssessments->filter(fn (Assessment $a): bool => $a->weight !== null);

        if ($weightedAssessments->isNotEmpty()) {
            return $weightedAssessments->sum(function (Assessment $assessment) use ($scoresByAssessmentId): float {
                $score = $scoresByAssessmentId->get($assessment->id);
                $normalized = (float) $score->score / (float) $assessment->max_score;

                return $normalized * (float) $assessment->weight;
            });
        }

        // No configured weighting at all: a plain ratio of marks scored so far over every
        // POSSIBLE mark in the class subject's full assessment set - not only the assessments
        // already scored, so a missing assessment dilutes the percentage rather than being
        // invisible to it. See this method's own caller for the full reasoning.
        $maxTotal = $assessments->sum(fn (Assessment $a): float => (float) $a->max_score);

        if ($maxTotal <= 0) {
            return 0.0;
        }

        $scoredTotal = $scoredAssessments->sum(
            fn (Assessment $a): float => (float) $scoresByAssessmentId->get($a->id)->score
        );

        return ($scoredTotal / $maxTotal) * 100;
    }

    protected function assertViewable(Result $result, User $user): void
    {
        if (! $user->hasRole(Role::STAFF)) {
            return;
        }

        if (! $this->isAssignedTeacher($user, $result->class_subject_id, $result->term->academic_session_id)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class subject, so you cannot view this result.'
            );
        }
    }

    /**
     * The gate behind every compile(): teaching staff may act only on a class subject they
     * hold an ACTIVE TeacherAssignment for, FOR THE SAME ACADEMIC SESSION the term belongs to -
     * the identical (class_subject_id, academic_session_id) pair ScoreService::isAssignedTeacher()
     * already established, and for the identical reason: TeacherAssignment is itself
     * session-scoped, so the class subject id alone is not enough. Anyone who is NOT role
     * STAFF is unrestricted here, matching how ADMIN/REGISTRAR are treated everywhere else in
     * this project once they hold the permission at all.
     */
    protected function assertTeacherAuthorized(User $user, int $classSubjectId, int $academicSessionId): void
    {
        if (! $user->hasRole(Role::STAFF)) {
            return;
        }

        if (! $this->isAssignedTeacher($user, $classSubjectId, $academicSessionId)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class subject, so you cannot compile results for it.'
            );
        }
    }

    protected function isAssignedTeacher(User $user, int $classSubjectId, int $academicSessionId): bool
    {
        $staff = $user->staff;

        if (! $staff || $staff->staff_type !== StaffType::TEACHING || ! $staff->isActive()) {
            return false;
        }

        return TeacherAssignment::query()
            ->where('teaching_staff_id', $staff->id)
            ->where('class_subject_id', $classSubjectId)
            ->where('academic_session_id', $academicSessionId)
            ->where('status', TeacherAssignmentStatus::ACTIVE->value)
            ->exists();
    }

    /**
     * @return Collection<int, TeacherAssignment>
     */
    protected function activeTeachingAssignments(User $user): Collection
    {
        $staff = $user->staff;

        if (! $staff || $staff->staff_type !== StaffType::TEACHING || ! $staff->isActive()) {
            return collect();
        }

        return TeacherAssignment::query()
            ->where('teaching_staff_id', $staff->id)
            ->where('status', TeacherAssignmentStatus::ACTIVE->value)
            ->get(['class_subject_id', 'academic_session_id']);
    }

    /**
     * A class subject must exist, be ACTIVE, and belong to a class that is itself ACTIVE and
     * whose class level is ACTIVE - the identical three-level chain Module 08, 09 and 10 all
     * apply to their own class-hierarchy references, checked EVERY compile (not only the
     * first) because recompiling from scratch against the current academic context is the
     * whole point of this operation, unlike Score's own update() which never re-derives
     * context for a plain correction.
     */
    protected function assertClassSubjectSelectable(ClassSubject $classSubject): void
    {
        $class = $classSubject->schoolClass;

        if ($classSubject->status !== CatalogStatus::ACTIVE
            || $class->status !== CatalogStatus::ACTIVE
            || ! $class->classLevel->status->isActive()) {
            throw new BusinessRuleViolation(
                'This class subject is not currently available for result compilation.'
            );
        }
    }

    /**
     * A result's enrollment must belong to the SAME class as the class subject, and the SAME
     * academic session as the term - the identical context-match discipline
     * ScoreService::assertContextMatches() already established for assessment/enrollment
     * pairs, applied here to class-subject/term/enrollment triples. Unlike Score, an ended
     * enrollment is NOT refused: compiling a result is aggregating already-recorded history,
     * not asserting a new fact about an active placement, so a withdrawn student's result
     * remains compilable - see the Module 12 audit.
     */
    protected function assertContextMatches(Enrollment $enrollment, ClassSubject $classSubject, Term $term): void
    {
        if ($enrollment->school_class_id !== $classSubject->school_class_id) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different class than the class subject.'
            );
        }

        if ($enrollment->academic_session_id !== $term->academic_session_id) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different academic session than the term.'
            );
        }
    }

    /**
     * Whether a QueryException is the unique(enrollment_id, class_subject_id, term_id) index
     * refusing a race, rather than some other integrity failure that should keep propagating
     * as a genuine 500. SQLSTATE 23000 is the portable integrity-violation class across all
     * four configured drivers; this table's only unique index is this one, so any 23000 here
     * is that one - the identical technique every prior module's service uses for its own
     * unique index.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

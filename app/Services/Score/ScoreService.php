<?php

namespace App\Services\Score;

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Score;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recording and amending what a specific student obtained against a specific configured
 * assessment.
 *
 * The one genuinely new shape in this service, compared to every module before it: every
 * write, and every read, is scoped to the acting user, not merely gated by a flat permission.
 * A holder of scores.create may still be refused a SPECIFIC score because they are teaching
 * staff with no active assignment to that assessment's class subject - see
 * assertTeacherAuthorized(). This is deliberately a protected method on THIS service rather
 * than a new ScorePermissionService: the same layering every prior module already uses for a
 * domain check that needs a loaded relation (assertClassSubjectSelectable() in Module 08 and
 * Module 09), extended to scope by the ACTOR rather than only by a payload field.
 */
class ScoreService
{
    /**
     * @param  array{assessment_id?: int, assessment_type_id?: int, enrollment_id?: int, student_id?: int, school_class_id?: int, section_id?: int, subject_id?: int, academic_session_id?: int, term_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Score>
     */
    public function paginate(array $filters, User $user): LengthAwarePaginator
    {
        return $this->query($filters, $user)
            ->orderByDesc('scores.created_at')
            ->orderByDesc('scores.id')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Score>
     */
    protected function query(array $filters, User $user): Builder
    {
        $query = Score::query()->with(self::WITH);

        // The scope is applied UNCONDITIONALLY, before any filter, so a teacher cannot widen
        // what they see by adding a filter naming data outside their own assignments - see
        // the Module 10 audit, "A teacher must not be able to bypass authorization simply by
        // adding a filter". A filter narrows within the scope; it never replaces it.
        if ($user->hasRole(Role::STAFF)) {
            $assignments = $this->activeTeachingAssignments($user);

            $query->where(function (Builder $scoped) use ($assignments): void {
                if ($assignments->isEmpty()) {
                    $scoped->whereRaw('1 = 0');

                    return;
                }

                // One OR-branch per active assignment, each pinning BOTH the class subject and
                // the academic session together - see isAssignedTeacher()'s own docblock for
                // why the pair, not the class subject alone, is what TeacherAssignment actually
                // authorizes.
                foreach ($assignments as $assignment) {
                    $scoped->orWhereHas(
                        'assessment',
                        fn (Builder $q): Builder => $q
                            ->where('class_subject_id', $assignment->class_subject_id)
                            ->whereHas(
                                'term',
                                fn (Builder $t): Builder => $t->where('academic_session_id', $assignment->academic_session_id)
                            )
                    );
                }
            });
        }

        return $query
            ->when($filters['assessment_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('scores.assessment_id', $id))
            ->when($filters['enrollment_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('scores.enrollment_id', $id))
            ->when($filters['assessment_type_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'assessment',
                fn (Builder $qq): Builder => $qq->where('assessment_type_id', $id)
            ))
            ->when($filters['term_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'assessment',
                fn (Builder $qq): Builder => $qq->where('term_id', $id)
            ))
            ->when($filters['subject_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'assessment.classSubject',
                fn (Builder $qq): Builder => $qq->where('subject_id', $id)
            ))
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
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'enrollment',
                fn (Builder $qq): Builder => $qq->where('academic_session_id', $id)
            ));
    }

    /**
     * Every relation the resource needs, loaded once here rather than per row - the same
     * eager-loading discipline every prior module's service already follows.
     *
     * @var list<string>
     */
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

    /**
     * Authorize and fully load an already-resolved score for a single read.
     *
     * Takes the model rather than an id so the controller's own route-model-bound instance
     * (which already turned a missing id into a 404 before this ever runs) is reused rather
     * than queried a second time - the eager loads below are a follow-up query for the
     * relations the resource needs, the identical `$model->load([...])` pattern every other
     * show() in this project already uses.
     */
    public function view(Score $score, User $user): Score
    {
        $score->loadMissing(self::WITH);

        $this->assertViewable($score, $user);

        return $score;
    }

    /**
     * Record a student's mark against an assessment.
     *
     * The assessment's own selectability chain and the enrollment/assessment context match
     * both need loaded relations a Form Request rule cannot express without a join - the
     * identical layering every prior module uses for its own class-hierarchy checks. Everything
     * expressible as a plain column exists()/unique() rule (assessment exists and is ACTIVE,
     * enrollment exists and is ACTIVE, score is non-negative and does not exceed the
     * assessment's live max_score, no existing score for this pair) is validated entirely in
     * StoreScoreRequest and is not repeated here.
     *
     * Wrapped in a transaction with the unique index as the final backstop, exactly like every
     * prior module's create(): two requests that both pass validation in the same instant
     * would otherwise both attempt the insert, and the loser must see a clear 422.
     *
     * @param  array{assessment_id: int, enrollment_id: int, score: string|float, remarks?: string|null}  $attributes
     */
    public function create(array $attributes, User $user): Score
    {
        $assessment = Assessment::query()
            ->with(['classSubject.schoolClass.classLevel', 'term'])
            ->findOrFail($attributes['assessment_id']);
        $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);

        $this->assertAssessmentSelectable($assessment);
        $this->assertTeacherAuthorized($user, $assessment);
        $this->assertContextMatches($assessment, $enrollment);

        try {
            return DB::transaction(function () use ($attributes, $assessment, $enrollment): Score {
                $score = new Score;

                $score->forceFill([
                    'assessment_id' => $assessment->id,
                    'enrollment_id' => $enrollment->id,
                    'score' => $attributes['score'],
                    'remarks' => $attributes['remarks'] ?? null,
                ])->save();

                return $score;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'This enrollment already has a score for this assessment.',
                    ['enrollment_id' => ['This enrollment already has a score for this assessment.']],
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Record a batch of scores against ONE assessment - the natural shape a class roster is
     * entered in.
     *
     * Deliberately all-or-nothing: EVERY row is validated first, and if any row is invalid the
     * whole batch is refused with a full per-row error report and NOTHING is written - see the
     * Module 10 audit for why partial success was rejected for academic score entry. Only once
     * every row has passed does a single transaction insert the batch, with the unique index as
     * the race-safe backstop.
     *
     * @param  array{assessment_id: int, scores: list<array{enrollment_id: int, score: string|float, remarks?: string|null}>}  $payload
     * @return list<Score>
     */
    public function createBulk(array $payload, User $user): array
    {
        $assessment = Assessment::query()
            ->with(['classSubject.schoolClass.classLevel', 'term'])
            ->findOrFail($payload['assessment_id']);

        $this->assertAssessmentSelectable($assessment);
        $this->assertTeacherAuthorized($user, $assessment);

        $enrollments = Enrollment::query()
            ->whereIn('id', collect($payload['scores'])->pluck('enrollment_id')->all())
            ->get()
            ->keyBy('id');

        $errors = [];

        foreach ($payload['scores'] as $index => $row) {
            $enrollment = $enrollments->get($row['enrollment_id']);

            // Existence is already guaranteed by StoreScoreBulkRequest's own Rule::exists on
            // scores.*.enrollment_id; this defends against a row whose enrollment vanished
            // between validation and this call, the same race window the unique-index catch
            // below covers for scores themselves.
            if (! $enrollment) {
                $errors["scores.{$index}.enrollment_id"] = ['This enrollment does not exist.'];

                continue;
            }

            try {
                $this->assertContextMatches($assessment, $enrollment);
            } catch (BusinessRuleViolation $e) {
                $errors["scores.{$index}.enrollment_id"] = [$e->getMessage()];
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleViolation(
                'One or more rows in this batch are invalid. No scores were saved.',
                $errors,
            );
        }

        try {
            return DB::transaction(fn (): array => collect($payload['scores'])
                ->map(function (array $row) use ($assessment): Score {
                    $score = new Score;

                    $score->forceFill([
                        'assessment_id' => $assessment->id,
                        'enrollment_id' => $row['enrollment_id'],
                        'score' => $row['score'],
                        'remarks' => $row['remarks'] ?? null,
                    ])->save();

                    return $score;
                })
                ->all());
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'One or more of these enrollments already has a score for this assessment. No scores were saved.',
                );
            }

            throw $e;
        }
    }

    /**
     * Amend a score's mark or remarks. Nothing else is reachable this way - see
     * UpdateScoreRequest. The pair a score names (assessment, enrollment) is fixed for its
     * lifetime; only the mark itself can change.
     *
     * The assessment's own max_score is re-validated against the LIVE value at update time too
     * (in UpdateScoreRequest, the same dynamic lookup StoreScoreRequest uses) rather than a
     * value captured when the score was first created, because Assessment.max_score is itself
     * editable - see the Module 10 audit for why a score already on file is never retroactively
     * invalidated by a later change to its assessment, only re-checked on the next write.
     *
     * @param  array{score?: string|float, remarks?: string|null}  $attributes
     */
    public function update(Score $score, array $attributes, User $user): Score
    {
        $score->loadMissing(['assessment.classSubject.schoolClass.classLevel', 'assessment.term']);

        $this->assertTeacherAuthorized($user, $score->assessment);

        $score->forceFill($attributes)->save();

        return $score;
    }

    /**
     * Teaching staff may view only scores for class subjects they hold an ACTIVE assignment
     * for - the identical scope create()/update() enforce, applied to reads too rather than
     * only to writes.
     */
    protected function assertViewable(Score $score, User $user): void
    {
        if (! $user->hasRole(Role::STAFF)) {
            return;
        }

        if (! $this->isAssignedTeacher($user, $score->assessment)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class subject, so you cannot view this score.'
            );
        }
    }

    /**
     * The gate behind every create() and update(): teaching staff may act only on an
     * assessment whose class subject they hold an ACTIVE TeacherAssignment for. Anyone who is
     * NOT role STAFF (SUPER_ADMIN, ADMIN, REGISTRAR) is unrestricted here - their own
     * permission grant is already the whole answer for them, matching how every other module
     * in this project treats ADMIN/REGISTRAR as globally scoped once they hold the permission
     * at all.
     *
     * An AuthorizationException, not a BusinessRuleViolation: this is a genuine authorization
     * failure (the actor may hold scores.create in general but not for THIS assessment), not a
     * well-formed-but-domain-invalid request - the same distinction the rest of this project
     * draws via bootstrap/app.php's existing render() for AuthorizationException, already
     * wired to answer 403 with no new plumbing needed.
     */
    protected function assertTeacherAuthorized(User $user, Assessment $assessment): void
    {
        if (! $user->hasRole(Role::STAFF)) {
            return;
        }

        if (! $this->isAssignedTeacher($user, $assessment)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class subject, so you cannot enter or amend scores for it.'
            );
        }
    }

    /**
     * Whether this user is, right now, the teacher of record for the assessment's class
     * subject - checked as a (class_subject_id, academic_session_id) PAIR, not the class
     * subject alone. TeacherAssignment is itself session-scoped (Module 08's own central
     * design decision: who teaches a class subject changes year to year), so a teacher once
     * assigned to "JSS 2 Mathematics" in 2025/2026 must NOT be authorized for a 2026/2027
     * assessment merely because the class subject id matches - someone else may hold that
     * responsibility this year. The session is read from assessment.term.academic_session_id,
     * the same derivation assertContextMatches() uses.
     *
     * False for anyone who is not an actively employed TEACHING staff member, including a
     * NON_TEACHING staff member who happens to hold scores.* at the role level - the permission
     * grants the ability to ATTEMPT the operation; this is the domain check that decides
     * whether a specific attempt is valid, the same layering a payload-level eligibility rule
     * already applies everywhere else in this project.
     */
    protected function isAssignedTeacher(User $user, Assessment $assessment): bool
    {
        $staff = $user->staff;

        if (! $staff || $staff->staff_type !== StaffType::TEACHING || ! $staff->isActive()) {
            return false;
        }

        return TeacherAssignment::query()
            ->where('teaching_staff_id', $staff->id)
            ->where('class_subject_id', $assessment->class_subject_id)
            ->where('academic_session_id', $assessment->term->academic_session_id)
            ->where('status', TeacherAssignmentStatus::ACTIVE->value)
            ->exists();
    }

    /**
     * Every ACTIVE teaching assignment this user currently holds, as (class_subject_id,
     * academic_session_id) pairs - the shape query() needs to scope a LIST rather than check
     * one specific assessment. Empty for anyone who is not an actively employed TEACHING staff
     * member, mirroring isAssignedTeacher()'s own eligibility gate.
     *
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
     * An assessment must exist, be ACTIVE, and belong to a class subject that is itself ACTIVE,
     * whose class is ACTIVE, and whose class level is ACTIVE - the identical three-level chain
     * Module 08 and Module 09 both apply to their own class-hierarchy references, checked
     * again here because none of the four statuses can be assumed from another.
     */
    protected function assertAssessmentSelectable(Assessment $assessment): void
    {
        $classSubject = $assessment->classSubject;
        $class = $classSubject->schoolClass;

        if ($assessment->status !== CatalogStatus::ACTIVE
            || $classSubject->status !== CatalogStatus::ACTIVE
            || $class->status !== CatalogStatus::ACTIVE
            || ! $class->classLevel->status->isActive()) {
            throw new BusinessRuleViolation(
                'This assessment is not currently available for score entry.'
            );
        }
    }

    /**
     * A score's enrollment must be the student's ACTIVE placement, and it must be the SAME
     * academic context the assessment itself belongs to - the same class, and the same
     * academic session (derived through the assessment's term, since Assessment carries no
     * academic_session_id of its own - see the assessments migration). A JSS 1 enrollment must
     * never accept a score for a JSS 2 assessment, and an ended placement must never accept a
     * NEW score at all, even though an already-recorded one may still be amended - see
     * update()'s own docblock for why a terminal enrollment does not block a correction to a
     * score already on file.
     *
     * Term completion is deliberately NOT checked here, unlike every module that guards a NEW
     * placement against a completed session/term. A score is retrospective data entry for an
     * assessment that already happened, routinely finished right as or after a term closes -
     * blocking it the moment a term completes would stop a teacher finishing legitimate,
     * already-due data entry. See the Module 10 audit.
     */
    protected function assertContextMatches(Assessment $assessment, Enrollment $enrollment): void
    {
        if (! $enrollment->isActive()) {
            throw new BusinessRuleViolation(
                'This enrollment has ended, so a new score cannot be recorded against it.'
            );
        }

        if ($enrollment->school_class_id !== $assessment->classSubject->school_class_id) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different class than the assessment.'
            );
        }

        if ($enrollment->academic_session_id !== $assessment->term->academic_session_id) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different academic session than the assessment.'
            );
        }
    }

    /**
     * Whether a QueryException is the unique(assessment_id, enrollment_id) index refusing a
     * race, rather than some other integrity failure that should keep propagating as a genuine
     * 500. SQLSTATE 23000 is the portable integrity-violation class across all four configured
     * drivers; this table's only unique index is this one, so any 23000 here is that one - the
     * identical technique every prior module's service uses for its own unique index.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

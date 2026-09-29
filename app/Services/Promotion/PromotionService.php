<?php

namespace App\Services\Promotion;

use App\Enums\PromotionDecision;
use App\Enums\StudentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Student\StudentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Recording what happens to a student's placement going into a new academic session:
 * PROMOTED, RETAINED, GRADUATED or NOT_ELIGIBLE.
 *
 * THIS IS NOT A SECOND ENROLLMENT SERVICE. Creating the target enrollment (for PROMOTED and
 * RETAINED) is delegated entirely to the EXISTING EnrollmentService::create() - the identical
 * "one service calls another, never re-implements it" pattern AdmissionService already
 * established for StudentService::create(). Every check EnrollmentService::create() already
 * performs (the target class is ACTIVE, its class level is ACTIVE, the enrollment date falls
 * inside the target session, no other enrollment already claims this student's seat for this
 * session) is inherited for free; this class adds only the checks that are specific to a
 * PROMOTION decision - see assertSourceEligible()/assertTargetSession().
 *
 * THIS IS NOT A CLASS-PROGRESSION ENGINE. There is no "next class" lookup anywhere in this
 * class. Nothing in this project's schema configures a progression graph (no
 * `next_class_id`/`next_class_level_id` column exists on SchoolClass or ClassLevel - confirmed
 * by audit), so a target class and section are always an explicit decision the caller supplies
 * for PROMOTED, never computed. RETAINED is the one exception, and even that is not a
 * computation: the target class/section are simply COPIED from the source enrollment, never
 * accepted from the client - see PromoteStudentRequest.
 */
class PromotionService
{
    /**
     * @var list<string>
     */
    protected const WITH = [
        'sourceEnrollment.student',
        'sourceEnrollment.academicSession',
        'sourceEnrollment.schoolClass',
        'sourceEnrollment.section',
        'targetEnrollment.academicSession',
        'targetEnrollment.schoolClass',
        'targetEnrollment.section',
        'targetAcademicSession',
        'decidedBy',
    ];

    public function __construct(
        protected EnrollmentService $enrollments,
        protected StudentService $students,
    ) {}

    /**
     * @param  array{source_enrollment_id?: int, target_academic_session_id?: int, decision?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Promotion>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Promotion>
     */
    protected function query(array $filters): Builder
    {
        return Promotion::query()
            ->with(self::WITH)
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'sourceEnrollment',
                fn (Builder $qq): Builder => $qq->where('student_id', $id)
            ))
            ->when($filters['source_enrollment_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('source_enrollment_id', $id))
            ->when($filters['target_academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('target_academic_session_id', $id))
            ->when($filters['decision'] ?? null, fn (Builder $q, string $decision): Builder => $q->where('decision', $decision));
    }

    /**
     * Authorize and fully load an already-resolved promotion for a single read - the
     * identical "reuse the controller's route-model-bound instance" pattern every prior
     * module's own view() uses.
     */
    public function view(Promotion $promotion): Promotion
    {
        return $promotion->loadMissing(self::WITH);
    }

    /**
     * Record a promotion decision for one student, moving (or not moving) them from their
     * source enrollment into a target academic session.
     *
     * Every reference is re-verified server-side, never trusted from the request - see
     * PromoteStudentRequest for the declarative half of that (source_enrollment_id belongs to
     * the named student and is ACTIVE; target_academic_session_id exists and is not COMPLETED;
     * target_school_class_id/target_section_id, required only for PROMOTED, are a real,
     * selectable pairing) and assertSourceEligible()/assertTargetSession() below for the half
     * that needs a loaded relation.
     *
     * @param  array{source_enrollment_id: int, target_academic_session_id: int, decision: string, target_school_class_id?: int, target_section_id?: int, reason?: string|null}  $attributes
     */
    public function promote(Student $student, array $attributes, User $actor): Promotion
    {
        $sourceEnrollment = Enrollment::query()
            ->with('academicSession')
            ->findOrFail($attributes['source_enrollment_id']);

        $this->assertSourceEligible($student, $sourceEnrollment);

        $targetSession = AcademicSession::query()->findOrFail($attributes['target_academic_session_id']);
        $this->assertTargetSession($sourceEnrollment->academicSession, $targetSession);

        $decision = PromotionDecision::from($attributes['decision']);

        if ($decision === PromotionDecision::PROMOTED
            && $attributes['target_school_class_id'] === $sourceEnrollment->school_class_id) {
            throw new BusinessRuleViolation(
                'A PROMOTED decision must target a different class than the source enrollment. Record this as RETAINED instead if the student is staying in the same class.'
            );
        }

        try {
            return DB::transaction(function () use ($student, $sourceEnrollment, $targetSession, $decision, $attributes, $actor): Promotion {
                $targetEnrollment = $this->createTargetEnrollment($student, $sourceEnrollment, $targetSession, $decision, $attributes);

                if ($decision->isGraduation()) {
                    $this->students->update($student, [], StudentStatus::GRADUATED);
                }

                $promotion = new Promotion;

                $promotion->forceFill([
                    'source_enrollment_id' => $sourceEnrollment->id,
                    'target_academic_session_id' => $targetSession->id,
                    'target_enrollment_id' => $targetEnrollment?->id,
                    'decision' => $decision,
                    'reason' => $attributes['reason'] ?? null,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ])->save();

                return $promotion->load(self::WITH);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'A promotion decision has already been recorded for this enrollment and target academic session.'
                );
            }

            throw $e;
        }
    }

    /**
     * PROMOTED and RETAINED both place the student for the target session, via the EXISTING
     * EnrollmentService::create() - never a second insert path. The one difference between
     * them is where the target class/section come from: an explicit decision for PROMOTED, a
     * plain copy of the source enrollment's own values for RETAINED. GRADUATED and
     * NOT_ELIGIBLE create nothing.
     *
     * The enrollment date is always the target session's own start date - this module adds no
     * field for the caller to choose one, since nothing in the brief asks a promotion decision
     * to backdate or postdate a placement relative to when its session actually begins.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTargetEnrollment(
        Student $student,
        Enrollment $sourceEnrollment,
        AcademicSession $targetSession,
        PromotionDecision $decision,
        array $attributes,
    ): ?Enrollment {
        if (! $decision->createsEnrollment()) {
            return null;
        }

        [$schoolClassId, $sectionId] = $decision === PromotionDecision::RETAINED
            ? [$sourceEnrollment->school_class_id, $sourceEnrollment->section_id]
            : [$attributes['target_school_class_id'], $attributes['target_section_id']];

        return $this->enrollments->create([
            'student_id' => $student->id,
            'academic_session_id' => $targetSession->id,
            'school_class_id' => $schoolClassId,
            'section_id' => $sectionId,
            'enrollment_date' => $targetSession->start_date->toDateString(),
        ]);
    }

    /**
     * The source enrollment must genuinely belong to the named student - never trusted merely
     * because both ids arrived in the same request, the identical IDOR discipline every prior
     * module applies to a client-supplied pair of references. It must also be ACTIVE: a
     * withdrawn or cancelled placement already ended for an unrelated, already-recorded reason,
     * so there is no "current placement" left to decide anything about. The student's own
     * record must not already be terminal either - a promotion decision about someone who has
     * already graduated or withdrawn is not a real operation, the same posture
     * StudentService::update() already takes toward amending a departed pupil's record.
     */
    protected function assertSourceEligible(Student $student, Enrollment $sourceEnrollment): void
    {
        if ($sourceEnrollment->student_id !== $student->id) {
            throw new BusinessRuleViolation(
                'This enrollment does not belong to the named student.'
            );
        }

        if (! $sourceEnrollment->isActive()) {
            throw new BusinessRuleViolation(
                "This enrollment has already ended ({$sourceEnrollment->status->value}), so it is not eligible for a promotion decision."
            );
        }

        if ($student->isTerminal()) {
            throw new BusinessRuleViolation(
                "This student has already left the school ({$student->status->value}), so no further promotion decision can be recorded."
            );
        }
    }

    /**
     * The target session must be a genuinely different, later session than the one the source
     * enrollment already belongs to - a promotion decision "into" the same session, or
     * backwards into an earlier one, is not a real academic-year transition. Compared by
     * start_date, the same chronological ordering AcademicSession::scopeOrderByRecency()
     * already uses, rather than by id: ids are assigned in creation order, which a school
     * backfilling historical sessions is not guaranteed to match.
     */
    protected function assertTargetSession(AcademicSession $sourceSession, AcademicSession $targetSession): void
    {
        if ($targetSession->id === $sourceSession->id) {
            throw new BusinessRuleViolation(
                'The target academic session must be different from the source enrollment\'s own session.'
            );
        }

        if (! $targetSession->start_date->gt($sourceSession->start_date)) {
            throw new BusinessRuleViolation(
                "The target academic session ({$targetSession->name}) must start after the source enrollment's session ({$sourceSession->name})."
            );
        }
    }

    /**
     * Whether a QueryException is the unique(source_enrollment_id, target_academic_session_id)
     * index refusing a race, rather than some other integrity failure that should keep
     * propagating as a genuine 500. SQLSTATE 23000 is the portable integrity-violation class
     * across all four configured drivers; this table's only unique index is this one, so any
     * 23000 here is that one - the identical technique every prior module's service uses for
     * its own unique index.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

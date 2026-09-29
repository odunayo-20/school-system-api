<?php

namespace App\Services\Enrollment;

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reading and writing the authoritative academic placement.
 *
 * Every write here uses forceFill() rather than ordinary mass assignment, including create().
 * That is a deliberate departure from every prior module's service (StudentService,
 * StaffService, AdmissionService all call Model::create()/fill() and rely on $fillable to
 * guard the model). Enrollment's placement fields have no legitimate mass-assignment caller at
 * all - not even this service, on any amend - so there is nothing $fillable would need to
 * admit, and forceFill makes that visible at the call site instead of depending on a list
 * defined in a different file staying in sync with this one.
 */
class EnrollmentService
{
    /**
     * @param  array{student_id?: int, academic_session_id?: int, school_class_id?: int, section_id?: int, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Enrollment>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            // Newest placement first, matching the admissions queue's own reasoning: this is
            // a working list of placements to review, not a roll to read alphabetically.
            ->orderByDesc('enrollments.created_at')
            ->orderByDesc('enrollments.id')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Enrollment>
     */
    protected function query(array $filters): Builder
    {
        return Enrollment::query()
            ->with(['student', 'academicSession', 'schoolClass', 'section'])
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('enrollments.student_id', $id))
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('enrollments.academic_session_id', $id))
            ->when($filters['school_class_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('enrollments.school_class_id', $id))
            ->when($filters['section_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('enrollments.section_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('enrollments.status', $status));
    }

    /**
     * Place a student in a class and section for an academic session.
     *
     * The class-hierarchy checks a Form Request can express declaratively - the class exists
     * and is active, the section exists, is active, and belongs to the named class, the
     * session exists and is not completed, the student exists and is active, and no other
     * enrollment already claims this student's seat for this session - are already done by
     * the time this method runs; see StoreEnrollmentRequest. What is left is the one check
     * that needs a loaded relation rather than a column comparison (the class's own class
     * level must still be active) and the one check that needs a loaded model to compare
     * dates against (the enrollment date must fall inside the session).
     *
     * Wrapped in a transaction with the unique index as the final backstop, not merely the
     * form request's own uniqueness rule: two requests that both pass validation in the same
     * instant would otherwise both attempt the insert, and the loser must see a clear 422
     * rather than the raw integrity-constraint failure the database would otherwise return.
     *
     * @param  array{student_id: int, academic_session_id: int, school_class_id: int, section_id: int, enrollment_date: string, notes?: string|null}  $attributes
     */
    public function create(array $attributes): Enrollment
    {
        $class = SchoolClass::query()->with('classLevel')->findOrFail($attributes['school_class_id']);
        $session = AcademicSession::query()->findOrFail($attributes['academic_session_id']);

        $this->assertClassLevelActive($class);
        $this->assertDateInsideSession($session, $attributes['enrollment_date']);

        try {
            return DB::transaction(function () use ($attributes): Enrollment {
                $enrollment = new Enrollment;

                $enrollment->forceFill([
                    'student_id' => $attributes['student_id'],
                    'academic_session_id' => $attributes['academic_session_id'],
                    'school_class_id' => $attributes['school_class_id'],
                    'section_id' => $attributes['section_id'],
                    'enrollment_date' => $attributes['enrollment_date'],
                    'notes' => $attributes['notes'] ?? null,
                    'status' => EnrollmentStatus::ACTIVE,
                ])->save();

                return $enrollment;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'This student already has an enrollment for this academic session.',
                    ['student_id' => ['This student already has an enrollment for this academic session.']],
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Amend an enrollment's date and notes. Nothing else is reachable this way - see
     * UpdateEnrollmentRequest. The placement itself (student, session, class, section) is
     * immutable once created, and the status changes only through withdraw()/cancel().
     *
     * A terminal enrollment is READ ONLY in full, the identical rule Module 04 and Module 05
     * apply to a departed pupil and a decided admission: the record is a fact about a period
     * that has closed, and a later amend must not be able to rewrite it.
     *
     * @param  array{enrollment_date: string, notes?: string|null}  $attributes
     */
    public function update(Enrollment $enrollment, array $attributes): Enrollment
    {
        if ($enrollment->isTerminal()) {
            throw new BusinessRuleViolation(
                'This enrollment has ended, so the record can no longer be amended.'
            );
        }

        $this->assertDateInsideSession($enrollment->academicSession, $attributes['enrollment_date']);

        $enrollment->forceFill($attributes)->save();

        return $enrollment;
    }

    /**
     * Record that the student left this placement before the session ended.
     */
    public function withdraw(Enrollment $enrollment, ?string $notes = null): Enrollment
    {
        $this->assertActive($enrollment, 'withdrawn');

        $enrollment->forceFill([
            'status' => EnrollmentStatus::WITHDRAWN,
            'status_changed_at' => now(),
            'notes' => $notes ?? $enrollment->notes,
        ])->save();

        return $enrollment;
    }

    /**
     * Void an enrollment that should not have been created - the record-preserving
     * replacement for a DELETE endpoint. See EnrollmentController.
     */
    public function cancel(Enrollment $enrollment, ?string $notes = null): Enrollment
    {
        $this->assertActive($enrollment, 'cancelled');

        $enrollment->forceFill([
            'status' => EnrollmentStatus::CANCELLED,
            'status_changed_at' => now(),
            'notes' => $notes ?? $enrollment->notes,
        ])->save();

        return $enrollment;
    }

    /**
     * The single gate withdraw() and cancel() share, so they cannot drift into two different
     * answers to "can this placement still change?". Repeating a transition, or attempting
     * the other one, on an already-terminal enrollment is refused rather than treated as an
     * idempotent success - the same posture AdmissionService takes and for the same reason:
     * both are one-shot facts about how a placement ended, not a reversible toggle.
     */
    protected function assertActive(Enrollment $enrollment, string $verb): void
    {
        if (! $enrollment->isActive()) {
            throw new BusinessRuleViolation(
                "This enrollment has already ended ({$enrollment->status->value}), so it cannot be {$verb}."
            );
        }
    }

    /**
     * A class's own status is not enough: a class inside a class level the school has retired
     * must not accept a new placement either, even if nobody has got around to retiring the
     * class itself yet. This needs the loaded relation, which is why it lives here rather than
     * in a Form Request rule - the identical layering TermService uses for its own
     * dates-inside-session check.
     */
    protected function assertClassLevelActive(SchoolClass $class): void
    {
        if (! $class->classLevel->status->isActive()) {
            throw new BusinessRuleViolation(
                "The {$class->classLevel->name} class level is not active, so no new enrollment can be made into {$class->name}."
            );
        }

        // Defence in depth: StoreEnrollmentRequest already restricts school_class_id to
        // CatalogStatus::ACTIVE, but this method is also called from update paths a future
        // caller might add, so the check is repeated here rather than assumed.
        if ($class->status !== CatalogStatus::ACTIVE) {
            throw new BusinessRuleViolation(
                "The {$class->name} class is not active, so no new enrollment can be made into it."
            );
        }
    }

    /**
     * An enrollment date outside its own session's calendar cannot be right, the same
     * reasoning TermService applies to a term's dates.
     */
    protected function assertDateInsideSession(AcademicSession $session, mixed $date): void
    {
        $date = Carbon::parse($date);

        if ($date->lt($session->start_date) || $date->gt($session->end_date)) {
            throw new BusinessRuleViolation(
                "The enrollment date must fall within {$session->name} ({$session->start_date->toDateString()} to {$session->end_date->toDateString()}).",
                ['enrollment_date' => ["The enrollment date must fall within {$session->name}."]],
            );
        }
    }

    /**
     * Whether a QueryException is the unique(student_id, academic_session_id) index refusing
     * a race, rather than some other integrity failure that should keep propagating as a
     * genuine 500. SQLSTATE 23000 is the portable class for an integrity constraint violation
     * across all four configured drivers (SQLite, MySQL, PostgreSQL, SQL Server); this
     * enrollment is the only unique index this table has, so any 23000 here is that one.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

<?php

namespace App\Services\Staff;

use App\Enums\CatalogStatus;
use App\Enums\TeacherAssignmentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassSubject;
use App\Models\TeacherAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Assigning teaching staff to class subjects, for one academic session at a time.
 *
 * Lives under Services\Staff rather than a new Services\Assignment or Services\Teacher
 * namespace: this is fundamentally a Staff-module concern - which staff member teaches what -
 * built entirely on Module 03's Staff and Module 07's ClassSubject, and giving it a sibling
 * namespace to either would suggest a third, independent domain where none exists.
 */
class TeacherAssignmentService
{
    /**
     * @param  array{teaching_staff_id?: int, class_subject_id?: int, academic_session_id?: int, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, TeacherAssignment>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderByDesc('teacher_assignments.created_at')
            ->orderByDesc('teacher_assignments.id')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<TeacherAssignment>
     */
    protected function query(array $filters): Builder
    {
        return TeacherAssignment::query()
            ->with(['teachingStaff.user', 'classSubject.schoolClass', 'classSubject.subject', 'academicSession'])
            ->when($filters['teaching_staff_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('teacher_assignments.teaching_staff_id', $id))
            ->when($filters['class_subject_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('teacher_assignments.class_subject_id', $id))
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('teacher_assignments.academic_session_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('teacher_assignments.status', $status));
    }

    /**
     * Assign a teacher to a class subject for a session.
     *
     * The class subject's own chain - is IT active, is its CLASS active, is that class's
     * CLASS LEVEL active - is checked here because it needs loaded relations a Form Request
     * rule cannot express without a join: the identical layering EnrollmentService and
     * SubjectService both use for their own class-hierarchy checks. Staff eligibility
     * (exists, TEACHING, actively employed) and the session's own eligibility (exists, not
     * COMPLETED) ARE expressible as plain column exists() rules and are validated entirely
     * in StoreTeacherAssignmentRequest; they are not repeated here.
     *
     * Wrapped in a transaction with the unique index as the final backstop, exactly like
     * EnrollmentService::create() and SubjectService::createClassSubject(): two requests that
     * both pass validation in the same instant would otherwise both attempt the insert, and
     * the loser must see a clear 422.
     *
     * @param  array{teaching_staff_id: int, class_subject_id: int, academic_session_id: int, notes?: string|null}  $attributes
     */
    public function create(array $attributes): TeacherAssignment
    {
        $classSubject = ClassSubject::query()
            ->with('schoolClass.classLevel')
            ->findOrFail($attributes['class_subject_id']);

        $this->assertClassSubjectSelectable($classSubject);

        try {
            return DB::transaction(function () use ($attributes): TeacherAssignment {
                $assignment = new TeacherAssignment;

                $assignment->forceFill([
                    'teaching_staff_id' => $attributes['teaching_staff_id'],
                    'class_subject_id' => $attributes['class_subject_id'],
                    'academic_session_id' => $attributes['academic_session_id'],
                    'notes' => $attributes['notes'] ?? null,
                    'status' => TeacherAssignmentStatus::ACTIVE,
                    'active_marker' => true,
                ])->save();

                return $assignment;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'This class subject already has an active teacher for this academic session. End that assignment first.',
                    ['class_subject_id' => ['This class subject already has an active teacher for this academic session.']],
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Amend an assignment's notes. Nothing else is reachable this way - see
     * UpdateTeacherAssignmentRequest. The assignment itself (staff, class subject, session)
     * is immutable once created, and the status changes only through end()/cancel().
     *
     * A terminal assignment is READ ONLY in full, the identical rule Modules 04-06 apply to
     * their own departed/decided/ended records.
     *
     * @param  array{notes?: string|null}  $attributes
     */
    public function update(TeacherAssignment $assignment, array $attributes): TeacherAssignment
    {
        if ($assignment->isTerminal()) {
            throw new BusinessRuleViolation(
                'This teaching assignment has ended, so the record can no longer be amended.'
            );
        }

        $assignment->forceFill($attributes)->save();

        return $assignment;
    }

    /**
     * Record that the teacher stopped teaching this class subject this session - a real
     * event: they left, were reassigned, or the school appointed someone else mid-year.
     *
     * Clearing active_marker to NULL is what frees the (class_subject_id,
     * academic_session_id) pair for a new assignment to claim - see the migration's own
     * docblock. This is how reassignment happens: end() the old assignment, then create() the
     * new one. There is no separate "reassign" endpoint; the two together already express it
     * without inventing a third, coupled operation.
     */
    public function end(TeacherAssignment $assignment, ?string $notes = null): TeacherAssignment
    {
        $this->assertActive($assignment, 'ended');

        $assignment->forceFill([
            'status' => TeacherAssignmentStatus::ENDED,
            'active_marker' => null,
            'ended_at' => now(),
            'notes' => $notes ?? $assignment->notes,
        ])->save();

        return $assignment;
    }

    /**
     * Void an assignment that should not have been created - the record-preserving
     * replacement for a DELETE endpoint. See TeacherAssignmentController.
     */
    public function cancel(TeacherAssignment $assignment, ?string $notes = null): TeacherAssignment
    {
        $this->assertActive($assignment, 'cancelled');

        $assignment->forceFill([
            'status' => TeacherAssignmentStatus::CANCELLED,
            'active_marker' => null,
            'ended_at' => now(),
            'notes' => $notes ?? $assignment->notes,
        ])->save();

        return $assignment;
    }

    /**
     * The single gate end() and cancel() share, so they cannot drift into two different
     * answers to "can this assignment still change?". Repeating a transition, or attempting
     * the other one, on an already-terminal assignment is refused rather than treated as an
     * idempotent success - the same posture EnrollmentService and AdmissionService both take,
     * for the same reason: this is a one-shot fact about how a responsibility ended, not a
     * reversible toggle.
     */
    protected function assertActive(TeacherAssignment $assignment, string $verb): void
    {
        if (! $assignment->isActive()) {
            throw new BusinessRuleViolation(
                "This teaching assignment has already ended ({$assignment->status->value}), so it cannot be {$verb}."
            );
        }
    }

    /**
     * A class subject's own status is not enough: its class must also be active, and that
     * class's class level must also be active - a class subject can remain nominally ACTIVE
     * after its class is archived (Module 07's own design: the two statuses are independent),
     * so all three are checked independently rather than assumed from the class subject's own
     * status alone. The identical two/three-level guard Module 06 and Module 07 both apply to
     * their own class-hierarchy references.
     */
    protected function assertClassSubjectSelectable(ClassSubject $classSubject): void
    {
        $class = $classSubject->schoolClass;

        if ($classSubject->status !== CatalogStatus::ACTIVE
            || $class->status !== CatalogStatus::ACTIVE
            || ! $class->classLevel->status->isActive()) {
            throw new BusinessRuleViolation(
                'This class subject is not currently available for a new teaching assignment.'
            );
        }
    }

    /**
     * Whether a QueryException is the unique(class_subject_id, academic_session_id,
     * active_marker) index refusing a race, rather than some other integrity failure that
     * should keep propagating as a genuine 500. SQLSTATE 23000 is the portable
     * integrity-violation class across all four configured drivers; this table's only unique
     * index is this one, so any 23000 here is that one - the identical technique
     * EnrollmentService and SubjectService both use for their own unique indexes.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

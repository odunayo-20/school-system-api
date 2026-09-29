<?php

namespace App\Services\Subject;

use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The subject catalogue and the offering of its subjects to classes.
 *
 * One service for both, mirroring AcademicStructureService's own reasoning for
 * ClassLevel/SchoolClass/Section: Subject and ClassSubject are a single two-level hierarchy
 * with a shared rule (CatalogStatus, the same "does the parent still exist and is it
 * selectable" guard) rather than two unrelated catalogues. Splitting them into
 * SubjectService and ClassSubjectService would repeat that shape twice for no benefit.
 */
class SubjectService
{
    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{status?: string, search?: string, code?: string, per_page?: int, active_only?: bool}  $filters
     * @return LengthAwarePaginator<array-key, Subject>
     */
    public function paginateSubjects(array $filters = []): LengthAwarePaginator
    {
        return $this->subjectQuery($filters)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Subject>
     */
    protected function subjectQuery(array $filters): Builder
    {
        return Subject::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['code'] ?? null, fn (Builder $q, string $code): Builder => $q->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))]))
            // active_only backs the "choose a subject" picker a class-subject create form
            // needs, matching the identical flag on class levels, classes and sections.
            ->when($filters['active_only'] ?? false, fn (Builder $q): Builder => $q->selectable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search): Builder => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($search)).'%']));
    }

    public function findSubject(int $id): Subject
    {
        return Subject::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSubject(array $attributes): Subject
    {
        $attributes['status'] ??= CatalogStatus::ACTIVE;

        return Subject::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSubject(Subject $subject, array $attributes): Subject
    {
        $subject->fill($attributes)->save();

        return $subject;
    }

    /**
     * Remove a subject that no class currently offers.
     *
     * The database would refuse this anyway, through the restrictOnDelete foreign key from
     * class_subjects - checking first turns an integrity error into a message that names the
     * problem, the identical convention AcademicStructureService::deleteClassLevel() uses.
     *
     * Deliberately checks for ANY class_subjects row, not only ACTIVE ones: an INACTIVE
     * offering is still a historical fact about a subject that was once taught somewhere,
     * and deleting the subject out from under it would leave that row pointing at nothing.
     */
    public function deleteSubject(Subject $subject): void
    {
        if ($subject->classSubjects()->exists()) {
            throw new BusinessRuleViolation(
                'This subject is still offered to at least one class and cannot be deleted. Remove its class offerings first.'
            );
        }

        $subject->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Class subjects
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{subject_id?: int, school_class_id?: int, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, ClassSubject>
     */
    public function paginateClassSubjects(array $filters = []): LengthAwarePaginator
    {
        return $this->classSubjectQuery($filters)
            ->orderByDesc('class_subjects.created_at')
            ->orderByDesc('class_subjects.id')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ClassSubject>
     */
    protected function classSubjectQuery(array $filters): Builder
    {
        return ClassSubject::query()
            ->with(['schoolClass', 'subject'])
            ->when($filters['subject_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('class_subjects.subject_id', $id))
            ->when($filters['school_class_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('class_subjects.school_class_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('class_subjects.status', $status));
    }

    public function findClassSubject(int $id): ClassSubject
    {
        return ClassSubject::query()->with(['schoolClass', 'subject'])->findOrFail($id);
    }

    /**
     * Offer a subject to a class.
     *
     * Both parents are checked for selectability here, not only in the form request: the
     * request's own exists()->where() rules cover the HTTP path, but repeating the check
     * means the rule holds for any other caller of this service too - the identical
     * reasoning AcademicStructureService::guardSelectableParent() documents for the same
     * kind of check one level up the same hierarchy.
     *
     * The class's own status being ACTIVE is not enough on its own: Module 02 allows a class
     * to remain ACTIVE after its class level has been archived (an amend that merely renames
     * a class in an already-retired level is legitimate housekeeping, per
     * AcademicStructureService::updateClass()'s own comment), so the class level is checked
     * independently - the identical two-level guard Module 06 added for enrollments.
     *
     * Wrapped in a transaction with the unique index as the final backstop, exactly like
     * EnrollmentService::create(): two requests that both pass validation in the same instant
     * would otherwise both attempt the insert, and the loser must see a clear 422.
     *
     * @param  array{school_class_id: int, subject_id: int}  $attributes
     */
    public function createClassSubject(array $attributes): ClassSubject
    {
        $class = SchoolClass::query()->with('classLevel')->findOrFail($attributes['school_class_id']);

        $this->assertClassSelectable($class);

        try {
            return DB::transaction(function () use ($attributes): ClassSubject {
                $classSubject = new ClassSubject;

                $classSubject->forceFill([
                    'school_class_id' => $attributes['school_class_id'],
                    'subject_id' => $attributes['subject_id'],
                    'status' => CatalogStatus::ACTIVE,
                ])->save();

                return $classSubject;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'This subject is already attached to this class.',
                    ['subject_id' => ['This subject is already attached to this class.']],
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Amend a class subject's status. Nothing else is reachable this way - see
     * UpdateClassSubjectRequest. The pairing a class subject names is fixed for its
     * lifetime; only whether it is currently offered can change.
     *
     * @param  array{status?: string}  $attributes
     */
    public function updateClassSubject(ClassSubject $classSubject, array $attributes): ClassSubject
    {
        $classSubject->fill($attributes)->save();

        return $classSubject;
    }

    /**
     * A class must exist, be ACTIVE, and belong to a class level that is also ACTIVE.
     */
    protected function assertClassSelectable(SchoolClass $class): void
    {
        if ($class->status !== CatalogStatus::ACTIVE || ! $class->classLevel->status->isActive()) {
            throw new BusinessRuleViolation(
                "The {$class->name} class is not currently available for new subject offerings."
            );
        }
    }

    /**
     * Whether a QueryException is the unique(school_class_id, subject_id) index refusing a
     * race, rather than some other integrity failure that should keep propagating as a
     * genuine 500. SQLSTATE 23000 is the portable integrity-violation class across all four
     * configured drivers; this table's only unique index is this one, so any 23000 here is
     * that one - the identical technique EnrollmentService uses for its own unique index.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

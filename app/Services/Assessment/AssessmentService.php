<?php

namespace App\Services\Assessment;

use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassSubject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The assessment category catalogue and the assessments configured against it.
 *
 * One service for both, mirroring SubjectService's own reasoning for Subject/ClassSubject:
 * AssessmentType and Assessment are a single two-level hierarchy with a shared rule
 * (CatalogStatus, the same "does the parent still exist and is it selectable" guard) rather
 * than two unrelated catalogues.
 */
class AssessmentService
{
    /*
    |--------------------------------------------------------------------------
    | Assessment types
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{status?: string, search?: string, code?: string, per_page?: int, active_only?: bool}  $filters
     * @return LengthAwarePaginator<array-key, AssessmentType>
     */
    public function paginateAssessmentTypes(array $filters = []): LengthAwarePaginator
    {
        return $this->assessmentTypeQuery($filters)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AssessmentType>
     */
    protected function assessmentTypeQuery(array $filters): Builder
    {
        return AssessmentType::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['code'] ?? null, fn (Builder $q, string $code): Builder => $q->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))]))
            ->when($filters['active_only'] ?? false, fn (Builder $q): Builder => $q->selectable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search): Builder => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($search)).'%']));
    }

    public function findAssessmentType(int $id): AssessmentType
    {
        return AssessmentType::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createAssessmentType(array $attributes): AssessmentType
    {
        $attributes['status'] ??= CatalogStatus::ACTIVE;

        return AssessmentType::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAssessmentType(AssessmentType $assessmentType, array $attributes): AssessmentType
    {
        $assessmentType->fill($attributes)->save();

        return $assessmentType;
    }

    /**
     * Remove a category that no configured assessment currently uses.
     *
     * The database would refuse this anyway, through the restrictOnDelete foreign key from
     * assessments - checking first turns an integrity error into a message that names the
     * problem, the identical convention SubjectService::deleteSubject() uses.
     *
     * Deliberately checks for ANY assessments row, not only ACTIVE ones: an INACTIVE
     * assessment is still a historical fact that once used this category, and deleting the
     * category out from under it would leave that row pointing at nothing.
     */
    public function deleteAssessmentType(AssessmentType $assessmentType): void
    {
        if ($assessmentType->assessments()->exists()) {
            throw new BusinessRuleViolation(
                'This assessment type is still used by at least one configured assessment and cannot be deleted. Retire those assessments first.'
            );
        }

        $assessmentType->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Assessments
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{class_subject_id?: int, term_id?: int, assessment_type_id?: int, academic_session_id?: int, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Assessment>
     */
    public function paginateAssessments(array $filters = []): LengthAwarePaginator
    {
        return $this->assessmentQuery($filters)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Assessment>
     */
    protected function assessmentQuery(array $filters): Builder
    {
        return Assessment::query()
            ->with(['classSubject.schoolClass', 'classSubject.subject', 'term', 'assessmentType'])
            ->when($filters['class_subject_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('assessments.class_subject_id', $id))
            ->when($filters['term_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('assessments.term_id', $id))
            ->when($filters['assessment_type_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('assessments.assessment_type_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('assessments.status', $status))
            // Derived, not stored - see the assessments migration for why there is no
            // academic_session_id column to filter on directly.
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'term',
                fn (Builder $termQuery): Builder => $termQuery->where('academic_session_id', $id)
            ));
    }

    public function findAssessment(int $id): Assessment
    {
        return Assessment::query()->with(['classSubject.schoolClass', 'classSubject.subject', 'term', 'assessmentType'])->findOrFail($id);
    }

    /**
     * Configure an assessment against a class subject and term.
     *
     * The class subject's own chain - is IT active, is its CLASS active, is that class's
     * CLASS LEVEL active - is checked here because it needs loaded relations a Form Request
     * rule cannot express without a join: the identical layering EnrollmentService,
     * SubjectService and TeacherAssignmentService all use for their own class-hierarchy
     * checks. The assessment type's own eligibility (exists, ACTIVE) and the term's own
     * eligibility (exists, not COMPLETED) ARE expressible as plain column exists() rules and
     * are validated entirely in StoreAssessmentRequest; they are not repeated here.
     *
     * Wrapped in a transaction with the unique index as the final backstop, exactly like
     * SubjectService::createClassSubject() and TeacherAssignmentService::create(): two
     * requests that both pass validation in the same instant would otherwise both attempt the
     * insert, and the loser must see a clear 422.
     *
     * @param  array{class_subject_id: int, term_id: int, assessment_type_id: int, name: string, max_score: string|float, weight?: string|float|null, sort_order?: int|null}  $attributes
     */
    public function create(array $attributes): Assessment
    {
        $classSubject = ClassSubject::query()
            ->with('schoolClass.classLevel')
            ->findOrFail($attributes['class_subject_id']);

        $this->assertClassSubjectSelectable($classSubject);

        try {
            return DB::transaction(function () use ($attributes): Assessment {
                $assessment = new Assessment;

                $assessment->forceFill([
                    'class_subject_id' => $attributes['class_subject_id'],
                    'term_id' => $attributes['term_id'],
                    'assessment_type_id' => $attributes['assessment_type_id'],
                    'name' => $attributes['name'],
                    'max_score' => $attributes['max_score'],
                    'weight' => $attributes['weight'] ?? null,
                    'sort_order' => $attributes['sort_order'] ?? 0,
                    'status' => CatalogStatus::ACTIVE,
                ])->save();

                return $assessment;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'An assessment with this name already exists for this class subject and term.',
                    ['name' => ['An assessment with this name already exists for this class subject and term.']],
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Amend an assessment's detail fields. Nothing else is reachable this way - see
     * UpdateAssessmentRequest. The triple it names (class subject, term, category) is fixed
     * for its lifetime; only its display detail and whether it is currently offered can
     * change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Assessment $assessment, array $attributes): Assessment
    {
        try {
            $assessment->fill($attributes)->save();
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'An assessment with this name already exists for this class subject and term.',
                    ['name' => ['An assessment with this name already exists for this class subject and term.']],
                    $e,
                );
            }

            throw $e;
        }

        return $assessment;
    }

    /**
     * A class subject must exist, be ACTIVE, and belong to a class that is also ACTIVE and
     * whose class level is also ACTIVE - a class subject can remain nominally ACTIVE after its
     * class is archived (Module 07's own design: the two statuses are independent), so all
     * three are checked independently rather than assumed from the class subject's own status
     * alone. The identical two/three-level guard Module 06, Module 07 and Module 08 all apply
     * to their own class-hierarchy references.
     */
    protected function assertClassSubjectSelectable(ClassSubject $classSubject): void
    {
        $class = $classSubject->schoolClass;

        if ($classSubject->status !== CatalogStatus::ACTIVE
            || $class->status !== CatalogStatus::ACTIVE
            || ! $class->classLevel->status->isActive()) {
            throw new BusinessRuleViolation(
                'This class subject is not currently available for a new assessment.'
            );
        }
    }

    /**
     * Whether a QueryException is one of this module's own unique indexes refusing a race,
     * rather than some other integrity failure that should keep propagating as a genuine 500.
     * SQLSTATE 23000 is the portable integrity-violation class across all four configured
     * drivers - the identical technique EnrollmentService, SubjectService and
     * TeacherAssignmentService all use for their own unique indexes.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

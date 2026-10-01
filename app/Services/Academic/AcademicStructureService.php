<?php

namespace App\Services\Academic;

use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use App\Models\Section;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The academic structure: class levels, the classes inside them, and the sections inside
 * those classes.
 *
 * One service for all three on purpose. They are a single nested hierarchy with shared
 * rules rather than three unrelated catalogues: each level is scoped to its parent, each
 * one retires with the same CatalogStatus, and each refuses deletion while a child
 * references it. Three services would repeat that shape three times and let the three drift
 * apart, which is exactly the drift a shared CatalogStatus already prevents.
 *
 * Every delete is guarded against removing a record that something else still points at.
 * These three tables are the target of a foreign key from every later module, and a
 * deleted class level would take its classes with it and orphan whatever the student module
 * had already filed against them.
 */
class AcademicStructureService
{
    /**
     * @param  array{status?: string, search?: string, per_page?: int, active_only?: bool}  $filters
     * @return LengthAwarePaginator<array-key, ClassLevel>
     */
    public function paginateClassLevels(array $filters = []): LengthAwarePaginator
    {
        return $this->classLevelQuery($filters)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return Builder<ClassLevel>
     */
    protected function classLevelQuery(array $filters): Builder
    {
        return ClassLevel::query()
            ->withCount('classes')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            // active_only backs the "choose a class level" picker, which must offer only
            // levels a new class can actually be created in.
            ->when($filters['active_only'] ?? false, fn (Builder $q): Builder => $q->selectable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search): Builder => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($search)).'%']));
    }

    public function findClassLevel(int $id): ClassLevel
    {
        return ClassLevel::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createClassLevel(array $attributes): ClassLevel
    {
        $attributes['status'] ??= CatalogStatus::ACTIVE;

        return ClassLevel::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateClassLevel(ClassLevel $classLevel, array $attributes): ClassLevel
    {
        $classLevel->fill($attributes)->save();

        return $classLevel;
    }

    /**
     * Remove a class level that no class belongs to.
     *
     * The database would refuse this anyway, through the restrictOnDelete foreign key from
     * classes. Checking first turns an integrity error into a message that names the
     * problem and can tell the caller how many classes are in the way.
     */
    public function deleteClassLevel(ClassLevel $classLevel): void
    {
        if ($classLevel->classes()->exists()) {
            throw new BusinessRuleViolation(
                'This class level still has classes. Move or delete them first.'
            );
        }

        $classLevel->delete();
    }

    /**
     * @param  array{status?: string, search?: string, per_page?: int, active_only?: bool, class_level_id?: int}  $filters
     * @return LengthAwarePaginator<array-key, SchoolClass>
     */
    public function paginateClasses(array $filters = []): LengthAwarePaginator
    {
        return SchoolClass::query()
            ->with('classLevel')
            ->when($filters['class_level_id'] ?? null, fn (Builder $q, int $levelId): Builder => $q->where('class_level_id', $levelId))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['active_only'] ?? false, fn (Builder $q): Builder => $q->selectable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search): Builder => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($search)).'%']))
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function findClass(int $id): SchoolClass
    {
        return SchoolClass::query()->with('classLevel')->findOrFail($id);
    }

    /**
     * A class is only creatable inside a level that is still in use. Allowing a class to be
     * added to an ARCHIVED level would produce structure that no picker would ever offer,
     * so it could never be seen or amended by anyone.
     *
     * The requests enforce this with an exists() rule so the caller gets a field error, but
     * that only covers the HTTP path. Repeating it here means the rule holds for any other
     * caller of the service too, and means this method's own contract is not a claim the
     * rest of the system has to keep true for it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createClass(array $attributes): SchoolClass
    {
        $this->guardSelectableParent(
            ClassLevel::query(),
            $attributes['class_level_id'] ?? null,
            'class level'
        );

        $attributes['status'] ??= CatalogStatus::ACTIVE;

        return SchoolClass::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateClass(SchoolClass $schoolClass, array $attributes): SchoolClass
    {
        // Only when a level is actually being asserted. Amending the name of a class that
        // still sits in a level which has since been archived is legitimate housekeeping;
        // it is moving a class INTO a retired level that produces unreachable structure.
        if (array_key_exists('class_level_id', $attributes)) {
            $this->guardSelectableParent(
                ClassLevel::query(),
                $attributes['class_level_id'],
                'class level'
            );
        }

        $schoolClass->fill($attributes)->save();

        return $schoolClass;
    }

    public function deleteClass(SchoolClass $schoolClass): void
    {
        if ($schoolClass->sections()->exists()) {
            throw new BusinessRuleViolation(
                'This class still has sections. Move or delete them first.'
            );
        }

        $schoolClass->delete();
    }

    /**
     * @param  array{status?: string, search?: string, per_page?: int, active_only?: bool, school_class_id?: int}  $filters
     * @return LengthAwarePaginator<array-key, Section>
     */
    public function paginateSections(array $filters = []): LengthAwarePaginator
    {
        return Section::query()
            ->with('schoolClass')
            ->when($filters['school_class_id'] ?? null, fn (Builder $q, int $classId): Builder => $q->where('school_class_id', $classId))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['active_only'] ?? false, fn (Builder $q): Builder => $q->selectable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search): Builder => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($search)).'%']))
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function findSection(int $id): Section
    {
        return Section::query()->with('schoolClass')->findOrFail($id);
    }

    /**
     * A section belongs to a class that is still in use, for the same reason a class belongs
     * to a level that is still in use: structure filed under a retired parent is structure
     * no picker offers and no one can reach.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createSection(array $attributes): Section
    {
        $this->guardSelectableParent(
            SchoolClass::query(),
            $attributes['school_class_id'] ?? null,
            'class'
        );

        $attributes['status'] ??= CatalogStatus::ACTIVE;

        return Section::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSection(Section $section, array $attributes): Section
    {
        if (array_key_exists('school_class_id', $attributes)) {
            $this->guardSelectableParent(
                SchoolClass::query(),
                $attributes['school_class_id'],
                'class'
            );
        }

        $section->fill($attributes)->save();

        return $section;
    }

    public function deleteSection(Section $section): void
    {
        $section->delete();
    }

    /**
     * Refuse to file a record under a parent that is missing or retired.
     *
     * @param  Builder<ClassLevel>|Builder<SchoolClass>  $query
     */
    protected function guardSelectableParent(Builder $query, mixed $parentId, string $label): void
    {
        if (is_null($parentId) || $query->selectable()->whereKey($parentId)->doesntExist()) {
            throw new BusinessRuleViolation(
                "The selected {$label} does not exist or is no longer in use."
            );
        }
    }
}

<?php

namespace App\Services\Grading;

use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\GradingScale;
use App\Models\GradingScaleItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reading and writing grading scales, and the one calculation operation this module exposes:
 * percentage in, grade information out.
 *
 * One service for both the scale and its items, mirroring SubjectService's and
 * AssessmentService's own reasoning for their own two-level catalogue/detail pairs: a scale
 * and its items are a single unit, not two independently usable entities - an item has no
 * meaning outside the scale that owns it (see GradingScaleItem's own docblock), so there is no
 * second "GradingScaleItemService" any more than there is a "ClassSubjectItemService".
 *
 * Items are always validated and (re)written as a WHOLE GROUP, never one at a time - see
 * ValidatesGradingScaleRecord for why overlap and duplicate-grade checks need the full set.
 * create() and update() therefore both delete-and-recreate the item set inside one transaction
 * rather than diffing individual rows, the simplest correct implementation of "PUT is a
 * whole-record write" applied to a one-to-many relationship.
 */
class GradingService
{
    /**
     * @param  array{class_level_id?: int, status?: string, active_only?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, GradingScale>
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
     * @return Builder<GradingScale>
     */
    protected function query(array $filters): Builder
    {
        return GradingScale::query()
            ->with('classLevel')
            ->when($filters['class_level_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('class_level_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['active_only'] ?? false, fn (Builder $q): Builder => $q->selectable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search): Builder => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($search)).'%']));
    }

    public function find(int $id): GradingScale
    {
        return GradingScale::query()->with(['classLevel', 'items'])->findOrFail($id);
    }

    /**
     * Configure a new grading scale for a class level. Always created ACTIVE - a scale born
     * INACTIVE would need a second call before it meant anything, the same reasoning
     * StoreClassSubjectRequest and StoreTeacherAssignmentRequest give their own lifecycle
     * fields - which is also what keeps "at most one ACTIVE scale per class level" a plain,
     * unconditional uniqueness check in StoreGradingScaleRequest rather than one that depends
     * on a client-supplied status.
     *
     * Wrapped in a transaction with the unique index as the final backstop, exactly like every
     * prior module's create(): two requests that both pass validation in the same instant
     * would otherwise both attempt the insert, and the loser must see a clear 422.
     *
     * @param  array{class_level_id: int, name: string, code: string, sort_order?: int|null, items: list<array{grade: string, min_percentage: string|float, max_percentage: string|float, grade_point?: string|float|null, remark?: string|null}>}  $attributes
     */
    public function create(array $attributes): GradingScale
    {
        try {
            return DB::transaction(function () use ($attributes): GradingScale {
                $scale = new GradingScale;

                $scale->forceFill([
                    'class_level_id' => $attributes['class_level_id'],
                    'name' => $attributes['name'],
                    'code' => $attributes['code'],
                    'sort_order' => $attributes['sort_order'] ?? 0,
                    'status' => CatalogStatus::ACTIVE,
                ])->save();

                $scale->items()->createMany($this->itemRows($attributes['items']));

                return $scale->load('items');
            });
        } catch (QueryException $e) {
            throw $this->translateUniqueViolation($e);
        }
    }

    /**
     * Amend a scale's name, code, sort order and status, and fully replace its ranges. Nothing
     * else is reachable this way - see UpdateGradingScaleRequest. class_level_id has no key to
     * send at all; the class level a scale applies to is fixed for its lifetime.
     *
     * @param  array{name: string, code: string, sort_order?: int|null, status?: string, items: list<array{grade: string, min_percentage: string|float, max_percentage: string|float, grade_point?: string|float|null, remark?: string|null}>}  $attributes
     */
    public function update(GradingScale $scale, array $attributes): GradingScale
    {
        try {
            return DB::transaction(function () use ($scale, $attributes): GradingScale {
                $scale->fill([
                    'name' => $attributes['name'],
                    'code' => $attributes['code'],
                    'sort_order' => $attributes['sort_order'] ?? $scale->sort_order,
                    'status' => $attributes['status'] ?? $scale->status,
                ])->save();

                $scale->items()->delete();
                $scale->items()->createMany($this->itemRows($attributes['items']));

                return $scale->load('items');
            });
        } catch (QueryException $e) {
            throw $this->translateUniqueViolation($e);
        }
    }

    /**
     * Percentage in, grade information out. A read-only, side-effect-free lookup - it does not
     * touch a Score, a Result, or any other record. Null when no band in this scale covers
     * $percentage: a gap in the scale's own configuration is a legitimate state (see
     * ValidatesGradingScaleRecord), so this returns an explicit "nothing matched" rather than
     * guessing at the nearest band.
     */
    public function calculate(GradingScale $scale, float $percentage): ?GradingScaleItem
    {
        return $scale->items->first(fn (GradingScaleItem $item): bool => $item->covers($percentage));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function itemRows(array $items): array
    {
        return collect($items)->map(fn (array $item): array => [
            'grade' => $item['grade'],
            'min_percentage' => $item['min_percentage'],
            'max_percentage' => $item['max_percentage'],
            'grade_point' => $item['grade_point'] ?? null,
            'remark' => $item['remark'] ?? null,
        ])->all();
    }

    /**
     * Whether a QueryException is one of this table's own unique indexes refusing a race
     * (name, code, or the active_marker slot for this class level), rather than some other
     * integrity failure that should keep propagating as a genuine 500. SQLSTATE 23000 is the
     * portable integrity-violation class across all four configured drivers - the identical
     * technique every prior module's service uses for its own unique index.
     */
    protected function translateUniqueViolation(QueryException $e): Throwable
    {
        if ($e->getCode() !== '23000') {
            return $e;
        }

        return new BusinessRuleViolation(
            'A grading scale with this name or code already exists for this class level, or this class level already has an active grading scale.',
        );
    }
}

<?php

namespace App\Http\Requests\Grading\Concerns;

use App\Enums\CatalogStatus;
use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use App\Models\GradingScale;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of a grading scale payload: identity (name/code/sort_order, reusing Module 02's
 * ValidatesCatalogRecord verbatim, scoped to the class level rather than global), a class
 * level reference (Store-only - see GradingScale's own docblock for why it is immutable), and
 * a full set of percentage bands (Store+Update, always replaced as a whole - see
 * GradingService).
 */
trait ValidatesGradingScaleRecord
{
    use ValidatesCatalogRecord;

    protected function catalogTable(): string
    {
        return 'grading_scales';
    }

    /**
     * The class level this scale applies to. Store-only, and required to exist and be
     * ACTIVE - a class level is the top of its own hierarchy (unlike a class subject, it has
     * no further parent to independently check), so this is the whole reference check.
     *
     * @return array<string, mixed>
     */
    protected function classLevelRule(): array
    {
        return [
            'class_level_id' => [
                'required',
                'integer',
                Rule::exists('class_levels', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
        ];
    }

    /**
     * name/code/sort_order, scoped to the class level rather than global - two different
     * class levels may each have their own "Standard" scale. status is deliberately NOT
     * included here: Store never accepts it (a new scale is always ACTIVE, the same reasoning
     * StoreClassSubjectRequest and StoreTeacherAssignmentRequest give their own lifecycle
     * fields), and Update's own status rule needs the extra "no other active scale for this
     * class level" check catalogRules() cannot express - see activeStatusRule().
     *
     * @return array<string, mixed>
     */
    protected function identityRules(?int $classLevelId, ?int $ignoreId = null): array
    {
        return Arr::except($this->catalogRules('class_level_id', $classLevelId, $ignoreId), ['status']);
    }

    /**
     * Update's own status rule: an ordinary CatalogStatus value, but reactivating (or
     * creating... though Store never reaches this) must not collide with another scale
     * already holding the ACTIVE slot for the SAME class level - the declarative half of the
     * database's own unique(class_level_id, active_marker) index, needing a query no plain
     * Rule::unique expresses cleanly since it only applies when the SUBMITTED value is
     * specifically ACTIVE.
     *
     * @return array<int, mixed>
     */
    protected function activeStatusRule(GradingScale $scale): array
    {
        return [
            'sometimes',
            'string',
            Rule::enum(CatalogStatus::class),
            function (string $attribute, mixed $value, Closure $fail) use ($scale): void {
                if ($value !== CatalogStatus::ACTIVE->value) {
                    return;
                }

                $conflict = GradingScale::query()
                    ->where('class_level_id', $scale->class_level_id)
                    ->where('status', CatalogStatus::ACTIVE->value)
                    ->whereKeyNot($scale->getKey())
                    ->exists();

                if ($conflict) {
                    $fail('Another grading scale is already active for this class level.');
                }
            },
        ];
    }

    /**
     * The percentage bands, validated per-field here (existence, type, plain numeric bounds).
     * Overlap, min<=max and duplicate-grade checks need the WHOLE array compared against
     * itself and live in addItemCoherenceValidation() instead - a Form Request rule runs per
     * field, not across the set.
     *
     * @return array<string, mixed>
     */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.grade' => ['required', 'string', 'max:10'],
            'items.*.min_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.max_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.grade_point' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'items.*.remark' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function gradingScaleMessages(): array
    {
        return array_merge($this->catalogMessages(), [
            'class_level_id.required' => 'A class level is required.',
            'class_level_id.exists' => 'This class level does not exist or is not active.',
            'items.required' => 'At least one grading band is required.',
            'items.array' => 'Grading bands must be a list of rows.',
            'items.min' => 'At least one grading band is required.',
            'items.*.grade.required' => 'Each band requires a grade.',
            'items.*.min_percentage.required' => 'Each band requires a minimum percentage.',
            'items.*.min_percentage.min' => 'The minimum percentage may not be negative.',
            'items.*.min_percentage.max' => 'The minimum percentage may not be greater than 100.',
            'items.*.max_percentage.required' => 'Each band requires a maximum percentage.',
            'items.*.max_percentage.min' => 'The maximum percentage may not be negative.',
            'items.*.max_percentage.max' => 'The maximum percentage may not be greater than 100.',
            'items.*.grade_point.numeric' => 'The grade point must be a number.',
        ]);
    }

    /**
     * Cross-item checks that need the whole "items" array at once, added via the Form
     * Request's own withValidator() hook rather than a per-field rule:
     *
     *  - min_percentage must not exceed max_percentage within the same band.
     *  - no two bands may overlap - inclusive at both ends, so [60,69.99] and [70,100] are
     *    adjacent and valid, but [60,70] and [70,100] both cover 70.00 and are refused.
     *  - no two bands in the same scale may share a grade - see the grading_scale_items
     *    migration's own unique index for the database-level half of this rule.
     *
     * Gaps between bands are deliberately NOT rejected here - see GradingService::calculate()
     * and the Module 11 audit for why an uncovered percentage is a legitimate configuration
     * state, made safe by the calculation endpoint returning an explicit "no match" rather
     * than guessing, not by forbidding the gap at write time.
     */
    protected function addItemCoherenceValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = collect($this->input('items', []))
                ->filter(fn ($item): bool => is_array($item) && isset($item['min_percentage'], $item['max_percentage']))
                ->values();

            $grades = [];

            foreach ($items as $index => $item) {
                $min = (float) $item['min_percentage'];
                $max = (float) $item['max_percentage'];

                if ($min > $max) {
                    $validator->errors()->add(
                        "items.{$index}.min_percentage",
                        'The minimum percentage must not be greater than the maximum percentage.'
                    );
                }

                $grade = isset($item['grade']) ? mb_strtoupper(trim((string) $item['grade'])) : null;

                if ($grade !== null) {
                    if (isset($grades[$grade])) {
                        $validator->errors()->add(
                            "items.{$index}.grade",
                            "The grade \"{$item['grade']}\" is used more than once in this scale."
                        );
                    }

                    $grades[$grade] = true;
                }

                foreach ($items as $otherIndex => $other) {
                    if ($otherIndex <= $index) {
                        continue;
                    }

                    $otherMin = (float) $other['min_percentage'];
                    $otherMax = (float) $other['max_percentage'];

                    // Standard inclusive-interval overlap test: two ranges overlap unless one
                    // ends entirely before the other begins.
                    if ($min <= $otherMax && $otherMin <= $max) {
                        $validator->errors()->add(
                            "items.{$index}.min_percentage",
                            "This band overlaps another band in the same scale (row {$otherIndex})."
                        );
                    }
                }
            }
        });
    }
}

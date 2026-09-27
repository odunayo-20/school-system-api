<?php

namespace App\Http\Requests\Academic\Concerns;

use App\Enums\CatalogStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The shape shared by every record in the academic structure: class levels, classes and
 * sections.
 *
 * Unlike sessions and terms, status IS client-settable here. None of these three is a
 * singleton, so writing a status can never violate a uniqueness constraint, and the three
 * genuinely need to be retired by hand: a class level is ARCHIVED when the school stops
 * running it, a class is INACTIVE while a cohort is not being taught. There is no derived
 * transition for them, because "the current one" is not a concept that applies.
 *
 * The class level is the only one of the three whose name and code are unique on their own;
 * a class is unique within a level and a section within a class, so those add a scoped
 * uniqueness rule on top of these.
 */
trait ValidatesCatalogRecord
{
    /**
     * Normalise the code BEFORE it is validated.
     *
     * As with the session name, the code is uniquely indexed, so it must be folded to upper
     * case before the uniqueness check rather than after it. Normalising in a model mutator
     * would let "pri" pass the check against an existing "PRI" and then collide on the
     * index, reporting a client mistake as a server error.
     */
    public function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge([
                'code' => mb_strtoupper(trim((string) $this->input('code'))),
            ]);
        }
    }

    /**
     * @param  string|null  $parentColumn  the foreign key this record hangs from, or null
     *                                     when the record is unique on its own
     * @param  int|null  $ignoreId  the record being amended
     * @return array<string, mixed>
     */
    protected function catalogRules(?string $parentColumn = null, ?int $parentId = null, ?int $ignoreId = null): array
    {
        // A class level's name and code are unique outright. A class's are unique within
        // its level and a section's within its class, because "A" in Primary 5 and "A" in
        // JSS 1 are unrelated rows. One rule builder covers both by scoping the lookup
        // only when there is a parent to scope it to.
        //
        // The return type is the concrete Rules\Unique, not the Rule interface: Rule::unique()
        // builds an Illuminate\Validation\Rules\Unique, which does not implement
        // Illuminate\Validation\Rule, so typing this as Rule is a TypeError on every create.
        $unique = function (string $column) use ($parentColumn, $parentId, $ignoreId): Unique {
            $rule = Rule::unique($this->catalogTable(), $column)->ignore($ignoreId);

            return $parentColumn === null ? $rule : $rule->where($parentColumn, $parentId);
        };

        return [
            'name' => ['required', 'string', 'max:100', $unique('name')],
            'code' => ['required', 'string', 'max:20', $unique('code')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
        ];
    }

    /**
     * The table this request validates, so one implementation can build both the global and
     * the parent-scoped uniqueness rules.
     */
    abstract protected function catalogTable(): string;

    /**
     * @return array<string, string>
     */
    protected function catalogMessages(): array
    {
        return [
            'name.required' => 'The name is required.',
            'name.unique' => 'A record with this name already exists here.',
            'code.required' => 'The code is required. It is the short identifier used in lists and reports.',
            'code.unique' => 'A record with this code already exists here.',
            'code.max' => 'The code may not be longer than 20 characters.',
            'sort_order.integer' => 'The sort order must be a whole number.',
            'sort_order.min' => 'The sort order may not be negative.',
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogAttributes(): array
    {
        return $this->validated();
    }
}

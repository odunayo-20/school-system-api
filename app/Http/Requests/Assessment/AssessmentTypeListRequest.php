<?php

namespace App\Http\Requests\Assessment;

use App\Enums\CatalogStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the assessment type list: status, an exact code lookup, and active_only for the
 * "choose a category" picker an assessment create form needs - matching the identical flag
 * SubjectListRequest already offers for the same reason. `search` is inherited from
 * ListRequest and matches the category's name, exactly as it does for subjects.
 */
class AssessmentTypeListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            'code' => ['sometimes', 'string', 'max:20'],
            'active_only' => self::booleanFlag(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
            'code.max' => 'The code may not be longer than 20 characters.',
            'active_only.in' => 'The active only filter must be true or false.',
        ]);
    }
}

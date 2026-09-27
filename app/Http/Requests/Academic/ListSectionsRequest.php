<?php

namespace App\Http\Requests\Academic;

use App\Enums\CatalogStatus;
use Illuminate\Validation\Rule;

/**
 * Filters for the section list.
 */
class ListSectionsRequest extends AcademicListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            'active_only' => self::booleanFlag(),
            'school_class_id' => ['sometimes', 'integer', 'exists:classes,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
            'active_only.in' => 'The active only filter must be true or false.',
            'school_class_id.integer' => 'The class must be identified by its id.',
            'school_class_id.exists' => 'The selected class does not exist.',
        ]);
    }
}

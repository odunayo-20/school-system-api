<?php

namespace App\Http\Requests\Academic;

use App\Enums\CatalogStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the class list.
 */
class ListSchoolClassesRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            'active_only' => self::booleanFlag(),
            'class_level_id' => ['sometimes', 'integer', 'exists:class_levels,id'],
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
            'class_level_id.integer' => 'The class level must be identified by its id.',
            'class_level_id.exists' => 'The selected class level does not exist.',
        ]);
    }
}

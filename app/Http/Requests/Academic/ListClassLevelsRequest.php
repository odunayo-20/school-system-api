<?php

namespace App\Http\Requests\Academic;

use App\Enums\CatalogStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the class level list.
 */
class ListClassLevelsRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            // active_only backs the "choose a class level" picker, which must offer only the
            // levels a new class can actually be created in.
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
            'active_only.in' => 'The active only filter must be true or false.',
        ]);
    }
}

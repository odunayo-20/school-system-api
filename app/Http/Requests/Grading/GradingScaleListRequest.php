<?php

namespace App\Http\Requests\Grading;

use App\Enums\CatalogStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the grading scale list: which class level (the scale's whole scope), status,
 * and active_only for the "choose a scale" picker a future result-compilation module's admin
 * screen will need - matching the identical flag SubjectListRequest and
 * AssessmentTypeListRequest already offer for the same reason. `search` is inherited from
 * ListRequest and matches the scale's name.
 */
class GradingScaleListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'class_level_id' => ['sometimes', 'integer', Rule::exists('class_levels', 'id')],
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            'active_only' => self::booleanFlag(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'class_level_id.exists' => 'This class level does not exist.',
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
            'active_only.in' => 'The active only filter must be true or false.',
        ]);
    }
}

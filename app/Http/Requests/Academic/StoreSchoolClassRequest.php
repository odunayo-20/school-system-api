<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a class to a class level.
 *
 * The level is accepted in the body rather than taken from a route segment, so the whole
 * catalog can share one URL space: POST /api/v1/classes with a class_level_id. The level's
 * own existence and its ACTIVE status are resolved with exists() so a missing or retired
 * level is reported as a field error, not as a 500 from a failed foreign key.
 */
class StoreSchoolClassRequest extends FormRequest
{
    use ValidatesCatalogRecord;

    public function authorize(): bool
    {
        return true;
    }

    protected function catalogTable(): string
    {
        return 'classes';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $classLevelId = (int) $this->input('class_level_id');

        $rules = $this->catalogRules('class_level_id', $classLevelId);

        $rules['class_level_id'] = [
            'required',
            'integer',
            Rule::exists('class_levels', 'id')->where('status', 'ACTIVE'),
        ];

        // Inserted so the field is validated in a sensible order in the error output:
        // the parent first, since nothing else can be checked without it.
        return ['class_level_id' => $rules['class_level_id']] + $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->catalogMessages(), [
            'class_level_id.required' => 'A class level is required. A class always belongs to one.',
            'class_level_id.exists' => 'The selected class level does not exist or is not active.',
        ]);
    }
}

<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a section to a class. As with classes, the parent comes in the body so the whole
 * catalog shares one URL space.
 */
class StoreSectionRequest extends FormRequest
{
    use ValidatesCatalogRecord;

    public function authorize(): bool
    {
        return true;
    }

    protected function catalogTable(): string
    {
        return 'sections';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $classId = (int) $this->input('school_class_id');

        $rules = $this->catalogRules('school_class_id', $classId);

        $rules['school_class_id'] = [
            'required',
            'integer',
            Rule::exists('classes', 'id')->where('status', 'ACTIVE'),
        ];

        return ['school_class_id' => $rules['school_class_id']] + $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->catalogMessages(), [
            'school_class_id.required' => 'A class is required. A section always belongs to one.',
            'school_class_id.exists' => 'The selected class does not exist or is not active.',
        ]);
    }
}

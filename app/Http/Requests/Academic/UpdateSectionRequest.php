<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amend a section, optionally moving it to a different class.
 *
 * Moving is allowed and does not have to be refused: a section has no meaning outside the
 * class it splits, but a class with no students yet can legitimately be reorganised. The
 * uniqueness scope follows the class the section ends up in.
 */
class UpdateSectionRequest extends FormRequest
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
        // Bound to the model by the controller's {section} parameter; the key has to be
        // spelled exactly as the route registers it or the record is never ignored and every
        // amend collides with itself.
        /** @var Section $section */
        $section = $this->route('section');

        $targetClassId = $this->filled('school_class_id')
            ? (int) $this->input('school_class_id')
            : $section->school_class_id;

        $rules = $this->catalogRules('school_class_id', $targetClassId, $section->getKey());

        $rules['school_class_id'] = [
            'sometimes',
            'integer',
            Rule::exists('classes', 'id')->where('status', 'ACTIVE'),
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->catalogMessages(), [
            'school_class_id.exists' => 'The selected class does not exist or is not active.',
        ]);
    }
}

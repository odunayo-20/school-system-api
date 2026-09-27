<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amend a class, optionally moving it to a different class level.
 *
 * The uniqueness scope follows the level the class ends up in, so moving a class into a
 * level that already holds its name is refused rather than being allowed to collide.
 */
class UpdateSchoolClassRequest extends FormRequest
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
        // Bound to the model by the controller's {schoolClass} parameter; the key has to be
        // spelled exactly as the route registers it or the record is never ignored and every
        // amend collides with itself.
        /** @var SchoolClass $schoolClass */
        $schoolClass = $this->route('schoolClass');

        // class_level_id is optional on an amend: omitting it means "leave it where it is",
        // which is what makes the uniqueness scope the class's CURRENT level.
        $targetLevelId = $this->filled('class_level_id')
            ? (int) $this->input('class_level_id')
            : $schoolClass->class_level_id;

        $rules = $this->catalogRules('class_level_id', $targetLevelId, $schoolClass->getKey());

        $rules['class_level_id'] = [
            'sometimes',
            'integer',
            Rule::exists('class_levels', 'id')->where('status', 'ACTIVE'),
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->catalogMessages(), [
            'class_level_id.exists' => 'The selected class level does not exist or is not active.',
        ]);
    }
}

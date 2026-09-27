<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use App\Models\ClassLevel;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a class level. The record is ignored in the uniqueness check so re-saving a level
 * with its own name and code does not collide with itself.
 */
class UpdateClassLevelRequest extends FormRequest
{
    use ValidatesCatalogRecord;

    public function authorize(): bool
    {
        return true;
    }

    protected function catalogTable(): string
    {
        return 'class_levels';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Bound to the model by the controller's {classLevel} parameter; the key has to be
        // spelled exactly as the route registers it or the record is never ignored and every
        // amend collides with itself.
        /** @var ClassLevel $classLevel */
        $classLevel = $this->route('classLevel');

        return $this->catalogRules(ignoreId: $classLevel->getKey());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->catalogMessages();
    }
}

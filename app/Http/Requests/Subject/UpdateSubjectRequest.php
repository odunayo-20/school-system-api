<?php

namespace App\Http\Requests\Subject;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a subject. The record is ignored in the uniqueness check so re-saving a subject with
 * its own name and code does not collide with itself - the identical convention
 * UpdateClassLevelRequest uses.
 */
class UpdateSubjectRequest extends FormRequest
{
    use ValidatesCatalogRecord;

    public function authorize(): bool
    {
        return true;
    }

    protected function catalogTable(): string
    {
        return 'subjects';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return $this->catalogRules(ignoreId: $subject->getKey());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->catalogMessages();
    }
}

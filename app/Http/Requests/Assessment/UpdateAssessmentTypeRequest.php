<?php

namespace App\Http\Requests\Assessment;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use App\Models\AssessmentType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a category. The record is ignored in the uniqueness check so re-saving a category
 * with its own name and code does not collide with itself - the identical convention
 * UpdateSubjectRequest uses.
 */
class UpdateAssessmentTypeRequest extends FormRequest
{
    use ValidatesCatalogRecord;

    public function authorize(): bool
    {
        return true;
    }

    protected function catalogTable(): string
    {
        return 'assessment_types';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var AssessmentType $assessmentType */
        $assessmentType = $this->route('assessmentType');

        return $this->catalogRules(ignoreId: $assessmentType->getKey());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->catalogMessages();
    }
}

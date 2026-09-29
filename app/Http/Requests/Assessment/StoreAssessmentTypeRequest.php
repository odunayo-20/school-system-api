<?php

namespace App\Http\Requests\Assessment;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a category to the assessment type catalogue.
 *
 * Reuses Module 02's ValidatesCatalogRecord directly rather than a new trait: a category's
 * shape - a globally unique name and code, an optional display order, a CatalogStatus - is
 * structurally identical to a class level's or a subject's, and duplicating that trait here
 * would only invite the three to drift apart.
 */
class StoreAssessmentTypeRequest extends FormRequest
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
        return $this->catalogRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->catalogMessages();
    }
}

<?php

namespace App\Http\Requests\Subject;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a subject to the catalogue.
 *
 * Reuses Module 02's ValidatesCatalogRecord directly rather than a new trait: a subject's
 * shape - a globally unique name and code, an optional display order, a CatalogStatus - is
 * structurally identical to a class level's, and duplicating that trait here would only
 * invite the two to drift apart.
 */
class StoreSubjectRequest extends FormRequest
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

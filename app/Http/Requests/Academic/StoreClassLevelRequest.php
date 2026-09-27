<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A class level's name and code are unique across the school: a level is a top level of
 * the structure, so "Primary" can exist once and only once.
 */
class StoreClassLevelRequest extends FormRequest
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

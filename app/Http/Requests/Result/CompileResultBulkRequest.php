<?php

namespace App\Http\Requests\Result;

use App\Enums\CatalogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compile (or recompile) every currently active enrollment in a class subject's own class,
 * for one term - a whole class roster at once. See ResultService::compileClassSubject() for
 * why this is deliberately NOT all-or-nothing the way Module 10's bulk score entry is: each
 * student's outcome (including a perfectly legitimate INCOMPLETE) is independent of every
 * other student's.
 */
class CompileResultBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_subject_id' => [
                'required',
                'integer',
                Rule::exists('class_subjects', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'term_id' => ['required', 'integer', Rule::exists('terms', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_subject_id.required' => 'A class subject is required.',
            'class_subject_id.exists' => 'This class subject does not exist or is not active.',
            'term_id.required' => 'A term is required.',
            'term_id.exists' => 'This term does not exist.',
        ];
    }

    /**
     * @return array{class_subject_id: int, term_id: int}
     */
    public function bulkCompileAttributes(): array
    {
        return $this->safe()->only(['class_subject_id', 'term_id']);
    }
}

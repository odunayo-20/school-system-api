<?php

namespace App\Http\Requests\Result;

use App\Enums\CatalogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compile (or recompile) one enrollment's result for one class subject and term.
 *
 * The ONLY input this request accepts is the identifying triple - never a total, a
 * percentage, a grade or a grade point. Every calculated value is derived server-side by
 * ResultService from Assessments, Scores and the applicable GradingScale; nothing here reads
 * a client-supplied one, matching the identical discipline StoreScoreRequest already
 * established for max_score.
 *
 * enrollment_id deliberately does NOT require an ACTIVE status, unlike StoreScoreRequest's own
 * enrollment_id rule - see ResultService::assertContextMatches() for why a withdrawn
 * enrollment's result remains compilable. term_id likewise carries no "not COMPLETED"
 * restriction, matching every score-entry rule since Module 10: compiling after a term closes
 * is the normal, expected workflow, not an exception to guard against.
 */
class CompileResultRequest extends FormRequest
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
            'enrollment_id' => ['required', 'integer', Rule::exists('enrollments', 'id')],
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
            'enrollment_id.required' => 'An enrollment is required.',
            'enrollment_id.exists' => 'This enrollment does not exist.',
            'class_subject_id.required' => 'A class subject is required.',
            'class_subject_id.exists' => 'This class subject does not exist or is not active.',
            'term_id.required' => 'A term is required.',
            'term_id.exists' => 'This term does not exist.',
        ];
    }

    /**
     * @return array{enrollment_id: int, class_subject_id: int, term_id: int}
     */
    public function compileAttributes(): array
    {
        return $this->safe()->only(['enrollment_id', 'class_subject_id', 'term_id']);
    }
}

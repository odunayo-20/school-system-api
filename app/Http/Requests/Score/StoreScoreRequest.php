<?php

namespace App\Http\Requests\Score;

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Requests\Score\Concerns\ValidatesScoreRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a single student's mark against an assessment.
 *
 * assessment_id and enrollment_id are Store-only, matching the identical split every module
 * since Enrollment applies between its own Store-only reference rules and a Store+Update
 * detail rule set: the pair a score names is fixed for its lifetime, so UpdateScoreRequest
 * never accepts either.
 */
class StoreScoreRequest extends FormRequest
{
    use ValidatesScoreRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assessmentId = $this->input('assessment_id');

        return [
            'assessment_id' => [
                'required',
                'integer',
                Rule::exists('assessments', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'enrollment_id' => [
                'required',
                'integer',
                Rule::exists('enrollments', 'id')->where('status', EnrollmentStatus::ACTIVE->value),
                // At most one score per assessment per enrollment - the form request's own
                // half of the rule the database's unique(assessment_id, enrollment_id) index
                // backstops, the identical scoped-uniqueness technique every prior module uses
                // for its own unique index.
                Rule::unique('scores', 'enrollment_id')
                    ->where(fn ($query) => $query->where('assessment_id', $assessmentId)),
            ],
            'score' => $this->scoreValueRule($assessmentId),
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->scoreMessages(), [
            'assessment_id.required' => 'An assessment is required.',
            'assessment_id.exists' => 'This assessment does not exist or is not active.',
            'enrollment_id.required' => 'An enrollment is required.',
            'enrollment_id.exists' => 'This enrollment does not exist or is not active.',
            'enrollment_id.unique' => 'This enrollment already has a score for this assessment.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function scoreAttributes(): array
    {
        return $this->safe()->only(['assessment_id', 'enrollment_id', 'score', 'remarks']);
    }
}

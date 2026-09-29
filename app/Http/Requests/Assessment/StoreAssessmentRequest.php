<?php

namespace App\Http\Requests\Assessment;

use App\Http\Requests\Assessment\Concerns\ValidatesAssessmentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Configure a new assessment against a class subject and term.
 *
 * No `status` field is required: a new assessment is always ACTIVE - a row born INACTIVE
 * would need a second call before it meant anything, the same reasoning StoreClassSubjectRequest
 * and StoreEnrollmentRequest give their own lifecycle fields. `status` is still accepted here
 * (via assessmentDetailRules()) so an operator entering assessments ahead of time can mark one
 * INACTIVE from creation if they choose - the service defaults it to ACTIVE when omitted.
 */
class StoreAssessmentRequest extends FormRequest
{
    use ValidatesAssessmentRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            $this->assessmentReferenceRules(),
            $this->assessmentDetailRules($this->input('class_subject_id'), $this->input('term_id'))
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->assessmentMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function assessmentAttributes(): array
    {
        return $this->safe()->only([
            'class_subject_id', 'term_id', 'assessment_type_id',
            'name', 'max_score', 'weight', 'sort_order', 'status',
        ]);
    }
}

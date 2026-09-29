<?php

namespace App\Http\Requests\Assessment;

use App\Http\Requests\Assessment\Concerns\ValidatesAssessmentRecord;
use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend an assessment's detail fields: name, max score, weight, sort order and status.
 *
 * class_subject_id, term_id and assessment_type_id are NOT accepted here at all - see
 * Assessment's own docblock for why the triple an assessment names has no path to change once
 * created. The uniqueness check on `name` is scoped to the record's OWN (immutable)
 * class_subject_id and term_id, read from the route model rather than the request body, since
 * neither is ever present in an update payload.
 */
class UpdateAssessmentRequest extends FormRequest
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
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $this->assessmentDetailRules($assessment->class_subject_id, $assessment->term_id, $assessment->getKey());
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
        return $this->safe()->only(['name', 'max_score', 'weight', 'sort_order', 'status']);
    }
}

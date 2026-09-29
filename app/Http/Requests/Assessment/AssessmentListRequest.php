<?php

namespace App\Http\Requests\Assessment;

use App\Enums\CatalogStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the assessment list: the questions this table actually answers - "what is
 * configured for this class subject" (class_subject_id), "what is configured for this term"
 * (term_id), "which assessments are of this category" (assessment_type_id), and a derived
 * "what is configured for this session" (academic_session_id, resolved through term) - plus
 * status.
 *
 * There is no `search`. An assessment's own name field is short ("CA 1") and the canonical
 * filters already answer the realistic question - "what is configured for THIS class subject
 * and term" - far more precisely than a text search across every assessment in the school
 * would; the identical reasoning ValidatesEnrollmentFilters, ClassSubjectListRequest and
 * TeacherAssignmentListRequest all give for their own lists.
 */
class AssessmentListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'class_subject_id' => ['sometimes', 'integer', Rule::exists('class_subjects', 'id')],
            'term_id' => ['sometimes', 'integer', Rule::exists('terms', 'id')],
            'assessment_type_id' => ['sometimes', 'integer', Rule::exists('assessment_types', 'id')],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            // Overrides the base ListRequest rule for this one key - see
            // ValidatesEnrollmentFilters for why this technique (this array is merged AFTER
            // the base rules) is how a list request without a text field refuses `search`
            // with a clear 422 instead of silently accepting and ignoring it.
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'class_subject_id.exists' => 'This class subject does not exist.',
            'term_id.exists' => 'This term does not exist.',
            'assessment_type_id.exists' => 'This assessment type does not exist.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
            'search.prohibited' => 'Assessments cannot be searched by text. Filter by class_subject_id, term_id, assessment_type_id, academic_session_id or status instead.',
        ]);
    }
}

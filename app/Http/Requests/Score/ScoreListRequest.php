<?php

namespace App\Http\Requests\Score;

use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the score list: every angle Module 10's own brief names - the assessment, its
 * type, the enrollment, the student, the class, the section, the subject, the academic
 * session, the term.
 *
 * Every filter here is validated for existence only. AUTHORIZATION IS NOT THIS REQUEST'S JOB:
 * a filter narrows the results within whatever the acting user is already scoped to see -
 * ScoreService::query() applies that scope UNCONDITIONALLY before any filter is applied, so a
 * teacher sending ?student_id=999 for a student outside their own assignments gets an empty
 * list, never someone else's data. See the Module 10 audit.
 *
 * There is no `search`. A score holds no text field of its own - `remarks` is free text kept
 * with one specific record, not a field a list is searched by - so the canonical filters above
 * already answer every realistic question, the identical reasoning
 * TeacherAssignmentListRequest and AssessmentListRequest both give their own lists.
 */
class ScoreListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'assessment_id' => ['sometimes', 'integer', Rule::exists('assessments', 'id')],
            'assessment_type_id' => ['sometimes', 'integer', Rule::exists('assessment_types', 'id')],
            'enrollment_id' => ['sometimes', 'integer', Rule::exists('enrollments', 'id')],
            'student_id' => ['sometimes', 'integer', Rule::exists('students', 'id')],
            'school_class_id' => ['sometimes', 'integer', Rule::exists('classes', 'id')],
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')],
            'subject_id' => ['sometimes', 'integer', Rule::exists('subjects', 'id')],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'term_id' => ['sometimes', 'integer', Rule::exists('terms', 'id')],
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
            'assessment_id.exists' => 'This assessment does not exist.',
            'assessment_type_id.exists' => 'This assessment type does not exist.',
            'enrollment_id.exists' => 'This enrollment does not exist.',
            'student_id.exists' => 'This student does not exist.',
            'school_class_id.exists' => 'This class does not exist.',
            'section_id.exists' => 'This section does not exist.',
            'subject_id.exists' => 'This subject does not exist.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'term_id.exists' => 'This term does not exist.',
            'search.prohibited' => 'Scores cannot be searched by text. Filter by assessment_id, assessment_type_id, enrollment_id, student_id, school_class_id, section_id, subject_id, academic_session_id or term_id instead.',
        ]);
    }
}

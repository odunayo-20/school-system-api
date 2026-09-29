<?php

namespace App\Http\Requests\Staff;

use App\Enums\TeacherAssignmentStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the teacher assignment list: the three questions this table actually answers -
 * "what does this teacher teach" (teaching_staff_id), "who teaches this class subject"
 * (class_subject_id), "what was assigned this session" (academic_session_id) - plus status.
 *
 * There is no `search`. An assignment holds no text field of its own; the canonical filters
 * already answer every realistic question, and a client wanting "which classes does Staff #7
 * teach" or "who teaches JSS 2 Mathematics" uses teaching_staff_id or class_subject_id rather
 * than a dedicated convenience endpoint - see the Module 08 audit for why none was built.
 */
class TeacherAssignmentListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'teaching_staff_id' => ['sometimes', 'integer', Rule::exists('staff', 'id')],
            'class_subject_id' => ['sometimes', 'integer', Rule::exists('class_subjects', 'id')],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'status' => ['sometimes', 'string', Rule::enum(TeacherAssignmentStatus::class)],
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
            'teaching_staff_id.exists' => 'This staff member does not exist.',
            'class_subject_id.exists' => 'This class subject does not exist.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'status.enum' => 'The status must be one of: '.implode(', ', TeacherAssignmentStatus::values()).'.',
            'search.prohibited' => 'Assignments cannot be searched by text. Filter by teaching_staff_id, class_subject_id, academic_session_id or status instead.',
        ]);
    }
}

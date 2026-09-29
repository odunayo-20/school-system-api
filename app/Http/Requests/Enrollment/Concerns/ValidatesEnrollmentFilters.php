<?php

namespace App\Http\Requests\Enrollment\Concerns;

use App\Enums\EnrollmentStatus;
use Illuminate\Validation\Rule;

/**
 * Filters for the enrollment list: the four questions this table actually answers.
 *
 * There is no `search`. Unlike the student roll or the admissions queue, an enrollment holds
 * no text field of its own - no name, no reference number (see the enrollments migration and
 * the Module 06 audit for why neither exists) - so a text search here could only mean
 * searching the LINKED student's name, which would need a join into a table this module does
 * not own the search semantics of. The four FK filters already answer every realistic
 * question this list needs to: "this student's placement history" (student_id), "this
 * session's intake" (academic_session_id), "this class's roster" (school_class_id, optionally
 * narrowed further by section_id).
 */
trait ValidatesEnrollmentFilters
{
    /**
     * @return array<string, mixed>
     */
    protected function enrollmentFilterRules(): array
    {
        return [
            'student_id' => ['sometimes', 'integer', Rule::exists('students', 'id')],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'school_class_id' => ['sometimes', 'integer', Rule::exists('classes', 'id')],
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')],
            'status' => ['sometimes', 'string', Rule::enum(EnrollmentStatus::class)],
            // Overrides the base ListRequest rule for this one key (this array is merged
            // AFTER it - see ListRequest::rules()). There is no text field on this table to
            // search - see the trait docblock - so a client sending `search` gets a clear
            // 422 naming the field, per "invalid filters must be rejected rather than
            // silently ignored", instead of a query string that is accepted and quietly does
            // nothing.
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function enrollmentFilterMessages(): array
    {
        return [
            'student_id.exists' => 'This student does not exist.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'school_class_id.exists' => 'This class does not exist.',
            'section_id.exists' => 'This section does not exist.',
            'status.enum' => 'The status must be one of: '.implode(', ', EnrollmentStatus::values()).'.',
            'search.prohibited' => 'Enrollments cannot be searched by text. Filter by student_id, academic_session_id, school_class_id, section_id or status instead.',
        ];
    }
}

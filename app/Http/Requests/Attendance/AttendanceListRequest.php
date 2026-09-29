<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the attendance list: every angle this module's own brief names - the enrollment,
 * the student, the class, the section, the session, the term, a single date, a date range, and
 * the status.
 *
 * One list endpoint serves "class attendance for a date" (school_class_id + section_id + date),
 * "student attendance history" (student_id, or enrollment_id) and "date range" (date_from/
 * date_to) alike - the brief's own instruction not to create a separate endpoint per angle
 * when one filterable list already answers all of them, the identical shape ScoreListRequest
 * already established for Module 10.
 *
 * AUTHORIZATION IS NOT THIS REQUEST'S JOB. AttendanceService::query() applies the acting
 * teacher's own class scope UNCONDITIONALLY before any filter here is applied, so a filter
 * narrows within that scope; it never replaces it - see the service's own docblock.
 *
 * There is no `search` - an attendance mark holds no text field worth indexing beyond its own
 * `remarks`, matching ScoreListRequest's identical reasoning.
 */
class AttendanceListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'enrollment_id' => ['sometimes', 'integer', Rule::exists('enrollments', 'id')],
            'student_id' => ['sometimes', 'integer', Rule::exists('students', 'id')],
            'school_class_id' => ['sometimes', 'integer', Rule::exists('classes', 'id')],
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'term_id' => ['sometimes', 'integer', Rule::exists('terms', 'id')],
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'status' => ['sometimes', 'string', Rule::enum(AttendanceStatus::class)],
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'enrollment_id.exists' => 'This enrollment does not exist.',
            'student_id.exists' => 'This student does not exist.',
            'school_class_id.exists' => 'This class does not exist.',
            'section_id.exists' => 'This section does not exist.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'term_id.exists' => 'This term does not exist.',
            'date_to.after_or_equal' => 'The end date cannot be before the start date.',
            'status.enum' => 'The attendance status must be one of: '.implode(', ', AttendanceStatus::values()).'.',
            'search.prohibited' => 'Attendance cannot be searched by text. Filter by enrollment_id, student_id, school_class_id, section_id, academic_session_id, term_id, date, date_from, date_to or status instead.',
        ]);
    }
}

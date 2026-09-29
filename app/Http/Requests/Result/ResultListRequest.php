<?php

namespace App\Http\Requests\Result;

use App\Enums\ResultStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the result list: the same angles Module 10's own ScoreListRequest exposes -
 * enrollment, class subject, term, student, class, section, subject, academic session - plus
 * status.
 *
 * Every filter is validated for existence only. AUTHORIZATION IS NOT THIS REQUEST'S JOB: a
 * filter narrows the results within whatever the acting user is already scoped to see -
 * ResultService::query() applies that scope UNCONDITIONALLY before any filter is applied, so a
 * teacher sending ?student_id=999 for a student outside their own assignments gets an empty
 * list, never someone else's data. See the Module 12 audit.
 *
 * There is no `search`. A result holds no text field of its own - the canonical filters
 * already answer every realistic question, the identical reasoning ScoreListRequest gives its
 * own list.
 */
class ResultListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'enrollment_id' => ['sometimes', 'integer', Rule::exists('enrollments', 'id')],
            'class_subject_id' => ['sometimes', 'integer', Rule::exists('class_subjects', 'id')],
            'term_id' => ['sometimes', 'integer', Rule::exists('terms', 'id')],
            'student_id' => ['sometimes', 'integer', Rule::exists('students', 'id')],
            'school_class_id' => ['sometimes', 'integer', Rule::exists('classes', 'id')],
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')],
            'subject_id' => ['sometimes', 'integer', Rule::exists('subjects', 'id')],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'status' => ['sometimes', 'string', Rule::enum(ResultStatus::class)],
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
            'class_subject_id.exists' => 'This class subject does not exist.',
            'term_id.exists' => 'This term does not exist.',
            'student_id.exists' => 'This student does not exist.',
            'school_class_id.exists' => 'This class does not exist.',
            'section_id.exists' => 'This section does not exist.',
            'subject_id.exists' => 'This subject does not exist.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'status.enum' => 'The status must be one of: '.implode(', ', ResultStatus::values()).'.',
            'search.prohibited' => 'Results cannot be searched by text. Filter by enrollment_id, class_subject_id, term_id, student_id, school_class_id, section_id, subject_id, academic_session_id or status instead.',
        ]);
    }
}

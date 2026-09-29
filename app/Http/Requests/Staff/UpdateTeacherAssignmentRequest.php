<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\Staff\Concerns\ValidatesTeacherAssignmentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend an active assignment's notes. Nothing else.
 *
 * This request has NO `teaching_staff_id`, `class_subject_id`, `academic_session_id` or
 * `status` key - not "ignored", genuinely absent, the identical protection
 * UpdateEnrollmentRequest gives its own placement fields. The assignment this row names is
 * fixed for its lifetime; a reassignment is end() the old row and create() a new one, never an
 * edit to this one.
 *
 * TeacherAssignmentService::update() separately refuses to amend a terminal (ENDED/CANCELLED)
 * assignment at all, which a validation rule cannot express because it cannot see the record's
 * current status.
 */
class UpdateTeacherAssignmentRequest extends FormRequest
{
    use ValidatesTeacherAssignmentRecord;

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
            'notes' => $this->assignmentNotesRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->assignmentMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function assignmentAttributes(): array
    {
        return $this->safe()->only(['notes']);
    }
}

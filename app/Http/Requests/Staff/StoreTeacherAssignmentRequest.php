<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\Staff\Concerns\ValidatesTeacherAssignmentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign a teacher to a class subject for an academic session.
 *
 * No `status` field: a new assignment is always ACTIVE - a row born ENDED or CANCELLED would
 * be a contradiction, the same reasoning StoreEnrollmentRequest and StoreClassSubjectRequest
 * give their own lifecycle fields.
 */
class StoreTeacherAssignmentRequest extends FormRequest
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
        return array_merge($this->assignmentReferenceRules(), [
            'notes' => $this->assignmentNotesRule(),
        ]);
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
        return $this->safe()->only(['teaching_staff_id', 'class_subject_id', 'academic_session_id', 'notes']);
    }
}

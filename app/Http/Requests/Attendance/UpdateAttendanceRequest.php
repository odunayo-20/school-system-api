<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correct a mark's status or remarks. enrollment_id, academic_session_id, school_class_id,
 * section_id and date are NOT accepted here at all - see Attendance's own docblock for why
 * the placement and day a mark names has no path to change once created. This is both a
 * data-integrity and an authorization concern: without this, a PUT could turn "Student A's
 * mark for JSS 2A on 2026-09-29" into a mark for an entirely different class by simply naming
 * different ids, silently bypassing the teacher-assignment scope create() enforces on the
 * ORIGINAL class.
 */
class UpdateAttendanceRequest extends FormRequest
{
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
            'status' => ['required', 'string', Rule::enum(AttendanceStatus::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'An attendance status is required.',
            'status.enum' => 'The attendance status must be one of: '.implode(', ', AttendanceStatus::values()).'.',
            'remarks.max' => 'Remarks may not be longer than 1000 characters.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attendanceAttributes(): array
    {
        return $this->safe()->only(['status', 'remarks']);
    }
}

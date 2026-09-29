<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Requests\Attendance\Concerns\ValidatesAttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a single attendance mark.
 *
 * academic_session_id/school_class_id/section_id are required here even though they are also
 * reachable through the named enrollment - the client is never trusted to establish the
 * relationship merely by naming an enrollment id (see the brief's own IDOR warning);
 * AttendanceService::assertContextMatches() re-verifies every one of the four references
 * against the enrollment's own actual values before anything is written.
 *
 * A duplicate (enrollment_id, date) is refused here with a friendly 422 - the form request's
 * own half of the database's unique(enrollment_id, date) index; see AttendanceService for why
 * this entry point REJECTS a duplicate while the bulk entry point updates one in place.
 */
class StoreAttendanceRequest extends FormRequest
{
    use ValidatesAttendanceRecord;

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
            'enrollment_id' => [
                'required',
                'integer',
                Rule::exists('enrollments', 'id')->where('status', EnrollmentStatus::ACTIVE->value),
                Rule::unique('attendances', 'enrollment_id')
                    ->where(fn ($query) => $query->whereDate('date', $this->input('date'))),
            ],
            'academic_session_id' => $this->academicSessionRule(),
            'school_class_id' => $this->schoolClassRule(),
            'section_id' => $this->sectionRule(),
            'date' => $this->attendanceDateRule(),
            'status' => $this->attendanceStatusRule(),
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->attendanceContextMessages(), [
            'enrollment_id.required' => 'An enrollment is required.',
            'enrollment_id.exists' => 'This enrollment does not exist or is not active.',
            'enrollment_id.unique' => 'This enrollment already has an attendance record for this date.',
            'status.required' => 'An attendance status is required.',
            'status.enum' => 'The attendance status must be one of: '.implode(', ', AttendanceStatus::values()).'.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function attendanceAttributes(): array
    {
        return $this->safe()->only([
            'enrollment_id', 'academic_session_id', 'school_class_id', 'section_id', 'date', 'status', 'remarks',
        ]);
    }
}

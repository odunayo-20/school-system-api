<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Requests\Attendance\Concerns\ValidatesAttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a whole class register for one date in one call - one academic session, class,
 * section and date shared at the top level, many students underneath.
 *
 * What this request CANNOT express - whether a row's enrollment genuinely belongs to the
 * stated class/section/session - needs a loaded relation per row and is re-checked in
 * AttendanceService::createBulk(), exactly like the single-mark path and exactly like Module
 * 10's own StoreScoreBulkRequest/ScoreService pair.
 */
class StoreAttendanceBulkRequest extends FormRequest
{
    use ValidatesAttendanceRecord;

    /**
     * A generous ceiling above any single class roster, and low enough that one request
     * cannot become an unbounded write - the identical reasoning
     * StoreScoreBulkRequest::MAX_ROWS gives its own ceiling.
     */
    public const MAX_ROWS = 100;

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
            'academic_session_id' => $this->academicSessionRule(),
            'school_class_id' => $this->schoolClassRule(),
            'section_id' => $this->sectionRule(),
            'date' => $this->attendanceDateRule(),
            'attendances' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'attendances.*.enrollment_id' => [
                'required',
                'integer',
                // A client submitting the same enrollment twice in one batch is refused here,
                // without a query - the identical array-uniqueness technique
                // StoreScoreBulkRequest already uses for its own rows.
                'distinct',
                Rule::exists('enrollments', 'id')->where('status', EnrollmentStatus::ACTIVE->value),
            ],
            'attendances.*.status' => ['required', 'string', Rule::enum(AttendanceStatus::class)],
            'attendances.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->attendanceContextMessages(), [
            'attendances.required' => 'At least one attendance record is required.',
            'attendances.array' => 'Attendances must be a list of rows.',
            'attendances.min' => 'At least one attendance record is required.',
            'attendances.max' => 'A batch may contain at most '.self::MAX_ROWS.' attendance records.',
            'attendances.*.enrollment_id.required' => 'Each row requires an enrollment.',
            'attendances.*.enrollment_id.distinct' => 'The same enrollment appears more than once in this batch.',
            'attendances.*.enrollment_id.exists' => 'This enrollment does not exist or is not active.',
            'attendances.*.status.required' => 'Each row requires an attendance status.',
            'attendances.*.status.enum' => 'The attendance status must be one of: '.implode(', ', AttendanceStatus::values()).'.',
        ]);
    }

    /**
     * @return array{academic_session_id: int, school_class_id: int, section_id: int, date: string, attendances: list<array{enrollment_id: int, status: string, remarks?: string|null}>}
     */
    public function bulkAttributes(): array
    {
        return $this->safe()->only(['academic_session_id', 'school_class_id', 'section_id', 'date', 'attendances']);
    }
}

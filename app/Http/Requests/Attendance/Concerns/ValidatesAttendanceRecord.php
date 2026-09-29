<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Enums\AcademicSessionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\CatalogStatus;
use Illuminate\Validation\Rule;

/**
 * The shared shape of "which class register, which date" - identical between a single
 * create and a bulk create, since both name exactly one academic session, class, section and
 * date; only the enrollment/status/remarks vary per mark. Kept as one trait so the two request
 * classes cannot drift apart on what counts as a valid context, the same reason
 * ValidatesScoreRecord exists for scores.
 *
 * academic_session_id must not be COMPLETED - a closed academic year has no open register left
 * to add a fresh mark to, the identical posture PromoteStudentRequest already takes toward its
 * own target session. school_class_id/section_id must both be ACTIVE, and the section must
 * genuinely belong to the named class - what StoreEnrollmentRequest and PromoteStudentRequest
 * already validate for their own class/section pair, applied here identically.
 */
trait ValidatesAttendanceRecord
{
    /**
     * @return array<int, mixed>
     */
    protected function academicSessionRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('academic_sessions', 'id')->whereNot('status', AcademicSessionStatus::COMPLETED->value),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function schoolClassRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('classes', 'id')->where('status', CatalogStatus::ACTIVE->value),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function sectionRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('sections', 'id')
                ->where('status', CatalogStatus::ACTIVE->value)
                ->where('school_class_id', $this->input('school_class_id')),
        ];
    }

    /**
     * Never in the future: a school records what already happened, not a date yet to come.
     *
     * @return array<int, mixed>
     */
    protected function attendanceDateRule(): array
    {
        return ['required', 'date', 'before_or_equal:today'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function attendanceStatusRule(): array
    {
        return ['required', 'string', Rule::enum(AttendanceStatus::class)];
    }

    /**
     * @return array<string, string>
     */
    protected function attendanceContextMessages(): array
    {
        return [
            'academic_session_id.required' => 'An academic session is required.',
            'academic_session_id.exists' => 'This academic session does not exist or has already been completed.',
            'school_class_id.required' => 'A class is required.',
            'school_class_id.exists' => 'This class does not exist or is not active.',
            'section_id.required' => 'A section is required.',
            'section_id.exists' => 'This section does not exist, is not active, or does not belong to the selected class.',
            'date.required' => 'The attendance date is required.',
            'date.date' => 'The attendance date must be a valid date.',
            'date.before_or_equal' => 'The attendance date cannot be in the future.',
            'remarks.max' => 'Remarks may not be longer than 1000 characters.',
        ];
    }
}

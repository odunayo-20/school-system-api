<?php

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\Enrollment\Concerns\ValidatesEnrollmentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Place a student in a class and section for an academic session.
 *
 * No `status` field: a new enrollment is always ACTIVE - a placement born WITHDRAWN or
 * CANCELLED would be a contradiction, the same reasoning StoreStudentRequest and
 * StoreAdmissionRequest give their own lifecycle fields.
 */
class StoreEnrollmentRequest extends FormRequest
{
    use ValidatesEnrollmentRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->enrollmentReferenceRules(), $this->enrollmentDetailRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->enrollmentMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function enrollmentAttributes(): array
    {
        return $this->safe()->only([
            'student_id',
            'academic_session_id',
            'school_class_id',
            'section_id',
            'enrollment_date',
            'notes',
        ]);
    }
}

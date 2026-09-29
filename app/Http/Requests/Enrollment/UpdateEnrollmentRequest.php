<?php

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\Enrollment\Concerns\ValidatesEnrollmentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend an active enrollment's date and notes. Nothing else.
 *
 * This request has NO `student_id`, `academic_session_id`, `school_class_id`, `section_id` or
 * `status` key - not "ignored", genuinely absent, the identical protection
 * UpdateAdmissionRequest gives its own status field. The placement an enrollment records is
 * authoritative academic history the moment it is created; a PUT that could repoint it would
 * let "fix a typo" and "move a student to a different class" collide in one endpoint with one
 * permission, which is exactly the ambiguity a dedicated transfer operation exists to avoid.
 * This module does not build that operation - see the Module 06 audit - so today there is
 * simply no path to it at all.
 *
 * EnrollmentService::update() separately refuses to amend a terminal (WITHDRAWN/CANCELLED)
 * enrollment in full, which a validation rule cannot express because it cannot see the
 * record's current status - the same layering Student/Staff/Admission all use for their own
 * terminal rules.
 */
class UpdateEnrollmentRequest extends FormRequest
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
        return $this->enrollmentDetailRules();
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
        return $this->safe()->only(['enrollment_date', 'notes']);
    }
}

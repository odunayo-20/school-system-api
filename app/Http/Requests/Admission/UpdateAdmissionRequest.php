<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\Admission\Concerns\ValidatesAdmissionRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a pending admission. PUT only, a whole-record write: first_name and
 * academic_session_id are required so a client cannot half-update the record.
 *
 * There is NO status field here, and this is stricter than UpdateStudentRequest, which does
 * accept one. A pupil's roll status is ordinary record-keeping (Module 04's own reasoning);
 * an admission's status is a decision with a side effect - admitting creates a Student inside
 * a transaction - and the brief is explicit that a PUT must not be able to reach a workflow
 * transition by amending a field. admit()/reject()/withdraw() are the only doors to status,
 * each behind its own permission.
 *
 * There is also no student_id, for the identical reason StoreAdmissionRequest has none: no
 * request in this module ever reaches that column.
 *
 * AdmissionService::update() separately refuses to amend a decided (terminal) admission at
 * all, which a validation rule cannot express because it cannot see the record's current
 * status - the same layering StudentService and StaffService use for their own terminal
 * rules.
 */
class UpdateAdmissionRequest extends FormRequest
{
    use ValidatesAdmissionRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->admissionIdentityRules(), [
            'admission_number' => $this->admissionNumberRules(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->admissionIdentityMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function admissionAttributes(): array
    {
        return $this->safe()->only([
            'admission_number',
            'academic_session_id',
            'entry_class_level_id',
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'gender',
            'notes',
        ]);
    }
}

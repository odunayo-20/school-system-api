<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\Admission\Concerns\ValidatesAdmissionRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Record a new admission.
 *
 * Notice what is absent, because the absences are the design:
 *
 *  - No status. A new admission is PENDING - a record born already ADMITTED would need a
 *    Student that does not exist yet, and a record born already REJECTED or WITHDRAWN would
 *    be a decision with nothing to found it. See AdmissionService::create().
 *
 *  - No student_id. Every admission starts from someone who is not yet a pupil; the row this
 *    creates gets a Student only if and when it is admitted. See the migration's note on why
 *    this column has no path a request can reach.
 *
 *  - No class_id, section_id or anything that would make this the authoritative placement.
 *    entry_class_level_id is accepted, but it is a fact about what the applicant is seeking,
 *    not an enrollment - see ValidatesAdmissionRecord.
 */
class StoreAdmissionRequest extends FormRequest
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
     * The fields this endpoint can write, named for what it is rather than attributes() or
     * data() so the set is visible in one place - the same convention as
     * StoreStudentRequest::studentAttributes().
     *
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

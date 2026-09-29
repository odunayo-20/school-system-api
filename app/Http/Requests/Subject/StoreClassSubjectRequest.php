<?php

namespace App\Http\Requests\Subject;

use App\Http\Requests\Subject\Concerns\ValidatesClassSubjectRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Offer a subject to a class.
 *
 * No `status` field: a new offering is always ACTIVE - a row born INACTIVE would need a
 * second call before it meant anything, the same reasoning StoreEnrollmentRequest and
 * StoreAdmissionRequest give their own lifecycle fields.
 */
class StoreClassSubjectRequest extends FormRequest
{
    use ValidatesClassSubjectRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->classSubjectReferenceRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->classSubjectMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function classSubjectAttributes(): array
    {
        return $this->safe()->only(['school_class_id', 'subject_id']);
    }
}

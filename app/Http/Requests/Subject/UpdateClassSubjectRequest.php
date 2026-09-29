<?php

namespace App\Http\Requests\Subject;

use App\Http\Requests\Subject\Concerns\ValidatesClassSubjectRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a class subject. The ONLY field this request accepts is status - see ClassSubject's
 * own docblock for why the pairing itself (school_class_id, subject_id) has no path to
 * change once created.
 *
 * Unlike UpdateAdmissionRequest/UpdateEnrollmentRequest, status IS reachable here, through
 * the ordinary amend rather than a dedicated workflow endpoint. A class subject's status is
 * a plain, freely reversible toggle - "is this currently offered" - matching
 * CatalogStatus's own semantics on class levels, classes and sections, not a one-shot
 * decision like an admission or an enrollment ending. There is nothing to protect a
 * dedicated endpoint would add here that the ordinary amend does not already give.
 */
class UpdateClassSubjectRequest extends FormRequest
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
        return [
            'status' => $this->classSubjectStatusRule(),
        ];
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
        return $this->safe()->only(['status']);
    }
}

<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesAcademicSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a session's name and dates. Status is not accepted: see ValidatesAcademicSession.
 *
 * The service additionally refuses any change to a COMPLETED session, because its dates are
 * the frame later modules file results and promotion against.
 */
class UpdateAcademicSessionRequest extends FormRequest
{
    use ValidatesAcademicSession;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sessionRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->sessionMessages();
    }
}

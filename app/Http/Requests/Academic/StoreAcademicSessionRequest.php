<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesAcademicSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create an academic session. The session is always created UPCOMING; the activate
 * endpoint is the only way to make one current.
 */
class StoreAcademicSessionRequest extends FormRequest
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

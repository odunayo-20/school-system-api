<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'The email address is required.',
            'email.email' => 'The email address must be a valid email address.',
        ];
    }

    /**
     * The response to this endpoint is deliberately identical whether or not the
     * address belongs to an account, so the address is normalised in one place only.
     */
    public function email(): string
    {
        return mb_strtolower(trim($this->string('email')->toString()));
    }
}

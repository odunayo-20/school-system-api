<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
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
            'password.required' => 'The password is required.',
        ];
    }

    /**
     * Lower-cased and trimmed so authentication is case-insensitive regardless of the
     * database collation.
     */
    public function credentials(): array
    {
        return [
            'email' => mb_strtolower(trim($this->string('email')->toString())),
            'password' => $this->string('password')->toString(),
        ];
    }
}

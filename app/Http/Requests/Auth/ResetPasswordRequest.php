<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordRule;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::make()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(PasswordRule::messages(), [
            'token.required' => 'The password reset token is required.',
            'email.required' => 'The email address is required.',
            'email.email' => 'The email address must be a valid email address.',
            'password.required' => 'The password is required.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => mb_strtolower(trim($this->string('email')->toString())),
            'password' => $this->string('password')->toString(),
        ];
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }
}

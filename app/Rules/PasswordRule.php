<?php

namespace App\Rules;

use Illuminate\Validation\Rules\Password as BasePassword;

/**
 * The single definition of what an acceptable password looks like. Every place that
 * sets a password (registration, password reset, administrative user creation in a
 * later module) must use these rules so the policy cannot drift.
 */
final class PasswordRule
{
    /**
     * @return BasePassword
     */
    public static function make(): BasePassword
    {
        return BasePassword::min(8)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * @return list<string>
     */
    public static function messages(): array
    {
        return [
            'password.min' => 'The password must be at least :min characters.',
            'password.letters' => 'The password must contain at least one letter.',
            'password.mixed' => 'The password must contain at least one uppercase and one lowercase letter.',
            'password.numbers' => 'The password must contain at least one number.',
            'password.symbols' => 'The password must contain at least one symbol.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}

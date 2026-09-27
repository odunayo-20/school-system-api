<?php

namespace App\Http\Requests\Academic;

use App\Enums\SchoolStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `permission:school.update` route middleware, which
     * runs before this request is ever resolved, so it is not repeated here.
     */
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
            'name' => ['required', 'string', 'max:150'],
            'short_name' => ['required', 'string', 'max:50'],
            'motto' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'principal_name' => ['nullable', 'string', 'max:150'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::enum(SchoolStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The school name is required.',
            'short_name.required' => 'The school short name is required.',
            'website.url' => 'The website must be a valid URL, including the scheme, for example https://example.test.',
            'status.enum' => 'The status must be one of: '.implode(', ', SchoolStatus::values()).'.',
        ];
    }
}

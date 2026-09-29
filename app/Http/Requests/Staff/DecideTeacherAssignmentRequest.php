<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional note attached to an end or a cancel. Shared by both, matching
 * DecideAdmissionRequest/DecideEnrollmentRequest's identical reasoning: each endpoint accepts
 * exactly one optional field with exactly one rule.
 */
class DecideTeacherAssignmentRequest extends FormRequest
{
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
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.max' => 'Notes may not be longer than 1000 characters.',
        ];
    }

    public function notes(): ?string
    {
        return $this->validated('notes');
    }
}

<?php

namespace App\Http\Requests\Admission;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional note attached to a reject or a withdraw. Shared by both rather than split into
 * two near-identical request classes, because the two endpoints accept exactly one optional
 * field with exactly one rule.
 *
 * admit() takes no request class: there is nothing for a client to supply beyond the route
 * parameter itself, and adding an empty request class would be a file that exists only to be
 * empty.
 */
class DecideAdmissionRequest extends FormRequest
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

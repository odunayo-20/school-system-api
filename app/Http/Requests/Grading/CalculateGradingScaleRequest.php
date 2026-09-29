<?php

namespace App\Http\Requests\Grading;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A percentage to look up against one grading scale. The server calculates every derived
 * value; this is the only input the operation takes - see the Module 11 audit for why a
 * client-supplied grade, grade point or remark is never accepted anywhere in this module.
 */
class CalculateGradingScaleRequest extends FormRequest
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
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'percentage.required' => 'A percentage is required.',
            'percentage.numeric' => 'The percentage must be a number.',
            'percentage.min' => 'The percentage may not be negative.',
            'percentage.max' => 'The percentage may not be greater than 100.',
        ];
    }

    public function percentage(): float
    {
        return (float) $this->validated('percentage');
    }
}

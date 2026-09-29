<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The attendance summary is always scoped to exactly one enrollment - one student's placement
 * for one academic session - never the whole school or a whole class in one call. A per-class
 * or per-school aggregate is a different, unbounded shape this module's brief does not ask
 * for; see AttendanceService::summary()'s own docblock for the percentage definition this
 * request's filters feed into.
 */
class AttendanceSummaryRequest extends FormRequest
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
            'enrollment_id' => ['required', 'integer', Rule::exists('enrollments', 'id')],
            'term_id' => ['sometimes', 'integer', Rule::exists('terms', 'id')],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enrollment_id.required' => 'An enrollment is required.',
            'enrollment_id.exists' => 'This enrollment does not exist.',
            'term_id.exists' => 'This term does not exist.',
            'date_to.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryFilters(): array
    {
        return $this->validated();
    }
}

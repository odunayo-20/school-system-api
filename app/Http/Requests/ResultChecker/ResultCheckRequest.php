<?php

namespace App\Http\Requests\ResultChecker;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The three facts a public, unauthenticated caller must supply to check one term's result:
 * the student's own registration number, their date of birth (something a parent/guardian
 * already knows, not a value this module distributes), and the term being checked.
 *
 * `student_number` is deliberately NOT validated with exists:students,student_number here -
 * see ResultCheckerController. A distinct "no such student" error for that field, versus a
 * generic mismatch for a wrong date of birth, would let a caller enumerate valid student
 * numbers one guess at a time. Both failure modes, plus "no enrollment for that term" and "no
 * published result yet", collapse to the identical 404 the controller returns.
 *
 * `term_id` IS validated with exists:terms,id: a term's existence is public timetable
 * information (every school session/term is visible to any authenticated caller through
 * Module 02), not a fact about a particular student, so confirming it exists leaks nothing.
 */
class ResultCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The student number is folded to the same canonical form Student::studentNumber()
     * stores it in - trimmed, upper-cased - so "stu-0001" and "STU-0001" are treated as the
     * same lookup rather than one of them silently never matching.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('student_number')) {
            $this->merge([
                'student_number' => mb_strtoupper(trim((string) $this->input('student_number'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'term_id' => ['required', 'integer', 'exists:terms,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_number.required' => 'The student number is required.',
            'date_of_birth.required' => 'The date of birth is required.',
            'date_of_birth.date' => 'The date of birth must be a valid date.',
            'term_id.required' => 'The term is required.',
            'term_id.exists' => 'This term does not exist.',
        ];
    }
}

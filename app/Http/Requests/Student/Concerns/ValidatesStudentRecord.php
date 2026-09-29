<?php

namespace App\Http\Requests\Student\Concerns;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use Illuminate\Validation\Rule;

/**
 * The shape of a pupil record, shared by the create and amend requests so the two cannot
 * drift apart.
 *
 * Normalisation happens in prepareForValidation(), never only in a model mutator. The
 * student number is uniquely indexed, so if validation saw "stu-01" and the model stored
 * "STU-01", a second pupil numbered "STU-01" would pass the uniqueness check and then
 * collide on the index, surfacing a client's typo as a 500. Normalising first means the
 * value that is checked and the value that will be stored are the same string.
 * Student::studentNumber() repeats the fold for writers that never pass through a request.
 */
trait ValidatesStudentRecord
{
    /**
     * Normalise the student number and the name parts BEFORE validation.
     *
     * Only a present-and-not-empty value is touched, so an absent optional field stays
     * absent and "last_name: null" is not turned into the string "null".
     */
    public function prepareForValidation(): void
    {
        $this->normaliseStudentInput();
    }

    /**
     * The fold itself, separated so a request with extra fields to normalise can call this
     * and then its own rather than re-implementing (or forgetting) this part.
     */
    protected function normaliseStudentInput(): void
    {
        $normalised = [];

        // The student number is the one field whose spelling is normalised into a canonical
        // form rather than merely trimmed: " stu-01 ", "STU-01" and "Stu-01" are one number,
        // and storing three spellings of it would let the unique index admit duplicates and
        // make the roll show the same child twice.
        if ($this->filled('student_number')) {
            $normalised['student_number'] = mb_strtoupper(trim((string) $this->input('student_number')));
        }

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if ($this->filled($field)) {
                $normalised[$field] = trim((string) $this->input($field));
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * The identity fields shared by create and amend.
     *
     * first_name is required and the other two name parts are not. A pupil with a single
     * name is a real case, and inventing a surname to satisfy a NOT NULL column would put
     * fabricated data on a child's permanent record - the opposite of what a required field
     * is supposed to guarantee. The one name that must be there is the one the school will
     * actually call the child by, and staff can cope with a missing surname.
     *
     * @return array<string, mixed>
     */
    protected function studentIdentityRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', Rule::enum(Gender::class)],
        ];
    }

    /**
     * The student number is optional. When a client omits it the service derives one from
     * the pupil's own primary key, so a caller who has no numbering scheme in mind still
     * ends up with a unique, readable number.
     *
     * This is a genuinely optional field rather than a mandatory one in disguise, which is
     * why the index is nullable: a client-supplied number is a courtesy for a school that
     * has a numbering scheme of its own, and a derived one is the fallback.
     *
     * @return array<int, mixed>
     */
    protected function studentNumberRules(): array
    {
        return [
            'nullable',
            'string',
            'max:50',
            // Scoped to the record being amended so re-saving a pupil with their own number
            // does not collide with themselves, while two different children still cannot
            // share one. The route parameter is {student}, matching the controller
            // signature; the key must be spelled exactly as registered or the record is not
            // ignored and every amend collides with itself.
            Rule::unique('students', 'student_number')->ignore($this->route('student')),
        ];
    }

    /**
     * The status rule, used only by the amend request.
     *
     * Create does NOT accept a status: a pupil is put on the roll to be taught, so a new
     * record is ACTIVE. A pupil born INACTIVE or WITHDRAWN would be a record that needs a
     * second call before it means anything, and "add a child to the roll, already withdrawn"
     * is a contradiction rather than a state.
     *
     * @return array<int, mixed>
     */
    protected function studentStatusRule(): array
    {
        return ['sometimes', 'string', Rule::enum(StudentStatus::class)];
    }

    /**
     * @return array<string, string>
     */
    protected function studentIdentityMessages(): array
    {
        return [
            'first_name.required' => 'The pupil\'s first name is required.',
            'first_name.max' => 'The first name may not be longer than 100 characters.',
            'middle_name.max' => 'The middle name may not be longer than 100 characters.',
            'last_name.max' => 'The last name may not be longer than 100 characters.',
            'student_number.max' => 'The student number may not be longer than 50 characters.',
            'student_number.unique' => 'This student number is already assigned to another pupil.',
            'date_of_birth.date' => 'The date of birth must be a valid date.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'gender.enum' => 'The gender must be one of: '.implode(', ', Gender::values()).'.',
            'status.enum' => 'The status must be one of: '.implode(', ', StudentStatus::values()).'.',
        ];
    }
}

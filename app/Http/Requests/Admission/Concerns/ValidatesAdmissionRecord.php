<?php

namespace App\Http\Requests\Admission\Concerns;

use App\Enums\AcademicSessionStatus;
use App\Enums\CatalogStatus;
use App\Enums\Gender;
use Illuminate\Validation\Rule;

/**
 * The shape of an admission payload, shared by the create and amend requests so the two
 * cannot drift apart - the same structure Module 04 used for ValidatesStudentRecord, for the
 * same reason.
 *
 * Deliberately NO status field anywhere in this trait. An admission's status changes only
 * through admit()/reject()/withdraw(), never through a mass-assigned create or amend - see
 * the admissions migration and AdmissionService. A rule enumerating "status is not accepted"
 * would be pointless: there is no key to accept in the first place.
 */
trait ValidatesAdmissionRecord
{
    /**
     * Normalise the admission number and the name parts before validation, for the identical
     * reason ValidatesStudentRecord does: the value that is checked for uniqueness must be
     * the value that is stored, or a client's "adm-01" could pass a check against a stored
     * "ADM-01" and then collide on the index as a 500.
     */
    public function prepareForValidation(): void
    {
        $normalised = [];

        if ($this->filled('admission_number')) {
            $normalised['admission_number'] = mb_strtoupper(trim((string) $this->input('admission_number')));
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
     * The applicant's identity, and the intake being applied for.
     *
     * first_name is required, the other two name parts are not - a single-name applicant is
     * as real here as it is on the student roll, and the same field carries the same reason.
     *
     * academic_session_id is required and restricted to a session that is not COMPLETED: a
     * completed session is history, and admitting a new applicant into a year that has
     * already run is not a real intake. This is an ordinary `exists` rule with a `where`
     * clause, not a second status system - the same technique the class level check below
     * uses, and the one TermService could not use only because its own rule needed a date
     * comparison rather than a status match.
     *
     * entry_class_level_id is optional - a school may record an application before a level
     * has been decided - and when present must name a class level that is actually
     * selectable (CatalogStatus::ACTIVE), not one that has been retired.
     *
     * @return array<string, mixed>
     */
    protected function admissionIdentityRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', Rule::enum(Gender::class)],
            'academic_session_id' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')
                    ->whereNot('status', AcademicSessionStatus::COMPLETED->value),
            ],
            'entry_class_level_id' => [
                'nullable',
                'integer',
                Rule::exists('class_levels', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The admission's own reference. Optional, exactly like student_number: a school with
     * its own scheme supplies one, and AdmissionService derives one from the record's own
     * primary key otherwise.
     *
     * @return array<int, mixed>
     */
    protected function admissionNumberRules(): array
    {
        return [
            'nullable',
            'string',
            'max:50',
            // Scoped to the record being amended, matching the route parameter {admission}.
            Rule::unique('admissions', 'admission_number')->ignore($this->route('admission')),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function admissionIdentityMessages(): array
    {
        return [
            'first_name.required' => 'The applicant\'s first name is required.',
            'first_name.max' => 'The first name may not be longer than 100 characters.',
            'middle_name.max' => 'The middle name may not be longer than 100 characters.',
            'last_name.max' => 'The last name may not be longer than 100 characters.',
            'date_of_birth.date' => 'The date of birth must be a valid date.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'gender.enum' => 'The gender must be one of: '.implode(', ', Gender::values()).'.',
            'academic_session_id.required' => 'The academic session being applied for is required.',
            'academic_session_id.exists' => 'This academic session does not exist or is no longer open for admissions.',
            'entry_class_level_id.exists' => 'This class level does not exist or is not currently active.',
            'notes.max' => 'Notes may not be longer than 1000 characters.',
            'admission_number.max' => 'The admission number may not be longer than 50 characters.',
            'admission_number.unique' => 'This admission number is already assigned to another admission.',
        ];
    }
}

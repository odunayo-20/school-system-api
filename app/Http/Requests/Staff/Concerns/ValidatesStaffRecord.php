<?php

namespace App\Http\Requests\Staff\Concerns;

use App\Enums\EmploymentStatus;
use App\Enums\StaffType;
use Illuminate\Validation\Rule;

/**
 * The shape of a staff record, shared by the create and amend requests so the two cannot
 * drift apart.
 *
 * Normalisation happens in prepareForValidation(), never only in a model mutator. The staff
 * number is uniquely indexed, so if validation saw "sta-01" and the model stored "STA-01",
 * a second staff member numbered "STA-01" would pass the uniqueness check and then collide
 * on the index, surfacing a client's typo as a 500. Normalising first means the value that
 * is checked and the value that is stored are the same string. Staff::staffNumber() repeats
 * the fold for writers that never pass through a request.
 */
trait ValidatesStaffRecord
{
    /**
     * Normalise the staff number, name, phone and designation BEFORE validation.
     *
     * Only a present-and-not-empty value is touched, so an absent optional field stays
     * absent and "phone: null" is not turned into the string "null".
     */
    public function prepareForValidation(): void
    {
        $this->normaliseStaffInput();
    }

    /**
     * The fold itself, separated so a request with extra fields to normalise can call this
     * and then its own rather than re-implementing (or forgetting) this part.
     */
    protected function normaliseStaffInput(): void
    {
        $normalised = [];

        // The staff number is the one field whose spelling is normalised into a canonical
        // form rather than merely trimmed: " sta-01 ", "STA-01" and "Sta-01" are one
        // number, and storing three spellings of it would make the unique index admit
        // duplicates and a list show the same person three times.
        if ($this->filled('staff_number')) {
            $normalised['staff_number'] = mb_strtoupper(trim((string) $this->input('staff_number')));
        }

        foreach (['name', 'phone', 'designation'] as $field) {
            if ($this->filled($field)) {
                $normalised[$field] = trim((string) $this->input($field));
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * The employment fields shared by create and amend.
     *
     * @return array<string, mixed>
     */
    protected function staffEmploymentRules(): array
    {
        return [
            'staff_type' => ['required', 'string', Rule::enum(StaffType::class)],
            'staff_number' => $this->staffNumberRules(),
            'employment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'designation' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The staff number is optional. When a client omits it the service derives one from the
     * id of the account it is creating, so a caller who has no numbering scheme in mind
     * still ends up with a unique, readable number.
     *
     * @return array<int, mixed>
     */
    protected function staffNumberRules(): array
    {
        return [
            'nullable',
            'string',
            'max:50',
            // Scoped to the record being amended so re-saving a staff member with their own
            // number does not collide with themselves, while two different people still
            // cannot share one. The route parameter is {staff}, matching the controller
            // signature; the key must be spelled exactly as registered or the record is not
            // ignored and every amend collides with itself.
            Rule::unique('staff', 'staff_number')->ignore($this->route('staff')),
        ];
    }

    /**
     * The status rule, used only by the amend request.
     *
     * Create does NOT accept a status: a record that is born already inactive or terminated
     * has no route to a working state that does not immediately require a second call, and
     * a new staff member is actively employed by definition.
     *
     * @return array<int, mixed>
     */
    protected function staffStatusRule(): array
    {
        return ['sometimes', 'string', Rule::enum(EmploymentStatus::class)];
    }

    /**
     * @return array<string, string>
     */
    protected function staffEmploymentMessages(): array
    {
        return [
            'name.required' => 'The staff member\'s name is required.',
            'name.max' => 'The name may not be longer than 255 characters.',
            'staff_type.required' => 'The staff type is required, either TEACHING or NON_TEACHING.',
            'staff_type.enum' => 'The staff type must be one of: '.implode(', ', StaffType::values()).'.',
            'staff_number.max' => 'The staff number may not be longer than 50 characters.',
            'staff_number.unique' => 'This staff number is already assigned to another staff member.',
            'employment_date.date' => 'The employment date must be a valid date.',
            'employment_date.before_or_equal' => 'The employment date cannot be in the future.',
            'phone.max' => 'The phone number may not be longer than 30 characters.',
            'designation.max' => 'The designation may not be longer than 100 characters.',
            'status.enum' => 'The status must be one of: '.implode(', ', EmploymentStatus::values()).'.',
        ];
    }
}

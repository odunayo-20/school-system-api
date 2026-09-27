<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\Staff\Concerns\ValidatesStaffRecord;
use App\Rules\PasswordRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a staff record together with the login account behind it.
 *
 * staff.user_id is NOT NULL and UNIQUE - one staff record per login - so an employment
 * record with no account is not representable. email and password are therefore REQUIRED
 * inputs here rather than a silent side effect: this endpoint states that it creates an
 * account, and the administrator supplies the credentials. The password is checked by the
 * shared PasswordRule, which is the single definition of an acceptable password in this
 * project, and the response never echoes it.
 *
 * There is deliberately NO role field. The service assigns STAFF in code, and the absence
 * of the key is what enforces it: no payload can ask for SUPER_ADMIN, because there is no
 * key to ask with. A rule that merely rejected role=SUPER_ADMIN would still have to
 * enumerate the values, and the next role added would be a new thing to remember to
 * exclude. A role can be granted afterwards through Module 01's users.* permissions.
 *
 * There is no status field either: a new staff member is ACTIVE, and creating one already
 * inactive would only create a record that immediately needs a second call to be usable.
 */
class StoreStaffRequest extends FormRequest
{
    use ValidatesStaffRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Also lower-cases the email, for the same reason the staff number is upper-cased: the
     * unique index on users.email compares the stored value, and User lower-cases on the
     * way in through a mutator. Validating "A@B.com" against a stored "a@b.com" would let
     * the duplicate through and then report the collision as a 500, so the value that is
     * checked has to be the value that will be stored.
     */
    public function prepareForValidation(): void
    {
        $this->normaliseStaffInput();

        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->staffEmploymentRules(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', PasswordRule::make()],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->staffEmploymentMessages(), PasswordRule::messages(), [
            'email.required' => 'An email address is required, because a staff record has a login account.',
            'email.email' => 'The email address must be a valid address.',
            'email.unique' => 'This email address is already registered.',
            'password.required' => 'A password is required, because a staff record has a login account.',
        ]);
    }

    /**
     * The account fields, handed to the service separately from the employment fields so
     * the service cannot mistake one for the other and, for example, mass-assign a
     * password through the staff model.
     *
     * @return array{name: string, email: string, password: string}
     */
    public function accountAttributes(): array
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $this->safe()->only(['name', 'email', 'password']);

        return $validated;
    }

    /**
     * The employment fields, with no account credential and no user_id among them.
     *
     * @return array<string, mixed>
     */
    public function staffAttributes(): array
    {
        return $this->safe()->only([
            'staff_type',
            'staff_number',
            'employment_date',
            'phone',
            'designation',
        ]);
    }
}

<?php

namespace App\Http\Requests\Staff;

use App\Enums\EmploymentStatus;
use App\Http\Requests\Staff\Concerns\ValidatesStaffRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a staff record. PUT only: the route registers no PATCH, so a PATCH is answered
 * with 405 and an Allow header rather than silently behaving like a partial write.
 *
 * A whole-record write, following the convention every other amend endpoint in this API
 * uses: the fields that identify the person are required, so a client cannot half-update a
 * record by omitting the name. There is no partial form of this endpoint on purpose - the
 * resource it would need is another request class to keep in step, for a PUT that already
 * means "here is the record".
 *
 * It amends name and the employment fields. It does NOT accept email, password, role or
 * user_id:
 *
 *  - email and password are credentials. Module 01 holds them behind users.update, which is
 *    granted to ADMIN and SUPER_ADMIN only, because changing the address on an account is
 *    how you take it over: change the email, then take the password reset. Module 03 grants
 *    staff.update to REGISTRAR, so accepting email here would quietly hand a registrar an
 *    account-takeover path they are explicitly denied by Module 01 - a privilege escalation
 *    smuggled in through a field that looks like a harmless profile detail.
 *  - user_id is the one-to-one link to the account. It is never settable, so a staff record
 *    cannot be repointed at somebody else's login.
 *  - role is absent for the same reason it is absent on create.
 */
class UpdateStaffRequest extends FormRequest
{
    use ValidatesStaffRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->staffEmploymentRules(), [
            'name' => ['required', 'string', 'max:255'],
            'status' => $this->staffStatusRule(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->staffEmploymentMessages();
    }

    /**
     * The amendable attributes, with no credential and no linkage among them.
     *
     * @return array<string, mixed>
     */
    public function staffAttributes(): array
    {
        return $this->safe()->only([
            'name',
            'staff_type',
            'staff_number',
            'employment_date',
            'phone',
            'designation',
        ]);
    }

    /**
     * The requested status, or null when the amend did not mention one.
     *
     * Read separately from staffAttributes() so the service can tell "leave the status
     * alone" from "set the status to the value it already has", which is the difference
     * between a no-op and an attempt to reopen a terminated record.
     */
    public function requestedStatus(): ?EmploymentStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? EmploymentStatus::from($status) : null;
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One staff record: the employment data from staff, and the identity from the linked user.
 *
 * Every field is listed explicitly. Nothing is exposed by omission, so adding a column to
 * the staff table or the users table later cannot leak it into the API by accident.
 *
 * The account is flattened to name, email and status alongside the employment status
 * rather than nested, because the two are read together: an INACTIVE staff member whose
 * account is still ACTIVE is the interesting case this module is built around, and it is
 * only visible if the two statuses are on the same level.
 *
 * The user sub-object carries three fields and no more. In particular it does not carry the
 * user's id, permissions, role, email_verified_at, last_login_at or anything token-shaped:
 * a staff list is read by registrars, and the account behind a person is Module 01's
 * concern behind users.view.
 *
 * @mixin Staff
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_number' => $this->staff_number,

            // The person's identity, read from the account. Deliberately not duplicated
            // onto staff, so the two can never disagree.
            'name' => $this->user?->name,
            'email' => $this->user?->email,

            'staff_type' => $this->staff_type->value,
            'designation' => $this->designation,
            'employment_date' => $this->employment_date?->toDateString(),
            'phone' => $this->phone,

            // Employment and account status are separate answers to separate questions,
            // and are reported together so a mismatch between them is visible rather than
            // something an administrator has to remember to go and look for.
            'status' => $this->status->value,
            'account_status' => $this->user?->status?->value,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

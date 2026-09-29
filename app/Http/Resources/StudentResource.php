<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One pupil record: the person's identity and lifecycle, and the state of the portal
 * account behind it if there is one.
 *
 * Every field is listed explicitly. Nothing is exposed by omission, so adding a column to
 * the students table later cannot leak it into the API by accident - which matters more here
 * than anywhere else in the project, because a column that would leak is exactly the kind of
 * column this module exists to keep out of the table.
 *
 * There is deliberately NO class, section, session, term, score or attendance field, and no
 * "current" anything. A pupil's placement is a fact about one academic session and belongs
 * to a future enrollments table; a current_* column on this resource would be a client-facing
 * promise that the students table cannot keep. See the students migration.
 *
 * THE ACCOUNT FIELDS
 *
 * account_status is the linked login's UserStatus, or null when this pupil has no account.
 * One field therefore answers both questions - is this pupil on the roll, and can they log
 * in - and the two are never conflated, which is the whole lesson Module 03 learned the hard
 * way with employment status versus account status.
 *
 * A pupil's email is NOT exposed, unlike a staff member's. Module 03 could show it because a
 * staff member's email is their work identity and one of the ways a registrar finds them. A
 * child's login address is none of those things: it is a minor's personal data, it is not
 * needed to identify them on a roll, and reading other people's accounts is Module 01's
 * users.view business. The id, role, permissions, email_verified_at and last_login_at of a
 * linked account are likewise Module 01's.
 *
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_number' => $this->student_number,

            // The pupil's identity. Kept as parts AND composed, because a registrar's list
            // wants one sortable name column while a form wants the parts back.
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),

            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender?->value,

            // Roll status, and the account's login status, side by side for the same reason
            // the staff resource reports both.
            'status' => $this->status->value,
            'account_status' => $this->user?->status?->value,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Requests\Student;

use App\Http\Requests\Student\Concerns\ValidatesStudentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a pupil to the roll.
 *
 * Notice what is ABSENT, because the absences are the design:
 *
 *  - No email, no password, no role, no user_id. This endpoint does not create a login
 *    account, and that is the structural difference from Module 03's create. staff.user_id
 *    is NOT NULL - a constraint Module 01 predates Module 03 and Module 03 inherited - so
 *    a staff record cannot exist without an account and credentials have to be part of the
 *    request. No such constraint exists for pupils, and requiring one would be wrong: a
 *    Nursery entrant has no email address, a child's login should not be a precondition of
 *    recording that they exist, and the lifecycle runs Student -> Admission -> Enrollment,
 *    so identity comes before anything that would want a portal account.
 *
 *    The consequence worth being honest about: POST /students produces a pupil that no one
 *    can log in as, and that is the intended state, not an oversight. The account_status
 *    field in the response is null until the portal module provisions one. A client that
 *    needs a login in the same breath has to wait for that module, and pretending otherwise
 *    by quietly creating a User here would rebuild the account system this module exists to
 *    avoid.
 *
 *  - No status. A new pupil is ACTIVE. See the trait's note on studentStatusRule().
 *
 *  - No current_class_id, current_section_id, current_session_id or admission_number. See
 *    the students migration. A pupil is put on the roll; placing them in a class is a
 *    separate, session-scoped decision that this module has no opinion about.
 *
 * The student number IS accepted, optionally. A school with its own numbering scheme can
 * supply it; a school without one omits it and the service derives a unique readable one from
 * the record's own primary key. Both paths end at the same unique index.
 */
class StoreStudentRequest extends FormRequest
{
    use ValidatesStudentRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->studentIdentityRules(), [
            'student_number' => $this->studentNumberRules(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->studentIdentityMessages();
    }

    /**
     * The pupil's own fields, and nothing else.
     *
     * Named for what it is rather than calling it attributes() or data(): the point of this
     * method is that the set of things this endpoint can write is visible in one place, and
     * adding a field here is a decision somebody has to make on purpose. Module 03 needed
     * the same split for a different reason - it had to keep credentials out of a
     * mass-assignment call - but here it is simply the assertion that no account field can
     * leak into a pupil write.
     *
     * @return array<string, mixed>
     */
    public function studentAttributes(): array
    {
        return $this->safe()->only([
            'student_number',
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'gender',
        ]);
    }
}

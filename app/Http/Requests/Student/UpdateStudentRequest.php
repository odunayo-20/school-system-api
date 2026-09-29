<?php

namespace App\Http\Requests\Student;

use App\Enums\StudentStatus;
use App\Http\Requests\Student\Concerns\ValidatesStudentRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a pupil record. PUT only: the route registers no PATCH, so a PATCH is answered with
 * 405 and an Allow header rather than silently behaving like a partial write.
 *
 * A whole-record write, following the convention every other amend endpoint in this API
 * uses: first_name is required, so a client cannot half-update a record by omitting the one
 * name the school calls the child by. There is no partial form of this endpoint on purpose -
 * the resource it would need is another request class to keep in step, for a PUT that
 * already means "here is the record".
 *
 * It amends identity, date of birth, gender, roll status and the pupil's own number. It does
 * NOT accept user_id, and the reason is sharper here than it was for staff:
 *
 *  - user_id is the link to a portal account. Accepting it would let a holder of
 *    students.update - which Module 04 grants to REGISTRAR, and registrars are not
 *    administrators - repoint a child's record at ANY login in the system. That is an
 *    account-takeover path: attach a pupil record to an administrator's user_id and read
 *    the roll with their permissions. Module 01 keeps role and permission grants behind
 *    users.*, granted to ADMIN and SUPER_ADMIN only, and this field would be a way around
 *    that using a permission that reads like harmless record-keeping. The absence of the
 *    key is what prevents it; a rule enumerating forbidden values would be a list somebody
 *    has to remember to extend.
 *
 *  - There is no status rule that distinguishes "setting a status" from "changing a
 *    credential" here, because a pupil has none. The absence is the protection.
 *
 * The status IS amendable, unlike on create, and the terminal lifecycle is enforced in the
 * service rather than here - a validation rule cannot know the record's current status, and
 * embedding a lookup in a request to work that out would make the rule untestable in
 * isolation and would put a business rule in the wrong layer.
 */
class UpdateStudentRequest extends FormRequest
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
            'status' => $this->studentStatusRule(),
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
     * The amendable identity fields, with no status and no linkage among them.
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

    /**
     * The requested status, or null when the amend did not mention one.
     *
     * Read separately from studentAttributes() so the service can tell "leave the status
     * alone" from "set the status to the value it already has", which is the difference
     * between a no-op and an attempt to reopen a pupil who has already left.
     */
    public function requestedStatus(): ?StudentStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? StudentStatus::from($status) : null;
    }
}

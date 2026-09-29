<?php

namespace App\Http\Requests\Student\Concerns;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use Illuminate\Validation\Rule;

/**
 * The shared parts of the pupil list filters.
 *
 * There are three, and the fact that there are only three is worth explaining, because
 * Module 03 had six and every one of them was a question a registrar actually asked.
 *
 * search  - the number and the name parts. Unlike staff, this needs NO join and no search
 *           over a related table: a pupil's name is on the pupil row. Module 03 had to
 *           reach through to users for the name and email, and the query there carries a
 *           comment about it. Here the columns are simply there. That is the payoff of
 *           putting the identity fields on the pupil, and it is worth pointing at next time
 *           somebody suggests the pupils table should be a thin profile pointing at a user.
 *
 * status  - the roll status. Four values, and a missing one is not an oversight: ACTIVE,
 *           INACTIVE, GRADUATED and WITHDRAWN are the four states a pupil can be recorded
 *           in. There is no PENDING because a pupil is not an application - an application
 *           is an admission, and an admission has its own lifecycle in a future module.
 *
 * gender  - nullable, so a filter for it is meaningful rather than a formality.
 *
 * WHAT IS DELIBERATELY NOT HERE, and why, in each case:
 *
 *  - has_account. Module 03's equivalent was honest but useless: staff.user_id is NOT NULL,
 *    so every staff member necessarily has an account and the filter returned everything or
 *    nothing. A pupil's user_id is NULLABLE, so the same filter here would return a REAL
 *    subset - "which pupils have no portal login yet" is a genuine question, and it is one
 *    the registrar will ask precisely because Module 04 creates pupils without accounts.
 *    It is left out only because it was not in the approved scope for this module, not
 *    because it would not work. It is a one-line addition, and it is the first thing to add
 *    if the roll needs it. Writing it now would be a feature nobody asked for; writing it
 *    down stops it being rediscovered from scratch.
 *
 *  - class, section, session, term. A pupil's placement is an enrollment fact, and it
 *    belongs to a future enrollments table. Filtering the roll by a class without one would
 *    mean reading a column that does not exist, or reading the newest enrollment row
 *    directly - which is the correct answer to "who is in JSS 2 right now" and is emphatically
 *    not this module's question. GET /api/v1/classes/{class}/students is the endpoint that
 *    will answer it, and it will be honest about academic sessions in a way that a filter on
 *    the pupil roll can never be.
 *
 *  - school, owner_user_id and everything else Module 01's users list filters on. Module 01
 *    filters accounts; this filters people, and borrowing its filter set would be importing
 *    the account domain into a module built to stay out of it.
 */
trait ValidatesStudentFilters
{
    /**
     * @return array<string, mixed>
     */
    protected function studentFilterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(StudentStatus::class)],
            'gender' => ['sometimes', 'string', Rule::enum(Gender::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function studentFilterMessages(): array
    {
        return [
            'status.enum' => 'The status must be one of: '.implode(', ', StudentStatus::values()).'.',
            'gender.enum' => 'The gender must be one of: '.implode(', ', Gender::values()).'.',
        ];
    }
}

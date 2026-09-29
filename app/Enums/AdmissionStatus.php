<?php

namespace App\Enums;

/**
 * The lifecycle of one admission decision.
 *
 * Deliberately four states, not the five-or-six-stage pipeline a school admissions system
 * could have. There is no APPLIED/UNDER_REVIEW split: nothing in this project names a
 * reviewer distinct from the decision-maker, and RoleSeeder's own words give REGISTRAR and
 * ADMIN the whole of "handling admissions" rather than splitting intake from decision across
 * two roles. Collapsing them into one PENDING state is the same move Module 04 made when it
 * rejected a PENDING student status: a stage with nobody assigned to it and no rule that
 * depends on it is not a stage, it is an unused column.
 *
 * Three terminal states rather than one, mirroring Module 04's two terminal student states
 * for the same reason: ADMITTED, REJECTED and WITHDRAWN are different facts about how an
 * application ended, and a school that could not tell an offer declined from an application
 * refused could not answer "how many of this year's applicants did we actually want?".
 */
enum AdmissionStatus: string
{
    case PENDING = 'PENDING';

    /**
     * Accepted. A Student has been created and linked - see Admission::student_id.
     * Terminal: an admitted admission cannot be re-admitted, rejected or withdrawn.
     */
    case ADMITTED = 'ADMITTED';

    /**
     * Declined by the school. Terminal.
     */
    case REJECTED = 'REJECTED';

    /**
     * Withdrawn by the applicant (or on their behalf) before a decision was made. Terminal.
     *
     * Distinct from REJECTED: one is the school's decision, the other is the applicant's.
     * Collapsing them would lose exactly the distinction ADMITTED/REJECTED/WITHDRAWN exists
     * to keep.
     */
    case WITHDRAWN = 'WITHDRAWN';

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * A status the record cannot leave.
     *
     * Enforced by AdmissionService, not by withholding a permission: a holder of every
     * admissions.* permission still cannot move a terminal admission anywhere, because the
     * decision it records already happened.
     */
    public function isTerminal(): bool
    {
        return ! $this->isPending();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

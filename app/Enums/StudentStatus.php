<?php

namespace App\Enums;

/**
 * Whether a person is currently a pupil of the school.
 *
 * This is deliberately NOT the user's own UserStatus, and deliberately NOT CatalogStatus.
 *
 * UserStatus is about a credential: SUSPENDED there revokes tokens and stops the person
 * logging in, and Module 01's EnsureAccountIsActive already gives that defined behaviour.
 * Reusing it here would make "this child left the school" and "this login is disabled" the
 * same field, so recording a departure would silently cut off a portal account.
 *
 * CatalogStatus is scoped by its own docblock to "a record in the academic structure: class
 * levels, classes and sections". A pupil is not a catalogue record, and borrowing the enum
 * would contradict the file it came from.
 *
 * So this is the same shape as Module 03's EmploymentStatus - a person's lifecycle, kept
 * separate from their credentials - with the one difference that a student has two genuinely
 * distinct ways to leave, and collapsing them would lose information the school needs.
 */
enum StudentStatus: string
{
    case ACTIVE = 'ACTIVE';

    /**
     * On the roll but not currently attending: temporarily withdrawn, moved away, or on a
     * long absence. Reversible - returning to ACTIVE is an ordinary transition.
     */
    case INACTIVE = 'INACTIVE';

    /**
     * Completed the school's final year and left with a qualification.
     *
     * Terminal. Distinct from WITHDRAWN because "finished here" and "left before finishing"
     * are different facts, and a results or promotion module will eventually need to tell
     * them apart without inferring it from a date.
     */
    case GRADUATED = 'GRADUATED';

    /**
     * Left the school before completing it. Terminal.
     *
     * A withdrawal is not a deletion and not a failure. The record and its history stay
     * exactly as a graduate's does; only the reason differs, and a school that cannot
     * distinguish a graduate from a withdrawal cannot answer "how many of last year's
     * intake finished?".
     */
    case WITHDRAWN = 'WITHDRAWN';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * A status the record cannot leave.
     *
     * Enforced by StudentService. Without it, a withdrawn child could be quietly reinstated
     * by a later amend and would reappear on a current roll months after they had gone, and
     * a graduation could be reversed - which is the opposite of what a permanent record of
     * departure is for.
     */
    public function isTerminal(): bool
    {
        return $this === self::GRADUATED || $this === self::WITHDRAWN;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

<?php

namespace App\Enums;

/**
 * Whether a person is currently employed by the school.
 *
 * This is deliberately NOT the user's own UserStatus. That enum is about the credential -
 * SUSPENDED there revokes tokens and stops the person logging in - and Module 01's
 * EnsureAccountIsActive already gives it defined behaviour. Reusing it here would make
 * "stop employing this teacher" and "revoke this person's login" the same field, so a
 * registrar recording a departure would silently cut off somebody's access.
 *
 * The two are reported side by side in the staff resource instead, so an administrator can
 * see the mismatch when there is one.
 */
enum EmploymentStatus: string
{
    case ACTIVE = 'ACTIVE';

    /**
     * Employed but not currently working. A registrar records this for a teacher on sick
     * leave or secondment, and it is reversible: activate brings them back.
     *
     * It is also, in practice, where a departure lands, because a status the API cannot
     * tell apart from a long absence is a status that records nothing. Use TERMINATED for
     * a permanent departure.
     */
    case INACTIVE = 'INACTIVE';

    /**
     * Employment has ended permanently.
     *
     * Terminal, and enforced by StaffService: an employment that has ended cannot be
     * reopened. Without that, a leaver could be reactivated by a later amend and would
     * silently reappear on a current staff list, which is the opposite of what a
     * permanent record of departure is for.
     */
    case TERMINATED = 'TERMINATED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isTerminated(): bool
    {
        return $this === self::TERMINATED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

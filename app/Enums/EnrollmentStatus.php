<?php

namespace App\Enums;

/**
 * The lifecycle of one academic placement.
 *
 * Deliberately two terminal states rather than the four-state ACTIVE/COMPLETED/WITHDRAWN/
 * CANCELLED some enrollment systems use. There is no COMPLETED: nothing in this module
 * observes "the session ended" as an event, and adding a status for it would need something
 * to set it - a scheduled job or a hook this project has nowhere else. A past enrollment stays
 * legible as history through the *academic session's own* status (COMPLETED there already
 * means "this year has run"), not a copy of that fact on every enrollment row it produced.
 * ACTIVE simply means "this placement was never withdrawn or cancelled", whether the session
 * it belongs to is the current one or three years gone.
 *
 * WITHDRAWN and CANCELLED are kept distinct because they are different facts, the same
 * reasoning Module 04 applied to a student's own GRADUATED/WITHDRAWN split:
 *
 *  - WITHDRAWN: a real historical event. The student was placed here and later left this
 *    class/session placement - transferred elsewhere, dropped out mid-year.
 *  - CANCELLED: the row itself should not have existed - the wrong student, the wrong class,
 *    a duplicate data-entry. This is the record-preserving replacement for a DELETE endpoint
 *    this module deliberately does not have; see EnrollmentController.
 */
enum EnrollmentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case WITHDRAWN = 'WITHDRAWN';
    case CANCELLED = 'CANCELLED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * A status the record cannot leave. Enforced by EnrollmentService, not by withholding a
     * permission: a holder of every enrollments.* permission still cannot move a terminal
     * enrollment anywhere, because the placement it recorded already ended.
     */
    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

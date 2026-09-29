<?php

namespace App\Enums;

/**
 * The outcome of one attendance mark.
 *
 * Four cases, each with a genuine, distinct meaning a school register actually needs:
 *
 *  - PRESENT: the student attended, on time.
 *  - ABSENT: the student did not attend, with no excuse recorded.
 *  - LATE: the student attended, but after the register was expected to be taken - tracked
 *    separately from PRESENT because a school reasonably wants to know how often, and count
 *    it differently in a summary (see AttendanceService::summarize()).
 *  - EXCUSED: the student did not attend, but for a reason the school has accepted (a medical
 *    appointment, a family event) - tracked separately from ABSENT because conflating the two
 *    would lose exactly the distinction a parent or administrator asks this module for.
 *
 * No automatic derivation of one from another: every mark is an explicit decision the
 * recording staff member makes, never inferred from a time-of-day cutoff or any other rule
 * this module does not own.
 */
enum AttendanceStatus: string
{
    case PRESENT = 'PRESENT';
    case ABSENT = 'ABSENT';
    case LATE = 'LATE';
    case EXCUSED = 'EXCUSED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

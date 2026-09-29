<?php

namespace App\Enums;

/**
 * The lifecycle of one compiled subject result.
 *
 * Deliberately NOT the DRAFT/SUBMITTED/APPROVED/PUBLISHED/LOCKED shape a later module might
 * eventually want: no status enum anywhere in this project already has that shape (confirmed
 * by auditing EnrollmentStatus, AdmissionStatus, TeacherAssignmentStatus and CatalogStatus
 * before writing this one), so it is not an "established project workflow" to extend - it is
 * this module's own speculative guess at what Module 13 will need, and building it now with no
 * approval/publication logic to drive it would be exactly the premature architecture this
 * project's conventions caution against.
 *
 * Three cases, each with a genuine consumer INSIDE this module:
 *
 *  - INCOMPLETE: not every active assessment configured for this class subject and term has a
 *    score yet. The percentage stored alongside this status is still meaningful (the weighted
 *    contribution of whatever HAS been scored, deliberately not renormalized - see
 *    ResultService), but grade/grade_point/remark are never resolved against an incomplete
 *    picture - see ResultService::compile().
 *  - COMPILED: every active assessment has a score. The percentage is the full weighted total,
 *    and grade/grade_point/remark are resolved through Module 11's grading scale wherever one
 *    is configured and covers the percentage.
 *  - LOCKED: terminal. Nothing in Module 12 ever sets this - it exists purely as the boundary
 *    Module 13 will use once it introduces approval/publication, so that a future "lock" action
 *    needs no change to this table or to ResultService's own recompilation guard, which already
 *    refuses to touch a LOCKED row.
 */
enum ResultStatus: string
{
    case INCOMPLETE = 'INCOMPLETE';
    case COMPILED = 'COMPILED';
    case LOCKED = 'LOCKED';

    public function isLocked(): bool
    {
        return $this === self::LOCKED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

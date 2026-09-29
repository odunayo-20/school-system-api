<?php

namespace App\Enums;

/**
 * The lifecycle of one compiled subject result.
 *
 * Module 12 introduced the first three cases (INCOMPLETE, COMPILED, LOCKED) and deliberately
 * did NOT build the DRAFT/SUBMITTED/APPROVED/PUBLISHED/LOCKED shape a later module might want,
 * reserving only LOCKED as an unused boundary. Module 13 (Result Approval & Publication) is
 * that later module, and extends this SAME enum - on this SAME `results.status` column -
 * rather than introducing a second lifecycle field, because there is only ever one true state
 * a result can be in at a time, and a school-facing client asking "what is this result's
 * status" should never have to reconcile two columns to answer it.
 *
 * COMPILED plays the role a separate DRAFT status would have played: a complete, compiled
 * result awaiting submission. No separate DRAFT case was added, because COMPILED and "ready to
 * submit" are exactly the same fact under two names - INCOMPLETE cannot be submitted (see
 * ResultService::submit()), so nothing distinguishes "just compiled" from "a compiled result
 * before its first submission" that a second status would need to express.
 *
 * Six cases now, each with a genuine consumer:
 *
 *  - INCOMPLETE: not every active assessment configured for this class subject and term has a
 *    score yet. The percentage stored alongside this status is still meaningful (the weighted
 *    contribution of whatever HAS been scored, deliberately not renormalized - see
 *    ResultService), but grade/grade_point/remark are never resolved against an incomplete
 *    picture, and it cannot be submitted - see ResultService::compile()/submit().
 *  - COMPILED: every active assessment has a score. The percentage is the full weighted total,
 *    and grade/grade_point/remark are resolved through Module 11's grading scale wherever one
 *    is configured and covers the percentage. Ready for submission.
 *  - SUBMITTED: a teacher (or an administrator) has submitted the compiled result for review.
 *    Set by ResultService::submit(), recorded with submitted_by/submitted_at.
 *  - APPROVED: an administrator has reviewed and approved the submission. Set by
 *    ResultService::approve(), recorded with approved_by/approved_at.
 *  - PUBLISHED: an administrator has made the approved result available - the hand-off point a
 *    future Result Checker module (Module 16) will read from. Set by ResultService::publish(),
 *    recorded with published_by/published_at. This module does not itself expose a public
 *    result-checking endpoint.
 *  - LOCKED: terminal and immutable. Set by ResultService::lock(), recorded with
 *    locked_by/locked_at.
 *
 * See isRecompilable() for the one behavioural change this extension makes to Module 12's own
 * ResultService::persist(): a result that has entered the workflow (SUBMITTED or beyond) can no
 * longer be silently overwritten by a recompile - see the Module 13 audit §8.
 */
enum ResultStatus: string
{
    case INCOMPLETE = 'INCOMPLETE';
    case COMPILED = 'COMPILED';
    case SUBMITTED = 'SUBMITTED';
    case APPROVED = 'APPROVED';
    case PUBLISHED = 'PUBLISHED';
    case LOCKED = 'LOCKED';

    public function isLocked(): bool
    {
        return $this === self::LOCKED;
    }

    /**
     * Whether ResultService::persist() may overwrite a result in this status with a fresh
     * compilation. Once a result has entered the approval workflow (SUBMITTED or beyond), a
     * recompile - however well-intentioned - would silently corrupt a record someone has
     * already reviewed, approved or published against its CURRENT values. Only the two states
     * Module 12 itself ever produces remain recompilable.
     */
    public function isRecompilable(): bool
    {
        return match ($this) {
            self::INCOMPLETE, self::COMPILED => true,
            self::SUBMITTED, self::APPROVED, self::PUBLISHED, self::LOCKED => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

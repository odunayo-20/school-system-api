<?php

namespace App\Enums;

/**
 * What was decided for a student's placement going into a new academic session.
 *
 * Deliberately NOT a boolean `is_promoted`. Four cases, because the brief's own domain names
 * four genuinely different facts, and collapsing any two would lose a question this school
 * needs to answer later ("how many of last year's JSS 3 actually moved up, versus stayed
 * back?"):
 *
 *  - PROMOTED: moved to a different class for the target session. Creates a target
 *    enrollment.
 *  - RETAINED: stays in the SAME class and section for the target session. Creates a target
 *    enrollment too - the class/section are simply copied from the source enrollment, never
 *    supplied by the client, so "retained" can never accidentally mean "moved to a different
 *    class" - see PromoteStudentRequest.
 *  - GRADUATED: completed the school (or the applicable stage) and has no next placement
 *    within it. Creates NO target enrollment; sets the student's own StudentStatus to
 *    GRADUATED (Module 04's existing terminal status, reused unchanged - see
 *    PromotionService).
 *  - NOT_ELIGIBLE: an explicit record that this student was NOT moved forward this decision
 *    round, for a reason outside this module's own knowledge (the brief's own §9 explicitly
 *    forbids inventing an automatic eligibility formula). Creates no target enrollment and
 *    does NOT change the student's status - unlike GRADUATED, this is not terminal: the same
 *    source enrollment remains promotable again later (e.g. once the reason is resolved).
 *
 * There is no automatic rule anywhere that assigns one of these - every promotion is an
 * explicit decision an authorized user records, per the brief's own §9.
 */
enum PromotionDecision: string
{
    case PROMOTED = 'PROMOTED';
    case RETAINED = 'RETAINED';
    case GRADUATED = 'GRADUATED';
    case NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    /**
     * Whether this decision produces a target enrollment. Both cases that keep the student in
     * the school for the target session create one; the two that do not (a completed academic
     * career, or no decision to move forward yet) leave `target_enrollment_id` null.
     */
    public function createsEnrollment(): bool
    {
        return match ($this) {
            self::PROMOTED, self::RETAINED => true,
            self::GRADUATED, self::NOT_ELIGIBLE => false,
        };
    }

    public function isGraduation(): bool
    {
        return $this === self::GRADUATED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $decision): string => $decision->value, self::cases());
    }
}

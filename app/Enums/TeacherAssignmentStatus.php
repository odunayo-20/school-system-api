<?php

namespace App\Enums;

/**
 * The lifecycle of one teaching responsibility.
 *
 * ACTIVE, then terminally ENDED or CANCELLED - the identical three-state shape
 * EnrollmentStatus uses, for the identical reason: ENDED and CANCELLED are different facts
 * about how an assignment stopped, and collapsing them would lose "how many teaching
 * assignments genuinely concluded versus were data-entry mistakes" - a real distinction once
 * this module's own history is looked back on.
 *
 *  - ENDED: a real event. The teacher stopped teaching this class subject for this session -
 *    they left, were reassigned elsewhere, or the school appointed someone else partway
 *    through the year.
 *  - CANCELLED: the row should not have existed - the wrong teacher, the wrong class subject,
 *    a duplicate data-entry. This is the record-preserving replacement for a DELETE endpoint
 *    this module deliberately does not have.
 *
 * Neither is reversible, unlike ClassSubject's freely-toggled CatalogStatus: an assignment
 * that has stopped is a fact about a period that has closed, not a switch to flip back. A
 * teacher resuming the same responsibility later is a NEW assignment (a new row), not a
 * reactivation of the old one - the same posture Module 04 takes toward a departed pupil and
 * Module 06 takes toward a withdrawn enrollment.
 */
enum TeacherAssignmentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ENDED = 'ENDED';
    case CANCELLED = 'CANCELLED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * A status the record cannot leave. Enforced by TeacherAssignmentService, not by
     * withholding a permission: a holder of every teacher_assignments.* permission still
     * cannot move a terminal assignment anywhere, because the responsibility it recorded
     * already ended.
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

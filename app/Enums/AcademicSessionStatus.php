<?php

namespace App\Enums;

/**
 * Lifecycle of an academic session.
 *
 * Exactly one session may be ACTIVE at a time. That rule is enforced twice: by the
 * active_marker unique index in the database, and by AcademicSessionService inside a
 * transaction.
 */
enum AcademicSessionStatus: string
{
    case UPCOMING = 'UPCOMING';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';

    /**
     * The status that claims the "one active session" slot.
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * A completed session is history. It must stay readable because later modules will
     * use it for enrollment, results, promotion and report cards, so it is retired
     * rather than deleted.
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

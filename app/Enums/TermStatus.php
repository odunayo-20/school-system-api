<?php

namespace App\Enums;

/**
 * Lifecycle of a term within an academic session.
 *
 * At most one term is ACTIVE across the whole system, and the active term must belong to
 * the active academic session. Both rules are enforced by TermService and, for the
 * "at most one" half, by the active_marker unique index.
 */
enum TermStatus: string
{
    case UPCOMING = 'UPCOMING';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

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

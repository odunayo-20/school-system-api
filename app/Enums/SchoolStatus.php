<?php

namespace App\Enums;

/**
 * Lifecycle of the single school configuration record.
 *
 * This is deliberately NOT App\Enums\UserStatus. UserStatus describes a person's account
 * and carries authentication concepts such as SUSPENDED; this describes a configuration
 * record. Conflating the two would make it possible to "suspend a school" with the same
 * code path that revokes a user's API tokens.
 */
enum SchoolStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

<?php

namespace App\Enums;

/**
 * Classification of a STAFF user. This is deliberately NOT an authentication
 * role: teaching and non-teaching staff authenticate identically, they merely
 * resolve to different permissions.
 */
enum StaffType: string
{
    case TEACHING = 'TEACHING';
    case NON_TEACHING = 'NON_TEACHING';

    /**
     * @return list<self>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

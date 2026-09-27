<?php

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUSPENDED = 'SUSPENDED';

    /**
     * Only active accounts may hold a valid API token.
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * @return list<self>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

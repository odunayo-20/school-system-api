<?php

namespace App\Enums;

enum Role: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ADMIN = 'ADMIN';
    case REGISTRAR = 'REGISTRAR';
    case STAFF = 'STAFF';
    case STUDENT = 'STUDENT';

    /**
     * The role that bypasses every authorization check. The bypass itself lives
     * in a single Gate::before callback, never in a controller.
     */
    public function isSuperAdmin(): bool
    {
        return $this === self::SUPER_ADMIN;
    }

    /**
     * @return list<self>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}

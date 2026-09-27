<?php

namespace App\Enums;

/**
 * Lifecycle of a record in the academic structure: class levels, classes and sections.
 *
 * One shared enum for all three on purpose. ClassLevelStatus, ClassStatus and
 * SectionStatus would be three enums with identical cases and identical meaning, so
 * changing the retirement policy would mean editing three files and a bare "status" string
 * would be ambiguous about which enum it wanted. See the Module 02 audit, section D.6.
 *
 * ARCHIVED is a first-class state, not a synonym for INACTIVE: a record can be
 * temporarily out of use (INACTIVE) or permanently retired while remaining readable for
 * historical data (ARCHIVED).
 */
enum CatalogStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case ARCHIVED = 'ARCHIVED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * A record that may no longer be chosen for new academic work, but which must stay
     * readable because history references it.
     */
    public function isRetired(): bool
    {
        return $this === self::INACTIVE || $this === self::ARCHIVED;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

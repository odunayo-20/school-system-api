<?php

namespace App\Enums;

/**
 * A pupil's gender, as the school records it.
 *
 * Nullable throughout. Gender is not something a school can always know, and a record that
 * had to guess would put a fabricated value in a field that will end up on a child's
 * permanent file, so "not recorded" is a first-class answer and the column accepts null.
 *
 * SCOPE, STATED PLAINLY: this is a MALE / FEMALE pair, which is the smallest model that
 * records what the overwhelming majority of pupils are, and it is what the enum can honestly
 * offer. It cannot represent a pupil who is neither, and the only ways to record such a
 * child today are an inaccurate value or no value at all. That is a real limitation rather
 * than a considered policy.
 *
 * If the school needs to record a pupil outside this pair, the migration is additive -
 * adding a case to this enum and a migration to widen the column is a contained change - and
 * nothing else in Module 04 needs to change, because every reader goes through values() and
 * the rules validate against Rule::enum() rather than a hand-written list. Widening the
 * column is deliberately NOT done pre-emptively: a value with no case behind it cannot be
 * filtered, and a filter over a list the enum does not define would be a guess.
 */
enum Gender: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $gender): string => $gender->value, self::cases());
    }
}

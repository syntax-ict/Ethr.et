<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an emergency contact is related to the employee, as the forms offer it.
 *
 * Not enforced on save: rows written before this list hold free text
 * ("Uncle", "Neighbour"), and the contact form's "Other" keeps what was typed.
 * The enum is the list the forms offer and the words the labels exist for, so
 * a row that holds one of these values always has a name in every language.
 */
enum Relationship: string
{
    case SPOUSE = 'spouse';

    case PARENT = 'parent';

    case SIBLING = 'sibling';

    case CHILD = 'child';

    case FRIEND = 'friend';

    case OTHER = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    /** A value as people write it, or null when it is none of the six. */
    public static function fromInput(?string $value): ?self
    {
        return self::tryFrom(mb_strtolower(trim((string) $value)));
    }
}

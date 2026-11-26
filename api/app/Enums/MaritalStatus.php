<?php

declare(strict_types=1);

namespace App\Enums;

/** The marital statuses the employee record stores. */
enum MaritalStatus: string
{
    case SINGLE = 'single';

    case MARRIED = 'married';

    case DIVORCED = 'divorced';

    case WIDOWED = 'widowed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /** A value as people write it, or null when it is none of the four. */
    public static function fromInput(?string $value): ?self
    {
        return self::tryFrom(mb_strtolower(trim((string) $value)));
    }
}

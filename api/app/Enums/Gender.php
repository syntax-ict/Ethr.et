<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The genders the employee record stores. Two, because leave rules
 * (`gender_restriction`) and the statutory reports branch on them; an
 * employee whose gender was never recorded has none, not a third value.
 */
enum Gender: string
{
    case MALE = 'male';

    case FEMALE = 'female';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $g) => $g->value, self::cases());
    }

    /**
     * A value as a spreadsheet writes it: "Male", "F", "female". Null when it
     * is none of them. Never a guess.
     */
    public static function fromInput(?string $value): ?self
    {
        $key = mb_strtolower(trim((string) $value));

        return match ($key) {
            'm' => self::MALE,
            'f' => self::FEMALE,
            default => self::tryFrom($key),
        };
    }
}

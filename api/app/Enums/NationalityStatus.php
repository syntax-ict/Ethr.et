<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an employee is an Ethiopian national — the fact pension coverage
 * turns on (Proclamations 1268/2022 and 1267/2022, Art. 3(1)).
 *
 * A fixed choice, not the free-text `nationality`: a typo there ("Ethiopean")
 * used to exempt an Ethiopian from pension silently, and the employer then
 * owes the contributions plus 5 % a month (research P12). `nationality` stays
 * for the country name.
 */
enum NationalityStatus: string
{
    case ETHIOPIAN = 'ethiopian';

    /** A foreign national of Ethiopian origin (Proclamation 270/2002). Coverage unsettled — research P8. */
    case ETHIOPIAN_ORIGIN = 'ethiopian_origin';

    case FOREIGN = 'foreign';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * A value as people write it — "Ethiopian origin", "FOREIGN" — or null
     * when it is none of the three. Never a guess: anything else is null.
     */
    public static function fromInput(?string $value): ?self
    {
        $key = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));

        return self::tryFrom($key);
    }
}

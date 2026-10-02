<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An `array`-cast column, or a validated `array` input, that only ever holds
 * strings — a webhook's events, an API key's abilities, a schedule's
 * recipients — read as a list of strings.
 *
 * The API contract types an `array` cast as `unknown[]`; returning the value
 * through here is what lets it publish `string[]`. Values that are already a
 * list of strings come back unchanged.
 */
final class StringList
{
    /** @return list<string> */
    public static function of(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            $list[] = (string) $item;
        }

        return $list;
    }
}

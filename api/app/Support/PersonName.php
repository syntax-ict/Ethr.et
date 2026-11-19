<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A person's full name as two records are compared on it: trimmed, runs of
 * spaces made one, and lower case. "Semira  Aragaw Sisay " and "semira aragaw
 * sisay" are the same name.
 *
 * One rule, used wherever a name is compared whole: the identity resolver's
 * name similarity, the same-name check on a staged list (SameNameRoster) and
 * the possible-duplicates report.
 */
final class PersonName
{
    public static function normalised(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }
}

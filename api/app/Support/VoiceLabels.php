<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Labels for employee-voice codes (categories, statuses, values), read from
 * the lang/{locale}/voice.php groups. The group is looked up whole, by a
 * literal key, so every key a notification uses can be checked to exist in
 * both languages.
 */
final class VoiceLabels
{
    /** @param  array<string, string>|string  $group */
    public static function pick(array|string $group, string $code): string
    {
        return is_array($group) && is_string($group[$code] ?? null) ? $group[$code] : $code;
    }
}

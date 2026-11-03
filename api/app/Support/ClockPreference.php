<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Which clock a person's times of day are shown in.
 *
 * Ethiopian time counts the hours from dawn: 06:00 is 12:00 ጠዋት, 07:30 is
 * 1:30 ጠዋት, 18:00 is 12:00 ምሽት. It is only ever a way of showing and typing
 * a time — every stored and transported time stays UTC and 24-hour.
 *
 * The preference is `auto` unless someone chose: the user's own choice wins,
 * then their organisation's `settings.clock`, then `auto`. `auto` is decided
 * where the time is written, by `resolve()`, because it depends on the language
 * the text is in: an Amharic reader on the Ethiopian calendar gets Ethiopian
 * time, anyone else 24-hour. The frontend applies the same rule
 * (`resolveClock` in `lib/calendar/ethiopian-time.ts`).
 */
final class ClockPreference
{
    public const AUTO = 'auto';

    public const ETHIOPIAN = 'ethiopian';

    public const TWENTY_FOUR_HOUR = '24h';

    public const CHOICES = [self::AUTO, self::ETHIOPIAN, self::TWENTY_FOUR_HOUR];

    public static function forUser(User $user): string
    {
        $stored = is_array($user->preferences) ? ($user->preferences['clock'] ?? null) : null;

        if (in_array($stored, [self::ETHIOPIAN, self::TWENTY_FOUR_HOUR], true)) {
            return $stored;
        }

        if ($user->tenant_id === null) {
            return self::AUTO;
        }

        // loadMissing, as CalendarPreference does: preventLazyLoading is on
        // outside production.
        $user->loadMissing('tenant');
        $tenant = $user->getRelation('tenant');

        return self::forTenant($tenant instanceof Tenant ? $tenant : null);
    }

    public static function forTenant(?Tenant $tenant): string
    {
        $settings = $tenant?->getAttribute('settings');
        $clock = is_array($settings) ? ($settings['clock'] ?? null) : null;

        return in_array($clock, self::CHOICES, true) ? $clock : self::AUTO;
    }

    /**
     * The clock a time is written in, for a preference, a calendar and the
     * language of the text.
     *
     * @return 'ethiopian'|'24h'
     */
    public static function resolve(string $preference, string $calendar, string $locale): string
    {
        return match ($preference) {
            self::ETHIOPIAN => self::ETHIOPIAN,
            self::TWENTY_FOUR_HOUR => self::TWENTY_FOUR_HOUR,
            default => $calendar === 'ethiopian' && in_array($locale, Locales::ETHIOPIAN_CLOCK, true)
                ? self::ETHIOPIAN
                : self::TWENTY_FOUR_HOUR,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The languages the product ships, server side.
 *
 * `lang/<code>/` holds a full translation for each of these, and the frontend
 * marks the same codes `available` in `src/src/lib/i18n/config.ts`. Before
 * this list the pair `['en', 'am']` was restated in the locale middleware, the
 * reader's preferred locale, two queued jobs and the notification templates,
 * so a third language meant finding all of them.
 *
 * `ti`, `so` and `sid` are accepted as *stored preferences*
 * (`UpdateProfilePreferencesRequest`) but have no translation yet, so they are
 * not here: a reader who chose one is written to in English.
 */
final class Locales
{
    public const DEFAULT = 'en';

    /** @var list<string> */
    public const SHIPPED = ['en', 'am', 'om'];

    /**
     * Readers who count the day from dawn when nothing says otherwise: the
     * Ethiopian clock is the `auto` choice for them on the Ethiopian calendar.
     *
     * @var list<string>
     */
    public const ETHIOPIAN_CLOCK = ['am', 'om'];

    public static function isShipped(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::SHIPPED, true);
    }

    /** `$locale` when the product speaks it, English otherwise. */
    public static function orDefault(mixed $locale): string
    {
        return self::isShipped($locale) ? $locale : self::DEFAULT;
    }

    /** The rule `in:` takes, for a request that accepts a shipped language. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::SHIPPED);
    }
}

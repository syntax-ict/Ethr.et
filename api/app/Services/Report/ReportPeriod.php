<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Exceptions\PayrollRunRefused;
use App\Models\Tenant;
use App\Services\Calendar\EthiopianCalendar;
use App\Services\Calendar\OrganisationMonth;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A rolling period, resolved on the day a report runs.
 *
 * A saved or scheduled report that stores `from` and `to` reports the same
 * dates for ever; a scheduled "last month" report sent a different month
 * only if somebody edited it. A period key is stored instead and turned into
 * dates here, each time.
 *
 * The month periods are the Analytics page's, so Reports and Analytics agree
 * on what "last month" is: whole months of the organisation's own calendar,
 * counted as `SPAN` counts them in
 * `src/src/app/(dashboard)/analytics/period.ts` (this month, last month, the
 * last 3 months and the last 12 months, each ending with the month that
 * `back` months ago names, the current month included for the last two).
 * Tikimt runs from 11 October to 9 November on the Ethiopian calendar, and
 * Pagume is a month of five or six days.
 *
 * The fiscal year is read as the Settings page reads it, from the statutory
 * rule `fiscal_year.start_month`, an Ethiopian month whatever calendar the
 * organisation shows (1 = Meskerem, 11 = Hamle), and falls back to 1.
 * `this_fiscal_year` runs up to today, as a year to date.
 */
final class ReportPeriod
{
    public const KEYS = ['yesterday', 'last_7_days', 'this_month', 'last_month', 'last_3_months', 'last_12_months', 'this_fiscal_year', 'last_fiscal_year'];

    /** The periods of a month or less, the only ones a timesheet takes: it covers 31 days at most. */
    public const SHORT_KEYS = ['yesterday', 'last_7_days', 'this_month', 'last_month'];

    /** The month periods: how many months, and how many months back the last one is. */
    private const SPAN = [
        'this_month' => ['months' => 1, 'back' => 0],
        'last_month' => ['months' => 1, 'back' => 1],
        'last_3_months' => ['months' => 3, 'back' => 0],
        'last_12_months' => ['months' => 12, 'back' => 0],
    ];

    /**
     * The first and last day of the period, at midnight, as Gregorian dates.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolve(string $key, ?Tenant $tenant, ?CarbonInterface $today = null): array
    {
        $months = OrganisationMonth::forTenant($tenant);
        $today = $today === null
            ? $months->today()
            : Carbon::parse($today->format('Y-m-d'))->startOfDay();

        if (isset(self::SPAN[$key])) {
            ['months' => $count, 'back' => $back] = self::SPAN[$key];
            ['year' => $year, 'month' => $month] = $months->monthOf($today);
            $last = $months->shift($year, $month, -$back);
            $first = $months->shift($last['year'], $last['month'], -($count - 1));

            return [
                $months->start($first['year'], $first['month'])->startOfDay(),
                $months->end($last['year'], $last['month'])->startOfDay(),
            ];
        }

        return match ($key) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_7_days' => [$today->copy()->subDays(7), $today->copy()->subDay()],
            'this_fiscal_year' => [self::fiscalYearStart($tenant, $today, 0), $today->copy()],
            'last_fiscal_year' => [
                self::fiscalYearStart($tenant, $today, 1),
                self::fiscalYearStart($tenant, $today, 0)->subDay(),
            ],
            default => throw new \InvalidArgumentException("Unknown report period [{$key}]."),
        };
    }

    /** The first day of the fiscal year that began `$yearsBack` years before the current one. */
    private static function fiscalYearStart(?Tenant $tenant, Carbon $today, int $yearsBack): Carbon
    {
        $calendar = app(EthiopianCalendar::class);
        $start = self::fiscalStartMonth($tenant, $today);
        $now = $calendar->gregorianToEthiopian($today);
        $year = $now['month'] >= $start ? $now['year'] : $now['year'] - 1;

        return Carbon::parse($calendar->ethiopianToGregorian($year - $yearsBack, $start, 1)->toDateString())->startOfDay();
    }

    private static function fiscalStartMonth(?Tenant $tenant, Carbon $today): int
    {
        if ($tenant === null || ! $tenant->exists) {
            return 1;
        }

        try {
            $month = app(StatutoryRules::class)->for($tenant, $today)->int('fiscal_year.start_month');
        } catch (PayrollRunRefused) {
            return 1;
        }

        return $month >= 1 && $month <= 13 ? $month : 1;
    }
}

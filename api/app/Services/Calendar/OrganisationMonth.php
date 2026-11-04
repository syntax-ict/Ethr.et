<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\Tenant;
use App\Support\CalendarPreference;
use App\Support\TenantTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A month of the organisation's own calendar: Tikimt 2019 for an organisation
 * on the Ethiopian calendar, October 2026 for one on the Gregorian.
 *
 * "This month" on the dashboard meant the Gregorian month until 2026-11-03,
 * behind Ethiopian labels: the Organisation today tile read 45% attendance
 * for 1 to 30 October while the Analytics page, which builds its window in
 * the reader's calendar, read 84.7% for Tikimt 2019 (11 October to
 * 9 November). The calendar is the organisation's `settings.calendar`, the
 * one rule `CalendarPreference` already states, and today is read in the
 * organisation's zone, as the frontend's `todayIn()` reads it.
 *
 * An Ethiopian year has thirteen months here, Pagume included, as the
 * Analytics page's `period.ts` counts them; a hires bucket for Pagume holds
 * five or six days and says so by its name. (`LeaveYear` folds Pagume into
 * Nehase, because accrual pays twelve months a year; that is a leave rule,
 * not a calendar one.)
 */
final class OrganisationMonth
{
    private function __construct(
        public readonly string $calendar,
        private readonly string $timeZone,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        return new self(CalendarPreference::forTenant($tenant), TenantTime::zone($tenant));
    }

    public function isEthiopian(): bool
    {
        return $this->calendar === 'ethiopian';
    }

    /** Today in the organisation's zone, at midnight. */
    public function today(): Carbon
    {
        return Carbon::now($this->timeZone)->startOfDay();
    }

    /**
     * The month `$date` falls in.
     *
     * @return array{year: int, month: int}
     */
    public function monthOf(CarbonInterface $date): array
    {
        if (! $this->isEthiopian()) {
            return ['year' => $date->year, 'month' => $date->month];
        }

        $ethiopian = self::ethiopian()->gregorianToEthiopian(Carbon::parse($date->format('Y-m-d')));

        return ['year' => $ethiopian['year'], 'month' => $ethiopian['month']];
    }

    /** The first day of the month, at midnight in the application zone, as the figures filter dates. */
    public function start(int $year, int $month): Carbon
    {
        return $this->isEthiopian()
            ? Carbon::parse(self::ethiopian()->ethiopianToGregorian($year, $month, 1)->toDateString())->startOfDay()
            : Carbon::create($year, $month, 1)->startOfDay();
    }

    /** The last day of the month, at the end of the day. */
    public function end(int $year, int $month): Carbon
    {
        if (! $this->isEthiopian()) {
            return Carbon::create($year, $month, 1)->endOfMonth();
        }

        $lastDay = $month === 13 ? self::ethiopian()->daysInPagume($year) : 30;

        return Carbon::parse(self::ethiopian()->ethiopianToGregorian($year, $month, $lastDay)->toDateString())->endOfDay();
    }

    /**
     * The month `$by` months after the given one; negative counts back.
     *
     * @return array{year: int, month: int}
     */
    public function shift(int $year, int $month, int $by): array
    {
        $perYear = $this->isEthiopian() ? 13 : 12;
        $index = $year * $perYear + ($month - 1) + $by;

        return ['year' => intdiv($index, $perYear), 'month' => ($index % $perYear) + 1];
    }

    /**
     * The current month's first and last day.
     *
     * @return array{Carbon, Carbon}
     */
    public function current(): array
    {
        ['year' => $year, 'month' => $month] = $this->monthOf($this->today());

        return [$this->start($year, $month), $this->end($year, $month)];
    }

    /**
     * A month's bucket key. Gregorian months keep the `2026-10` the charts
     * have always carried; an Ethiopian month is `2019-02-EC`, which no reader
     * of a Gregorian key can mistake for February 2019.
     */
    public function key(int $year, int $month): string
    {
        $key = sprintf('%04d-%02d', $year, $month);

        return $this->isEthiopian() ? "{$key}-EC" : $key;
    }

    private static function ethiopian(): EthiopianCalendar
    {
        return app(EthiopianCalendar::class);
    }
}

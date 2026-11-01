<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Calendar\EthiopianCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dates and times written for one reader: their calendar, their clock, the
 * language of the text, and their organisation's zone.
 *
 * The server's half of the frontend's `useDateFormatters()`. Before it, every
 * e-mail, push and in-app notice wrote `2026-11-01` and `08:57` whatever the
 * reader had chosen, because nothing that renders text on the server read the
 * calendar preference at all (audit, 2026-11-01).
 *
 * The language is the one the text is being written in, `app()->getLocale()`
 * by default: Laravel sets it to the recipient's `preferredLocale()` while a
 * notification renders, and to the reader's while a request is served.
 *
 * Outputs match the frontend's for the same inputs: `Tikimt 22, 2019`,
 * `ጥቅምት 22, 2019`, `01 Nov 2026`; `1:30 ጠዋት`, `07:30`.
 */
final class ReaderDates
{
    private const MONTHS = [
        'en' => ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'],
        'am' => ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'],
    ];

    private const PERIODS = [
        'en' => ['morning' => 'morning', 'day' => 'afternoon', 'evening' => 'evening', 'night' => 'night'],
        'am' => ['morning' => 'ጠዋት', 'day' => 'ቀን', 'evening' => 'ምሽት', 'night' => 'ሌሊት'],
    ];

    /**
     * @param  'ethiopian'|'gregorian'  $calendar
     * @param  'ethiopian'|'24h'  $clock
     */
    public function __construct(
        public readonly string $calendar,
        public readonly string $clock,
        public readonly string $locale,
        public readonly string $timeZone,
    ) {}

    /**
     * For whoever a notification is going to. A notifiable that is not a user
     * (an e-mail route, a lead) reads like a newcomer to the organisation.
     */
    public static function for(?object $notifiable, ?string $locale = null, ?Tenant $tenant = null): self
    {
        $locale ??= app()->getLocale();

        if ($notifiable instanceof User) {
            $notifiable->loadMissing('tenant');
            $userTenant = $notifiable->getRelation('tenant');

            return self::make(
                CalendarPreference::forUser($notifiable),
                ClockPreference::forUser($notifiable),
                $locale,
                $userTenant instanceof Tenant ? $userTenant : $tenant,
            );
        }

        return self::forTenant($tenant, $locale);
    }

    public static function forTenant(?Tenant $tenant, ?string $locale = null): self
    {
        return self::make(
            CalendarPreference::forTenant($tenant),
            ClockPreference::forTenant($tenant),
            $locale ?? app()->getLocale(),
            $tenant,
        );
    }

    private static function make(string $calendar, string $clockPreference, string $locale, ?Tenant $tenant): self
    {
        $calendar = $calendar === 'gregorian' ? 'gregorian' : 'ethiopian';
        $locale = $locale === 'am' ? 'am' : 'en';

        return new self(
            $calendar,
            ClockPreference::resolve($clockPreference, $calendar, $locale),
            $locale,
            TenantTime::zone($tenant),
        );
    }

    /**
     * A calendar date. A bare `Y-m-d` is read as written; an instant is read in
     * the organisation's zone, so 22:30 UTC is already the next day in Addis.
     */
    public function date(CarbonInterface|string|null $value): string
    {
        $day = $this->day($value);
        if ($day === null) {
            return is_string($value) ? $value : '';
        }

        if ($this->calendar === 'ethiopian') {
            $eth = app(EthiopianCalendar::class)->gregorianToEthiopian(Carbon::instance($day));

            return $this->monthName($eth['month']).' '.$eth['day'].', '.$eth['year'];
        }

        return $this->locale === 'am'
            ? $day->locale('am')->translatedFormat('d M Y')
            : $day->format('d M Y');
    }

    /** The time of day an instant falls on in the organisation's zone. */
    public function time(CarbonInterface|string|null $value): string
    {
        $instant = $this->instant($value);
        if ($instant === null) {
            return is_string($value) ? $value : '';
        }

        $local = $instant->setTimezone($this->timeZone);

        return $this->clock((int) $local->format('G'), (int) $local->format('i'));
    }

    /** A stored wall-clock time (`06:00`, `06:00:00`), such as a shift start. */
    public function timeOfDay(?string $value): string
    {
        if ($value === null || preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return $value ?? '';
        }

        [$hour, $minute] = [(int) $m[1], (int) $m[2]];
        if ($hour > 23 || $minute > 59) {
            return $value;
        }

        return $this->clock($hour, $minute);
    }

    public function dateTime(CarbonInterface|string|null $value): string
    {
        $instant = $this->instant($value);
        if ($instant === null) {
            return is_string($value) ? $value : '';
        }

        return $this->date($instant).', '.$this->time($instant);
    }

    /**
     * The month a pay period means. Taken from its midpoint, as the frontend's
     * `localizedPeriodLabel` does: a period cut on Ethiopian month boundaries
     * (11 Sep - 10 Oct) and one cut on Gregorian ones (1 - 30 Sep) both put
     * their middle inside the month they mean, in either calendar.
     */
    public function period(CarbonInterface|string|null $start, CarbonInterface|string|null $end): string
    {
        $from = $this->day($start);
        if ($from === null) {
            return '';
        }

        $to = $this->day($end) ?? $from;
        $midpoint = $from->addDays(intdiv((int) $from->diffInDays($to), 2));

        if ($this->calendar === 'ethiopian') {
            $eth = app(EthiopianCalendar::class)->gregorianToEthiopian(Carbon::instance($midpoint));

            return $this->monthName($eth['month']).' '.$eth['year'];
        }

        return $this->locale === 'am'
            ? $midpoint->locale('am')->translatedFormat('F Y')
            : $midpoint->format('F Y');
    }

    /** A payroll run's month, or its stored label for a run without dates. */
    public function periodOf(?PayrollRun $run): string
    {
        if ($run?->period_start === null) {
            return (string) $run?->period_label;
        }

        return $this->period($run->period_start->toDateString(), $run->period_end?->toDateString());
    }

    /**
     * A run's period as a stored message parameter: `Y-m-d/Y-m-d`, written
     * for each reader by `period()`, or the stored label when it has no dates.
     */
    public static function periodValue(?PayrollRun $run): string
    {
        if ($run?->period_start === null) {
            return (string) $run?->period_label;
        }

        return $run->period_start->toDateString().'/'.($run->period_end ?? $run->period_start)->toDateString();
    }

    private function clock(int $hour, int $minute): string
    {
        if ($this->clock === ClockPreference::TWENTY_FOUR_HOUR) {
            return sprintf('%02d:%02d', $hour, $minute);
        }

        $reading = (($hour - 6) % 12 + 12) % 12;
        $period = match (true) {
            $hour >= 6 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 18 => 'day',
            $hour >= 18 => 'evening',
            default => 'night',
        };

        return sprintf('%d:%02d %s', $reading === 0 ? 12 : $reading, $minute, self::PERIODS[$this->locale][$period]);
    }

    private function monthName(int $month): string
    {
        return self::MONTHS[$this->locale][$month - 1] ?? (string) $month;
    }

    /** The calendar day of a value: a bare date as written, an instant in the organisation's zone. */
    private function day(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', trim($value), 'UTC') ?: null;
        }

        $instant = $this->instant($value);
        if ($instant === null) {
            return null;
        }

        $local = $instant->setTimezone($this->timeZone);

        return CarbonImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), 'UTC') ?: null;
    }

    private function instant(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Models\Tenant;
use App\Services\Calendar\EthiopianCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The year an organisation's leave balances run on: Gregorian (1 January to
 * 31 December) or Ethiopian (Meskerem 1 to Pagume 5 or 6).
 *
 * `LeaveBalance.year` is the leave year's own number: 2026 for a Gregorian
 * year, 2019 for the Ethiopian year that starts on 11 September 2026. Every
 * reader of a balance asks this class which year a date falls in. Until
 * 2026-11-02 they all read the Gregorian `->year`, so an organisation keeping
 * its books on the Ethiopian calendar reset leave on 1 January.
 *
 * Monthly accrual counts twelve months a year. Pagume, five or six days, is
 * part of the twelfth month (Nehase): it earns no thirteenth month's leave.
 *
 * Every organisation runs on the Ethiopian year unless it chose otherwise:
 * `DEFAULT` is what an organisation without the setting gets. Organisations
 * that existed before 2026-11-02 were given the setting explicitly by
 * `forExisting()`, so one with leave already recorded under Gregorian year
 * numbers kept them.
 */
final class LeaveYear
{
    public const GREGORIAN = 'gregorian';

    public const ETHIOPIAN = 'ethiopian';

    public const CHOICES = [self::GREGORIAN, self::ETHIOPIAN];

    /** What an organisation without the setting runs on. */
    public const DEFAULT = self::ETHIOPIAN;

    private function __construct(
        public readonly string $kind,
        private readonly string $timeZone,
    ) {}

    public static function of(string $kind, string $timeZone = 'Africa/Addis_Ababa'): self
    {
        // Anything but Gregorian is the default. Written as two literals so
        // the published API contract types `leave_year` as their enum.
        return new self($kind !== self::GREGORIAN ? self::ETHIOPIAN : self::GREGORIAN, $timeZone);
    }

    public static function forTenant(?Tenant $tenant): self
    {
        // getAttribute(), as CalendarPreference reads it: the analyser types
        // the JSON column from the schema and cannot see the array cast.
        $settings = $tenant?->getAttribute('settings');
        $kind = is_array($settings) ? ($settings['leave_year'] ?? null) : null;

        return self::of(is_string($kind) ? $kind : self::DEFAULT, (string) ($tenant?->timezone ?: 'Africa/Addis_Ababa'));
    }

    public static function forTenantId(int $tenantId): self
    {
        return self::forTenant(Tenant::query()->find($tenantId));
    }

    public function isEthiopian(): bool
    {
        return $this->kind === self::ETHIOPIAN;
    }

    /** Today in the organisation's zone, at midnight. */
    public function today(): Carbon
    {
        return Carbon::now($this->timeZone)->startOfDay();
    }

    /** The leave year `$date` falls in. */
    public function yearOf(CarbonInterface $date): int
    {
        if (! $this->isEthiopian()) {
            return $date->year;
        }

        return self::calendar()->gregorianToEthiopian(Carbon::parse($date->format('Y-m-d')))['year'];
    }

    public function current(): int
    {
        return $this->yearOf($this->today());
    }

    /** The month of its leave year `$date` falls in, 1 to 12; Pagume counts as 12. */
    public function monthOf(CarbonInterface $date): int
    {
        if (! $this->isEthiopian()) {
            return $date->month;
        }

        return min(12, self::calendar()->gregorianToEthiopian(Carbon::parse($date->format('Y-m-d')))['month']);
    }

    /** The first day of leave year `$year`. */
    public function starts(int $year): Carbon
    {
        return $this->isEthiopian()
            ? self::calendar()->ethiopianToGregorian($year, 1, 1)
            : Carbon::create($year, 1, 1)->startOfDay();
    }

    /** The last day of month `$month` (1 to 12) of leave year `$year`; the twelfth runs to the end of Pagume. */
    public function monthEnds(int $year, int $month): Carbon
    {
        if (! $this->isEthiopian()) {
            return Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        }

        return $month >= 12
            ? $this->starts($year + 1)->subDay()
            : self::calendar()->ethiopianToGregorian($year, $month, 30);
    }

    /** Whole days from the start of `$date`'s leave year to `$date`. */
    public function dayOfYear(CarbonInterface $date): int
    {
        return (int) $this->starts($this->yearOf($date))->diffInDays(Carbon::parse($date->format('Y-m-d'))) + 1;
    }

    /**
     * Whether an organisation can move to another leave year without losing
     * anything: no balance has leave used, pending or carried into it. A
     * balance with only its entitlement is rebuilt under the new year.
     */
    public static function canChange(int $tenantId): bool
    {
        // The query builder, not LeaveBalance: the tenant scope returns no
        // rows when no tenant is resolved (a migration, the console), which
        // would read as "nothing recorded" for every organisation.
        return ! DB::table('leave_balances')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('used_days', '>', 0)
                ->orWhere('pending_days', '>', 0)
                ->orWhere('carried_days', '>', 0))
            ->exists();
    }

    /**
     * The leave year an existing organisation should run on: the Ethiopian
     * year, unless leave is already recorded under the Gregorian year numbers
     * it ran on before the setting existed.
     */
    public static function forExisting(int $tenantId): string
    {
        return self::canChange($tenantId) ? self::DEFAULT : self::GREGORIAN;
    }

    private static function calendar(): EthiopianCalendar
    {
        return app(EthiopianCalendar::class);
    }
}

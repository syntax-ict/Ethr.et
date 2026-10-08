<?php

declare(strict_types=1);

namespace App\Services\Holiday;

use App\Models\Holiday;
use App\Services\Calendar\EthiopianCalendar;
use App\Services\CurrentTenant;
use Carbon\Carbon;

final class HolidayService
{
    public function __construct(
        private readonly EthiopianCalendar $calendar,
    ) {}

    /**
     * The set of holiday dates for a tenant within [$from, $to], as a map of
     * 'Y-m-d' => true for O(1) membership checks. Fetches once so callers that
     * test many dates (e.g. payroll scanning a period's attendance) avoid a
     * per-date query.
     *
     * @return array<string, bool>
     */
    public function getHolidayDates(int $tenantId, Carbon $from, Carbon $to, ?int $branchId = null): array
    {
        return Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) {
                    $q->orWhere('branch_id', $branchId);
                }
            })
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [Carbon::parse($date)->format('Y-m-d') => true])
            ->all();
    }

    public function getEthiopianHolidays(int $gregorianYear): array
    {
        $holidays = [];

        $holidays[] = [
            'name' => 'Ethiopian New Year (Enkutatash)',
            'name_am' => 'እንቁጣጣሽ',
            'date' => $this->ethiopianToGregorian($gregorianYear, 1, 1),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Meskel (Finding of the True Cross)',
            'name_am' => 'መስቀል',
            'date' => $this->ethiopianToGregorian($gregorianYear, 1, 17),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Timkat (Epiphany)',
            'name_am' => 'ጥምቀት',
            'date' => $this->ethiopianToGregorian($gregorianYear, 5, 11),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Adwa Victory Day',
            'name_am' => 'የአድዋ ድል',
            'date' => $this->ethiopianToGregorian($gregorianYear, 6, 23),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Labour Day',
            'name_am' => 'የሠራተኞች ቀን',
            'date' => Carbon::create($gregorianYear, 5, 1)->format('Y-m-d'),
            'ethiopian_calendar' => false,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Ethiopian Patriots Day',
            'name_am' => 'የአርበኞች ቀን',
            'date' => Carbon::create($gregorianYear, 5, 5)->format('Y-m-d'),
            'ethiopian_calendar' => false,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Downfall of the Derg',
            'name_am' => 'ደርግ የወደቀበት ቀን',
            'date' => Carbon::create($gregorianYear, 5, 28)->format('Y-m-d'),
            'ethiopian_calendar' => false,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Ethiopian Christmas (Genna)',
            'name_am' => 'ገና',
            'date' => $this->ethiopianToGregorian($gregorianYear, 4, 29),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        // ── Movable feasts ────────────────────────────────────────────────
        // Orthodox Easter is computed (Alexandrian computus), so Siklet and
        // Fasika are exact. The Islamic feasts come from the tabular Hijri
        // calendar and are marked estimated — Ethiopia observes them by local
        // moon sighting, which can shift the date by a day either way.

        $holidays[] = [
            'name' => 'Good Friday (Siklet)',
            'name_am' => 'ስቅለት',
            'date' => $this->calendar->orthodoxGoodFriday($gregorianYear)->format('Y-m-d'),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        $holidays[] = [
            'name' => 'Ethiopian Easter (Fasika)',
            'name_am' => 'ፋሲካ',
            'date' => $this->calendar->orthodoxEaster($gregorianYear)->format('Y-m-d'),
            'ethiopian_calendar' => true,
            'recurring' => true,
        ];

        foreach ($this->islamicHolidays($gregorianYear) as $islamic) {
            $holidays[] = $islamic;
        }

        return $holidays;
    }

    /**
     * Eid al-Fitr, Eid al-Adha and Mawlid for a Gregorian year.
     *
     * A Hijri year is ~11 days shorter than a Gregorian one, so a feast can
     * fall twice in the same Gregorian year — each occurrence is returned.
     *
     * @return list<array{name: string, name_am: string, date: string, ethiopian_calendar: bool, recurring: bool, is_estimated: bool}>
     */
    private function islamicHolidays(int $gregorianYear): array
    {
        $feasts = [
            // [name, Amharic name, Hijri month, Hijri day]
            ['Eid al-Fitr', 'ኢድ አል ፈጥር', 10, 1],
            ['Eid al-Adha (Arafa)', 'አረፋ', 12, 10],
            ['Prophet Muhammad Birthday (Mawlid)', 'መውሊድ', 3, 12],
        ];

        $holidays = [];

        foreach ($feasts as [$name, $nameAm, $hijriMonth, $hijriDay]) {
            $hijriYears = $this->calendar->hijriYearsOverlapping(
                $gregorianYear,
                $hijriMonth,
                $hijriDay,
            );

            foreach ($hijriYears as $hijriYear) {
                $holidays[] = [
                    'name' => $name,
                    'name_am' => $nameAm,
                    'date' => $this->calendar
                        ->hijriToGregorian($hijriYear, $hijriMonth, $hijriDay)
                        ->format('Y-m-d'),
                    'ethiopian_calendar' => false,
                    'recurring' => true,
                    'is_estimated' => true,
                ];
            }
        }

        return $holidays;
    }

    public function autoDetect(int $tenantId, int $gregorianYear): int
    {
        $holidays = $this->getEthiopianHolidays($gregorianYear);
        $created = 0;

        foreach ($holidays as $holidayData) {
            // Match on the date alone, not name + date: a tenant that already
            // recorded "Ethiopian Christmas" must not gain a second row when
            // auto-detect proposes "Ethiopian Christmas (Genna)" for the same
            // day. Branch-specific holidays are left alone — they coexist with
            // the tenant-wide ones by design.
            $exists = Holiday::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereNull('branch_id')
                ->whereDate('date', $holidayData['date'])
                ->exists();

            if ($exists) {
                continue;
            }

            Holiday::create([
                'tenant_id' => $tenantId,
                'name' => $holidayData['name'],
                'name_am' => $holidayData['name_am'],
                'date' => $holidayData['date'],
                'ethiopian_calendar' => $holidayData['ethiopian_calendar'],
                'recurring' => $holidayData['recurring'],
                'is_estimated' => $holidayData['is_estimated'] ?? false,
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * The date in Gregorian $gregorianYear of an Ethiopian-calendar holiday.
     *
     * Two Ethiopian years overlap any Gregorian year: ($gregorianYear - 8),
     * which ends in September, and ($gregorianYear - 7), which begins then. A
     * fixed Ethiopian date falls in exactly one of them inside this Gregorian
     * year, and that is the one returned.
     *
     * Until 2026-10-07 this always used ($gregorianYear - 7), the year
     * beginning in September. A run for 2026 then produced Genna, Timkat and
     * Adwa for January–March 2027 while its Labour Day and Easter were 2026's,
     * so this year's were never created by any run a new tenant got, and
     * attendance and payroll treated them as working days (audit N57).
     * Conversion is delegated to the shared, exact EthiopianCalendar service.
     */
    private function ethiopianToGregorian(int $gregorianYear, int $ethMonth, int $ethDay): string
    {
        foreach ([$gregorianYear - 8, $gregorianYear - 7] as $ethiopianYear) {
            $date = $this->calendar->ethiopianToGregorian($ethiopianYear, $ethMonth, $ethDay);
            if ($date->year === $gregorianYear) {
                return $date->format('Y-m-d');
            }
        }

        // Unreachable for the twelve regular months; kept so a Pagume date
        // in a year without it cannot silently pick the wrong year.
        throw new \LogicException("No {$ethMonth}/{$ethDay} EC falls in {$gregorianYear}.");
    }

    /**
     * Make "recurring every year" true for $toYear: the statutory holidays,
     * and the tenant's own recurring holidays from the year before.
     *
     * The `recurring` flag was saved and read by nothing, so a company holiday
     * marked recurring applied to one year only (audit N58). Statutory
     * holidays come from autoDetect(), which computes each year properly,
     * movable feasts included. A tenant's own recurring holiday is copied to
     * the same month and day, but only a Gregorian, non-estimated one. An
     * Ethiopian-calendar or estimated holiday moves between Gregorian years by
     * a rule this cannot know, and copying it by date would invent a wrong
     * holiday.
     *
     * Idempotent, like autoDetect: a date the tenant already has, on the same
     * branch, is skipped. Runs for the coming year only, never the current
     * one, so a holiday a tenant removed this year does not come back.
     *
     * Requires $tenantId to be the current tenant (RollForwardHolidaysJob sets
     * it), so these queries need no scope bypass.
     */
    public function rollForward(int $tenantId, int $toYear): int
    {
        if (app(CurrentTenant::class)->id() !== $tenantId) {
            throw new \LogicException('rollForward() needs the tenant it rolls forward to be current.');
        }

        $created = $this->autoDetect($tenantId, $toYear);

        $recurring = Holiday::query()
            ->where('recurring', true)
            ->where('ethiopian_calendar', false)
            ->where('is_estimated', false)
            ->whereYear('date', $toYear - 1)
            ->get();

        foreach ($recurring as $holiday) {
            $previous = Carbon::parse($holiday->date);
            // 29 February has no equivalent in a common year; it is skipped
            // rather than moved to a day the tenant never chose.
            if (! checkdate($previous->month, $previous->day, $toYear)) {
                continue;
            }
            $date = Carbon::create($toYear, $previous->month, $previous->day)->format('Y-m-d');

            $exists = Holiday::query()
                ->whereDate('date', $date)
                ->where('branch_id', $holiday->branch_id)
                ->exists();
            if ($exists) {
                continue;
            }

            Holiday::create([
                'tenant_id' => $tenantId,
                'branch_id' => $holiday->branch_id,
                'name' => $holiday->name,
                'name_am' => $holiday->name_am,
                'date' => $date,
                'ethiopian_calendar' => false,
                'recurring' => true,
                'is_estimated' => false,
                'is_active' => $holiday->is_active,
            ]);
            $created++;
        }

        return $created;
    }
}

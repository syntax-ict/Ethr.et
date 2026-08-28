<?php

declare(strict_types=1);

namespace App\Services\Holiday;

use App\Models\Holiday;
use App\Services\Calendar\EthiopianCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class HolidayService
{
    public function __construct(
        private readonly EthiopianCalendar $calendar,
    ) {}

    public function getHolidays(int $tenantId, int $year): Collection
    {
        return Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get();
    }

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

    public function isHoliday(int $tenantId, Carbon $date, ?int $branchId = null): bool
    {
        return Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereDate('date', $date->format('Y-m-d'))
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) {
                    $q->orWhere('branch_id', $branchId);
                }
            })
            ->exists();
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
     * Resolve the Gregorian date of an Ethiopian-calendar holiday for the
     * Ethiopian year that begins in September of $gregorianYear.
     *
     * That Ethiopian year is ($gregorianYear - 7): e.g. the year starting in
     * September 2024 is 2017 EC. Conversion is delegated to the shared, exact
     * EthiopianCalendar service so there is a single implementation. For all
     * current-century inputs this yields dates identical to the previous
     * inline Sept-11/12 approximation; beyond ~2100 it is strictly more
     * accurate (it tracks the widening Julian/Gregorian gap the old rule
     * ignored).
     */
    private function ethiopianToGregorian(int $gregorianYear, int $ethMonth, int $ethDay): string
    {
        return $this->calendar
            ->ethiopianToGregorian($gregorianYear - 7, $ethMonth, $ethDay)
            ->format('Y-m-d');
    }
}

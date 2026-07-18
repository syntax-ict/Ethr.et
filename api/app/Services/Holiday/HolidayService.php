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

        return $holidays;
    }

    public function autoDetect(int $tenantId, int $gregorianYear): int
    {
        $holidays = $this->getEthiopianHolidays($gregorianYear);
        $created = 0;

        foreach ($holidays as $holidayData) {
            $exists = Holiday::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('name', $holidayData['name'])
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

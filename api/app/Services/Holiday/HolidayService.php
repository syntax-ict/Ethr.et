<?php

declare(strict_types=1);

namespace App\Services\Holiday;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class HolidayService
{
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
     * Approximate Ethiopian calendar to Gregorian.
     * Ethiopian months 1-12 have 30 days each, month 13 (Pagume) has 5 or 6.
     * Ethiopian New Year is September 11 (or 12 in leap year before Gregorian leap).
     */
    private function ethiopianToGregorian(int $gregorianYear, int $ethMonth, int $ethDay): string
    {
        $ethNewYear = Carbon::create($gregorianYear, 9, 11);

        if ($this->isEthiopianLeapYear($gregorianYear)) {
            $ethNewYear = Carbon::create($gregorianYear, 9, 12);
        }

        $daysFromNewYear = ($ethMonth - 1) * 30 + ($ethDay - 1);

        return $ethNewYear->copy()->addDays($daysFromNewYear)->format('Y-m-d');
    }

    private function isEthiopianLeapYear(int $gregorianYear): bool
    {
        return ($gregorianYear + 1) % 4 === 0;
    }
}

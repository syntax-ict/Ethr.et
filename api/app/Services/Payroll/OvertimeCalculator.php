<?php

declare(strict_types=1);

namespace App\Services\Payroll;

final class OvertimeCalculator
{
    private float $normalRate = 1.25;
    private float $nightRate = 1.5;
    private float $holidayRate = 2.0;
    private float $holidayNightRate = 2.5;

    public function calculate(
        int $basicSalaryCents,
        int $workingDaysPerMonth,
        int $hoursPerDay,
        int $overtimeMinutes,
        string $type = 'normal',
    ): int {
        if ($overtimeMinutes <= 0 || $workingDaysPerMonth <= 0 || $hoursPerDay <= 0) {
            return 0;
        }

        $hourlyRate = $basicSalaryCents / ($workingDaysPerMonth * $hoursPerDay);
        $overtimeHours = $overtimeMinutes / 60;

        $multiplier = match ($type) {
            'night' => $this->nightRate,
            'holiday' => $this->holidayRate,
            'holiday_night' => $this->holidayNightRate,
            default => $this->normalRate,
        };

        return (int) round($hourlyRate * $overtimeHours * $multiplier);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class AttendanceIntelligence
{
    public function __construct(
        private readonly ShiftMatcher $shiftMatcher,
    ) {}

    public function detectLate(AttendanceRecord $record): bool
    {
        if (! $record->shift || ! $record->check_in) {
            return false;
        }

        $shiftStart = $record->check_in->copy()->setTimeFromTimeString($record->shift->start_time);
        $graceEnd = $shiftStart->copy()->addMinutes($record->shift->grace_minutes);

        return $record->check_in->gt($graceEnd);
    }

    public function detectEarlyLeave(AttendanceRecord $record): bool
    {
        if (! $record->shift || ! $record->check_out) {
            return false;
        }

        $shiftEnd = $record->check_out->copy()->setTimeFromTimeString($record->shift->end_time);

        if ($record->shift->crosses_midnight && $shiftEnd->lte($record->check_in)) {
            $shiftEnd->addDay();
        }

        $earlyThreshold = $shiftEnd->copy()->subMinutes($record->shift->early_departure_minutes);

        return $record->check_out->lt($earlyThreshold);
    }

    public function calculateOvertime(AttendanceRecord $record): int
    {
        return $record->overtimeMinutes();
    }

    public function detectMissingPunch(Employee $employee, Carbon $date): ?string
    {
        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date->format('Y-m-d'))
            ->latest('check_in')
            ->first();

        if (! $record) {
            return null;
        }

        if ($record->check_in && ! $record->check_out) {
            return 'missing_check_out';
        }

        if (! $record->check_in && $record->check_out) {
            return 'missing_check_in';
        }

        return null;
    }

    public function detectAnomalies(AttendanceRecord $record): array
    {
        $anomalies = [];

        if ($record->workedMinutes() > 960) {
            $anomalies[] = 'excessive_hours';
        }

        if ($record->overtimeMinutes() > 240) {
            $anomalies[] = 'excessive_overtime';
        }

        return $anomalies;
    }

    public function getLateArrivals(int $tenantId, ?string $date = null): Collection
    {
        $date ??= now()->format('Y-m-d');

        return AttendanceRecord::query()
            ->where('status', AttendanceStatus::LATE)
            ->whereDate('date', $date)
            ->with('employee', 'shift')
            ->get()
            ->map(function (AttendanceRecord $record) {
                $minutesLate = 0;
                if ($record->shift && $record->check_in) {
                    $shiftStart = $record->check_in->copy()->setTimeFromTimeString($record->shift->start_time);
                    $minutesLate = (int) $shiftStart->diffInMinutes($record->check_in);
                }

                return [
                    'record' => $record,
                    'minutes_late' => $minutesLate,
                ];
            });
    }

    public function getEarlyDepartures(int $tenantId, ?string $date = null): Collection
    {
        $date ??= now()->format('Y-m-d');

        return AttendanceRecord::query()
            ->where('status', AttendanceStatus::EARLY_LEAVE)
            ->whereDate('date', $date)
            ->with('employee', 'shift')
            ->get();
    }

    public function getMissingPunches(int $tenantId, ?string $date = null): Collection
    {
        $date ??= now()->format('Y-m-d');

        return AttendanceRecord::query()
            ->whereDate('date', $date)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with('employee')
            ->get();
    }

    public function getOvertimeSummary(int $tenantId, string $period = 'monthly'): array
    {
        $startDate = match ($period) {
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            default => now()->startOfMonth(),
        };

        $records = AttendanceRecord::query()
            ->whereDate('date', '>=', $startDate->format('Y-m-d'))
            ->whereDate('date', '<=', now()->format('Y-m-d'))
            ->whereNotNull('check_out')
            ->with('employee', 'shift')
            ->get();

        $byEmployee = [];

        foreach ($records as $record) {
            $ot = $record->overtimeMinutes();
            if ($ot <= 0) {
                continue;
            }

            $empId = $record->employee_id;
            if (! isset($byEmployee[$empId])) {
                $byEmployee[$empId] = [
                    'employee' => $record->employee,
                    'total_overtime_minutes' => 0,
                    'days_with_overtime' => 0,
                ];
            }

            $byEmployee[$empId]['total_overtime_minutes'] += $ot;
            $byEmployee[$empId]['days_with_overtime']++;
        }

        usort($byEmployee, fn ($a, $b) => $b['total_overtime_minutes'] <=> $a['total_overtime_minutes']);

        return $byEmployee;
    }
}

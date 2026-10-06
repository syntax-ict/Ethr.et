<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use Illuminate\Support\Collection;

final class AttendanceIntelligence
{
    /** Threshold constants also read by the intelligence dashboard so the UI's
     *  explanation text can't drift from what `detectAnomalies()` actually checks. */
    public const EXCESSIVE_HOURS_MINUTES = 960;

    public const EXCESSIVE_OVERTIME_MINUTES = 240;

    public function __construct(
        private readonly ShiftMatcher $shiftMatcher,
    ) {}

    /**
     * The anomaly keys flagged for one record, in detection order.
     *
     * Annotated as a `list` rather than a bare `array` because `getAnomalies()`
     * filters on it and then declares a `list<string>`: without this, the filter
     * narrows to `non-empty-array` and the two signatures disagree.
     *
     * @return list<string>
     */
    public function detectAnomalies(AttendanceRecord $record): array
    {
        $anomalies = [];

        if ($record->workedMinutes() > self::EXCESSIVE_HOURS_MINUTES) {
            $anomalies[] = 'excessive_hours';
        }

        if ($record->overtimeMinutes() > self::EXCESSIVE_OVERTIME_MINUTES) {
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

    /**
     * Surfaces what `detectAnomalies()` flags — excessive hours / excessive
     * overtime — for an analyst review UI. The detector had no caller outside
     * `ScanAttendanceAnomaliesJob` (a fire-and-forget supervisor notification),
     * so there was nowhere to see *why* a day was flagged after the fact.
     * Records with completed punches only: `workedMinutes()`/`overtimeMinutes()`
     * both need `check_out` to mean anything, and an in-progress shift isn't an
     * anomaly yet.
     *
     * @return Collection<int, array{record: AttendanceRecord, types: list<string>, worked_minutes: int, overtime_minutes: int}>
     */
    public function getAnomalies(int $tenantId, ?string $date = null): Collection
    {
        $date ??= now()->format('Y-m-d');

        return AttendanceRecord::query()
            ->whereDate('date', $date)
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->with('employee', 'shift')
            ->get()
            ->map(fn (AttendanceRecord $record) => [
                'record' => $record,
                'types' => $this->detectAnomalies($record),
                'worked_minutes' => $record->workedMinutes(),
                'overtime_minutes' => $record->overtimeMinutes(),
            ])
            ->filter(fn (array $item) => $item['types'] !== [])
            ->values();
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

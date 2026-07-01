<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Team;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TeamMonitoringController extends Controller
{
    public function attendanceToday(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewTeam');

        $teamIds = $this->getTeamEmployeeIds($request->user()->employee);

        if (empty($teamIds)) {
            return response()->json(['employees' => [], 'summary' => ['present' => 0, 'absent' => 0, 'late' => 0, 'on_leave' => 0]]);
        }

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $teamIds)
            ->whereDate('date', Carbon::today())
            ->with('employee:id,public_id,name,photo_path')
            ->get()
            ->keyBy('employee_id');

        $onLeaveIds = LeaveRequest::query()
            ->whereIn('employee_id', $teamIds)
            ->where('status', LeaveStatus::APPROVED)
            ->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->pluck('employee_id')
            ->toArray();

        $employees = Employee::query()
            ->whereIn('id', $teamIds)
            ->with('department:id,name')
            ->get()
            ->map(function (Employee $emp) use ($records, $onLeaveIds) {
                $record = $records->get($emp->id);

                if (in_array($emp->id, $onLeaveIds)) {
                    $status = 'on_leave';
                } elseif ($record) {
                    $status = $record->status ?? ($record->check_out ? 'present' : 'checked_in');
                } else {
                    $status = 'absent';
                }

                return [
                    'public_id' => $emp->public_id,
                    'name' => $emp->name,
                    'department' => $emp->department?->name,
                    'photo_path' => $emp->photo_path,
                    'status' => $status,
                    'check_in' => $record?->check_in,
                    'check_out' => $record?->check_out,
                ];
            });

        $summary = [
            'present' => $employees->whereIn('status', ['present', 'checked_in', 'late'])->count(),
            'absent' => $employees->where('status', 'absent')->count(),
            'late' => $employees->where('status', 'late')->count(),
            'on_leave' => $employees->where('status', 'on_leave')->count(),
        ];

        return response()->json([
            'employees' => $employees->values(),
            'summary' => $summary,
            'date' => Carbon::today()->format('Y-m-d'),
        ]);
    }

    public function attendanceSummary(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewTeam');

        $period = $request->query('period', 'weekly');
        $teamIds = $this->getTeamEmployeeIds($request->user()->employee);

        if (empty($teamIds)) {
            return response()->json(['data' => []]);
        }

        [$from, $to] = match ($period) {
            'monthly' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            default => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
        };

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $teamIds)
            ->whereBetween('date', [$from, $to])
            ->get();

        $data = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if ($day->isWeekend()) {
                continue;
            }
            $dayRecords = $records->filter(fn ($r) => $r->date->isSameDay($day));
            $data[] = [
                'date' => $day->format('Y-m-d'),
                'present' => $dayRecords->count(),
                'absent' => count($teamIds) - $dayRecords->count(),
                'late' => $dayRecords->where('status', 'late')->count(),
                'rate' => count($teamIds) > 0
                    ? round(($dayRecords->count() / count($teamIds)) * 100, 1)
                    : 0,
            ];
        }

        return response()->json([
            'period' => $period,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'team_size' => count($teamIds),
            'data' => $data,
        ]);
    }

    public function overtime(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewTeam');

        $teamIds = $this->getTeamEmployeeIds($request->user()->employee);

        if (empty($teamIds)) {
            return response()->json(['employees' => [], 'total_hours' => 0]);
        }

        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfMonth();

        // Standard work day is 8 hours (480 minutes)
        $standardMinutes = 480;

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $teamIds)
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->with('employee:id,public_id,name')
            ->get()
            ->groupBy('employee_id');

        $employees = $records->map(function ($empRecords, $empId) use ($standardMinutes) {
            $employee = $empRecords->first()->employee;
            $totalWorkedMinutes = $empRecords->sum(function ($record) {
                return (int) Carbon::parse($record->check_in)->diffInMinutes(Carbon::parse($record->check_out));
            });
            $expectedMinutes = $empRecords->count() * $standardMinutes;
            $overtimeMinutes = max(0, $totalWorkedMinutes - $expectedMinutes);

            return [
                'public_id' => $employee?->public_id,
                'name' => $employee?->name,
                'days_worked' => $empRecords->count(),
                'overtime_hours' => round($overtimeMinutes / 60, 1),
                'overtime_minutes' => $overtimeMinutes,
            ];
        })->values()->sortByDesc('overtime_hours')->values();

        return response()->json([
            'month' => $from->format('Y-m'),
            'employees' => $employees,
            'total_overtime_hours' => round($employees->sum('overtime_minutes') / 60, 1),
        ]);
    }

    public function leaveCalendar(Request $request): JsonResponse
    {
        Gate::authorize('leave.viewTeam');

        $month = $request->query('month', Carbon::now()->format('Y-m'));
        $teamIds = $this->getTeamEmployeeIds($request->user()->employee);

        if (empty($teamIds)) {
            return response()->json(['employees' => [], 'days' => []]);
        }

        try {
            $from = Carbon::parse($month . '-01')->startOfMonth();
            $to = $from->copy()->endOfMonth();
        } catch (\Throwable) {
            $from = Carbon::now()->startOfMonth();
            $to = Carbon::now()->endOfMonth();
        }

        $leaves = LeaveRequest::query()
            ->whereIn('employee_id', $teamIds)
            ->where('status', LeaveStatus::APPROVED)
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->with('employee:id,public_id,name', 'leaveType:id,name,color')
            ->get();

        $employees = Employee::query()
            ->whereIn('id', $teamIds)
            ->select('id', 'public_id', 'name', 'photo_path')
            ->get();

        $calendar = $employees->map(function (Employee $emp) use ($leaves, $from, $to) {
            $empLeaves = $leaves->where('employee_id', $emp->id);
            $days = [];

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $onLeave = $empLeaves->first(fn ($l) => $l->start_date <= $day && $l->end_date >= $day);
                if ($onLeave) {
                    $days[$day->format('Y-m-d')] = [
                        'on_leave' => true,
                        'leave_type' => $onLeave->leaveType?->name,
                        'color' => $onLeave->leaveType?->color ?? '#6366f1',
                    ];
                }
            }

            return [
                'public_id' => $emp->public_id,
                'name' => $emp->name,
                'photo_path' => $emp->photo_path,
                'days' => $days,
            ];
        });

        // Daily summary: how many on leave each day
        $dailySummary = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->format('Y-m-d');
            $count = $leaves->filter(fn ($l) => $l->start_date <= $day && $l->end_date >= $day)->count();
            $dailySummary[$date] = ['on_leave_count' => $count];
        }

        return response()->json([
            'month' => $from->format('Y-m'),
            'team_size' => $employees->count(),
            'employees' => $calendar->values(),
            'daily_summary' => $dailySummary,
        ]);
    }

    private function getTeamEmployeeIds(?Employee $manager): array
    {
        if (! $manager) {
            return [];
        }

        return Employee::query()
            ->where('supervisor_id', $manager->id)
            ->pluck('id')
            ->toArray();
    }
}

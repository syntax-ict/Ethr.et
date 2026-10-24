<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Team;

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Attendance\ExpectedDays;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use App\Support\TenantTime;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TeamMonitoringController extends Controller
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly ExpectedDays $expectedDays,
    ) {}

    public function attendanceToday(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewTeam');

        $teamIds = $this->getTeamEmployeeIds($request->user()->employee);

        if (empty($teamIds)) {
            return response()->json(['employees' => [], 'summary' => ['present' => 0, 'absent' => 0, 'late' => 0, 'on_leave' => 0]]);
        }

        $date = TenantTime::today(app(CurrentTenant::class)->get());

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $teamIds)
            ->whereDate('date', $date->format('Y-m-d'))
            ->with('employee:id,public_id,name,photo_path')
            ->get()
            ->keyBy('employee_id');

        $team = Employee::query()
            ->whereIn('id', $teamIds)
            ->with('department:id,name')
            ->get();

        // Someone who has left is no longer on the team, and a holiday or a
        // rest day is not an absence. ExpectedDays decides both.
        $expected = $this->expectedDays->onDate($team, $date);

        $employees = $team
            ->reject(fn (Employee $emp): bool => ($expected[$emp->id] ?? null) === ExpectedDay::NOT_EMPLOYED)
            ->map(function (Employee $emp) use ($records, $expected) {
                $record = $records->get($emp->id);
                $kind = $expected[$emp->id] ?? ExpectedDay::WORKDAY;

                if ($kind === ExpectedDay::ON_LEAVE) {
                    $status = 'on_leave';
                } elseif ($record) {
                    $status = $record->status ?? ($record->check_out ? 'present' : 'checked_in');
                } elseif ($kind === ExpectedDay::WORKDAY) {
                    $status = 'absent';
                } else {
                    $status = $kind->value;
                }

                return [
                    'public_id' => $emp->public_id,
                    'name' => $emp->name,
                    'department' => $emp->department?->name,
                    'photo_path' => $emp->photo_path,
                    'photo_thumb_url' => $this->storage->thumbnailUrlOrNull($emp->photo_path, 150),
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
            'date' => $date->format('Y-m-d'),
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

        $today = TenantTime::today(app(CurrentTenant::class)->get())->format('Y-m-d');

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $teamIds)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get();

        $attended = [];
        foreach ($records as $record) {
            $attended[$record->date->format('Y-m-d')][$record->employee_id] = $record;
        }

        // Each member's own days: their shift's working week, the holidays of
        // their branch, their leave and their employment dates.
        $expected = [];
        foreach (Employee::query()->whereIn('id', $teamIds)->get() as $member) {
            foreach ($this->expectedDays->forEmployee($member, $from, $to) as $day => $kind) {
                $expected[$day][$member->id] = $kind;
            }
        }

        $data = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $key = $day->format('Y-m-d');

            // A day not yet reached has no absences to report.
            if ($key > $today) {
                break;
            }

            $dayRecords = collect($attended[$key] ?? []);
            $due = collect($expected[$key] ?? [])->filter(fn (ExpectedDay $kind): bool => $kind === ExpectedDay::WORKDAY);

            if ($due->isEmpty() && $dayRecords->isEmpty()) {
                continue;
            }

            $absent = $due->keys()->reject(fn (int $id): bool => $dayRecords->has($id))->count();

            $data[] = [
                'date' => $key,
                'present' => $dayRecords->count(),
                'absent' => $absent,
                'late' => $dayRecords->where('status', AttendanceStatus::LATE)->count(),
                'rate' => $due->isNotEmpty()
                    ? round((($due->count() - $absent) / $due->count()) * 100, 1)
                    : 0,
            ];
        }

        return response()->json([
            'period' => $period === 'monthly' ? 'monthly' : 'weekly',
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
            $from = Carbon::parse($month.'-01')->startOfMonth();
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
            ->with('employee:id,public_id,name', 'leaveType:id,name,code')
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
                        'color' => $onLeave->leaveType?->calendarColor() ?? '#64748B',
                    ];
                }
            }

            return [
                'public_id' => $emp->public_id,
                'name' => $emp->name,
                'photo_path' => $emp->photo_path,
                'photo_thumb_url' => $this->storage->thumbnailUrlOrNull($emp->photo_path, 150),
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

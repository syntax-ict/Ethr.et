<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Attendance\ExpectedDays;
use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /employees/{employee}/attendance/timeline
 *
 * Daily attendance summary for a single employee, suitable for rendering
 * a calendar heatmap on the employee profile.
 *
 * Query params:
 *   - from  (Y-m-d) — defaults to 90 days ago
 *   - to    (Y-m-d) — defaults to today
 *
 * Response:
 *   {
 *     employee: { public_id, name },
 *     range: { from, to },
 *     totals: { present, late, absent, on_leave, total_minutes_worked },
 *     days: [
 *       { date, status, check_in, check_out, worked_minutes, source, late_minutes }
 *     ]
 *   }
 *
 * A day with no record is `absent` only when it was a workday that has
 * passed. Otherwise it says why not: `weekend` (a rest day by the person's
 * shift or the organisation's working week), `holiday`, `on_leave`,
 * `not_employed`, or `upcoming` for a workday still ahead.
 */
class EmployeeAttendanceTimelineController extends Controller
{
    public function __construct(
        private readonly ExpectedDays $expectedDays,
    ) {}

    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        // Two dimensions, stated separately because they are separate questions:
        // may you read attendance at all, and may you read THIS employee.
        // attendance.view alone answers only the first -- it is in the $everyone
        // grant list -- and this endpoint returns 90 days of one named employee's
        // movements. EmployeePolicy::view() carries the orgScope() check.
        Gate::authorize('attendance.view');
        Gate::authorize('view', $employee);

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))
            : Carbon::now()->subDays(90);

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))
            : Carbon::now();

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->orderBy('date')
            ->get();

        // Which days were workdays at all: the shift's or the organisation's
        // working week, holidays, approved leave and employment dates. Only a
        // workday that has passed with no record is an absence.
        $expected = $this->expectedDays->forEmployee($employee, $from, $to);
        $today = TenantTime::today(app(CurrentTenant::class)->get())->toDateString();

        $byDate = $records->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());

        $days = [];
        $cursor = $from->copy();
        $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'total_minutes_worked' => 0];

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $record = $byDate->get($key);
            $kind = $expected[$key] ?? ExpectedDay::WORKDAY;
            $isWeekend = $kind === ExpectedDay::REST_DAY;

            if ($record) {
                $checkIn = $record->check_in ? Carbon::parse($record->check_in) : null;
                $checkOut = $record->check_out ? Carbon::parse($record->check_out) : null;
                $worked = $checkIn && $checkOut ? $checkIn->diffInMinutes($checkOut) : null;
                $status = $record->status->value;

                if ($status === 'late') {
                    $totals['late']++;
                } else {
                    $totals['present']++;
                }
                if ($worked) {
                    $totals['total_minutes_worked'] += $worked;
                }

                $days[] = [
                    'date' => $key,
                    'status' => $status,
                    'check_in' => $checkIn?->format('H:i:s'),
                    'check_out' => $checkOut?->format('H:i:s'),
                    'worked_minutes' => $worked,
                    'source' => $record->source->value,
                    'late_minutes' => null,
                    'is_weekend' => $isWeekend,
                ];
            } else {
                $status = match ($kind) {
                    ExpectedDay::WORKDAY => $key > $today ? 'upcoming' : 'absent',
                    ExpectedDay::REST_DAY => 'weekend',
                    ExpectedDay::HOLIDAY => 'holiday',
                    ExpectedDay::ON_LEAVE => 'on_leave',
                    ExpectedDay::NOT_EMPLOYED => 'not_employed',
                };

                if ($status === 'absent' || $status === 'on_leave') {
                    $totals[$status]++;
                }

                $days[] = [
                    'date' => $key,
                    'status' => $status,
                    'check_in' => null,
                    'check_out' => null,
                    'worked_minutes' => null,
                    'source' => null,
                    'late_minutes' => null,
                    'is_weekend' => $isWeekend,
                ];
            }

            $cursor->addDay();
        }

        return response()->json([
            'employee' => [
                'public_id' => $employee->public_id,
                'name' => $employee->name,
            ],
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => $totals,
            'days' => $days,
        ]);
    }
}

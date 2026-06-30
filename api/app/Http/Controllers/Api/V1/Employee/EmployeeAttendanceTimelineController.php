<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
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
 *     totals: { present, late, absent, total_minutes_worked },
 *     days: [
 *       { date, status, check_in, check_out, worked_minutes, source, late_minutes }
 *     ]
 *   }
 */
class EmployeeAttendanceTimelineController extends Controller
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('attendance.view');

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
            ->orderBy('date')
            ->get();

        $byDate = $records->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());

        $days = [];
        $cursor = $from->copy();
        $totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'total_minutes_worked' => 0];

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $record = $byDate->get($key);
            $isWeekend = $cursor->isWeekend();

            if ($record) {
                $checkIn = $record->check_in ? Carbon::parse($record->check_in) : null;
                $checkOut = $record->check_out ? Carbon::parse($record->check_out) : null;
                $worked = $checkIn && $checkOut ? $checkIn->diffInMinutes($checkOut) : null;
                $status = (string) ($record->status?->value ?? $record->status);

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
                    'source' => (string) ($record->source?->value ?? $record->source),
                    'late_minutes' => null,
                    'is_weekend' => $isWeekend,
                ];
            } else {
                if (! $isWeekend) {
                    $totals['absent']++;
                }
                $days[] = [
                    'date' => $key,
                    'status' => $isWeekend ? 'weekend' : 'absent',
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

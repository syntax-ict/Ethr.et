<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceRecordResource;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AttendanceIntelligenceController extends Controller
{
    public function __construct(
        private readonly AttendanceIntelligence $intelligence,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewAll');

        $tenant = app(CurrentTenant::class)->get();
        $date = $request->input('date', now()->format('Y-m-d'));

        $lateArrivals = $this->intelligence->getLateArrivals($tenant->id, $date);
        $earlyDepartures = $this->intelligence->getEarlyDepartures($tenant->id, $date);
        $missingPunches = $this->intelligence->getMissingPunches($tenant->id, $date);
        $anomalies = $this->intelligence->getAnomalies($tenant->id, $date);

        return response()->json([
            'date' => $date,
            'anomalies' => [
                'count' => $anomalies->count(),
                'thresholds' => [
                    'excessive_hours_minutes' => AttendanceIntelligence::EXCESSIVE_HOURS_MINUTES,
                    'excessive_overtime_minutes' => AttendanceIntelligence::EXCESSIVE_OVERTIME_MINUTES,
                ],
                'records' => $anomalies->map(fn ($item) => [
                    'employee_public_id' => $item['record']->employee?->public_id,
                    'employee_name' => $item['record']->employee?->name,
                    'types' => $item['types'],
                    'worked_minutes' => $item['worked_minutes'],
                    'overtime_minutes' => $item['overtime_minutes'],
                ])->values(),
            ],
            'late_arrivals' => [
                'count' => $lateArrivals->count(),
                'records' => $lateArrivals->map(fn ($item) => [
                    'employee_public_id' => $item['record']->employee?->public_id,
                    'employee_name' => $item['record']->employee?->name,
                    'minutes_late' => $item['minutes_late'],
                    'check_in' => $item['record']->check_in?->toIso8601String(),
                    'shift_start' => $item['record']->shift?->start_time,
                ])->values(),
            ],
            'early_departures' => [
                'count' => $earlyDepartures->count(),
                'records' => AttendanceRecordResource::collection($earlyDepartures),
            ],
            'missing_punches' => [
                'count' => $missingPunches->count(),
                'records' => AttendanceRecordResource::collection($missingPunches),
            ],
        ]);
    }

    public function overtime(Request $request): JsonResponse
    {
        Gate::authorize('attendance.viewAll');

        $tenant = app(CurrentTenant::class)->get();
        $period = $request->input('period', 'monthly');

        $summary = $this->intelligence->getOvertimeSummary($tenant->id, $period);

        $formatted = array_map(fn ($item) => [
            'employee_public_id' => $item['employee']?->public_id,
            'employee_name' => $item['employee']?->name,
            'total_overtime_minutes' => $item['total_overtime_minutes'],
            'days_with_overtime' => $item['days_with_overtime'],
        ], $summary);

        return response()->json([
            'period' => $period,
            'employees' => $formatted,
        ]);
    }
}

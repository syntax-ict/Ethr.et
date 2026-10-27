<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\MyAttendanceExceptionsRequest;
use App\Models\Employee;
use App\Services\CurrentTenant;
use App\Services\Payroll\AttendanceReconciliation;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The caller's own attendance exceptions (AttendanceReconciliation): absences
 * and lateness payroll will settle, so an employee who was at work can say so
 * with a missed-punch claim first. This month by default.
 */
class MyAttendanceExceptionsController extends Controller
{
    /**
     * @response array{period: array{start: string, end: string}, exceptions: list<array{type: string, date: string, state: string, minutes: int|null, record_public_id: string|null, request_kind: string|null, request_public_id: string|null}>}
     */
    public function __invoke(MyAttendanceExceptionsRequest $request, AttendanceReconciliation $reconciliation): JsonResponse
    {
        Gate::authorize('attendance.viewOwn');

        $tenant = app(CurrentTenant::class)->get();
        $today = TenantTime::today($tenant);
        $start = Carbon::parse((string) ($request->validated('period_start') ?? $today->startOfMonth()->format('Y-m-d')));
        $end = Carbon::parse((string) ($request->validated('period_end') ?? $today->format('Y-m-d')));

        $employeeId = $request->user()->employee_id;
        $employee = $employeeId === null ? null : Employee::find($employeeId);

        $exceptions = $employee === null ? [] : $reconciliation->forEmployee($tenant, $employee, $start, $end);

        return response()->json([
            'period' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
            'exceptions' => $exceptions,
        ]);
    }
}

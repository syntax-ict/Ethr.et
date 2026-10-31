<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Resources\AttendancePunchResource;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Attendance\EnteredAttendance;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollPeriodLock;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ManualAttendanceController extends Controller
{
    public function __construct(
        private readonly EnteredAttendance $entered,
    ) {}

    public function store(ManualAttendanceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $employee = Employee::where('public_id', $request->validated('employee_public_id'))->firstOrFail();
        // attendance.manage can be given to a custom role scoped to one branch
        // or department; it reaches only the people that scope reaches.
        abort_unless($request->user()->canAccessEmployee($employee), 404);
        // Entering your own hours is approving them, which corrections,
        // conflicts and integrity flags already refuse.
        abort_if($employee->id === $request->user()->employee_id, 403, __('attendance.manual_entry_self'));

        $zone = TenantTime::zone(app(CurrentTenant::class)->get());
        $date = $request->validated('date');
        app(PayrollPeriodLock::class)->assertOpen($employee->id, $date);
        $checkIn = TenantTime::wallClockToUtc($date, $request->validated('check_in'), $zone);
        // A check-out earlier on the clock than the check-in is the next
        // morning: a night shift crossing midnight (ManualAttendanceRequest).
        // The record stays dated the night it began, as a live punch is.
        $checkOut = null;
        if ($request->validated('check_out')) {
            $outDate = $request->validated('check_out') < $request->validated('check_in')
                ? Carbon::parse($date)->addDay()->format('Y-m-d')
                : $date;
            $checkOut = TenantTime::wallClockToUtc($outDate, $request->validated('check_out'), $zone);
        }

        // The times are what HR read off a sheet or a clock in the tenant's
        // own timezone. Written as "{date} {H:i}:00" they were stored as UTC
        // wall-clock, so 09:00 in Addis Ababa came back as 12:00.
        $result = $this->entered->enter(
            $employee,
            $date,
            $checkIn,
            $checkOut,
            [
                'reason' => $request->validated('reason'),
                'entered_by' => $request->user()->id,
                'manual_date' => $request->validated('date'),
                'manual_check_in' => $request->validated('check_in'),
                'manual_check_out' => $request->validated('check_out'),
            ],
            $request->validated('idempotency_key'),
            $request->ip(),
        );

        if ($result->wasDuplicate) {
            $result->record->load('employee', 'shift');

            return (new AttendancePunchResource($result->record, true))->response()->setStatusCode(200);
        }

        AuditLog::record('attendance.manual_entry', $result->record, [
            'employee_public_id' => $employee->public_id,
            'reason' => $request->validated('reason'),
            'entered_by_user_id' => $request->user()->id,
        ]);

        $result->record->load('employee', 'shift');

        return (new AttendanceRecordResource($result->record))
            ->response()
            ->setStatusCode(201);
    }
}

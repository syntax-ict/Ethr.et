<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateAttendanceModeRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Attendance\AttendancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EmployeeAttendanceModeController extends Controller
{
    /**
     * Where this employee is expected to punch: at their branch, at any of
     * their sites (field), or from anywhere (remote). See AttendancePolicy.
     */
    public function __invoke(UpdateAttendanceModeRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $from = AttendancePolicy::modeOf($employee)->value;
        $mode = AttendanceMode::from($request->validated('attendance_mode'));

        $employee->forceFill(['attendance_mode' => $mode])->save();

        AuditLog::record('employee.attendance_mode_changed', $employee, ['from' => $from, 'to' => $mode->value]);

        return response()->json([
            'public_id' => $employee->public_id,
            'attendance_mode' => $mode->value,
        ]);
    }
}

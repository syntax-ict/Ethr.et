<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateKioskPinRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class EmployeeKioskPinController extends Controller
{
    /**
     * Set or remove the PIN an employee enters at a kiosk.
     *
     * Needed whenever the organisation turns on "Require PIN" for kiosk
     * check-in. `null` removes it. The PIN is stored hashed and never
     * returned; the answer says only whether one is set.
     */
    public function __invoke(UpdateKioskPinRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('attendance.manage');

        // Nothing in the API or the UI ever wrote `kiosk_pin`, so turning on
        // "Require PIN" refused every employee at every kiosk (audit N62).
        $pin = $request->validated('pin');
        $employee->forceFill(['kiosk_pin' => $pin === null ? null : Hash::make($pin)])->save();

        AuditLog::record($pin === null ? 'employee.kiosk_pin_removed' : 'employee.kiosk_pin_set', $employee);

        return response()->json([
            'public_id' => $employee->public_id,
            'has_kiosk_pin' => $pin !== null,
        ]);
    }
}

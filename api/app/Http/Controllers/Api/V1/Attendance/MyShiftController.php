<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\MyShiftService;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /attendance/my-shift — the signed-in employee's shift today, whether
 * they are checked in, and the punching rules and assistant switches that
 * apply to them. The phone's punch screen and its reminders read this.
 */
class MyShiftController extends Controller
{
    public function __invoke(Request $request, MyShiftService $service, CurrentTenant $currentTenant): JsonResponse
    {
        Gate::authorize('attendance.viewOwn');

        $user = $request->user();
        $employee = $user->employee;
        $tenant = $currentTenant->get();

        if ($employee === null || $tenant === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.no_employee_linked'),
            ], 404);
        }

        return response()->json($service->forEmployee($employee, $user, $tenant));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\PaidMonthsRequest;
use App\Models\Employee;
use App\Services\Payroll\PayDifferenceRecorder;
use Illuminate\Http\JsonResponse;

/**
 * Which already released payroll months a date reaches for one person: the
 * same set a change to pay dated there records a pay difference for. HR reads
 * it before saving; nothing is refused.
 */
class EmployeePaidMonthsController extends Controller
{
    public function __invoke(PaidMonthsRequest $request, Employee $employee, PayDifferenceRecorder $recorder): JsonResponse
    {
        $this->authorize('view', $employee);

        $entries = $recorder->releasedEntriesFrom((int) $employee->tenant_id, (int) $employee->id, (string) $request->validated('from'));

        $earliest = $entries->min('period_start');
        $latest = $entries->max('period_end');

        return response()->json([
            'data' => [
                'count' => $entries->count(),
                'earliest_period_start' => $earliest === null ? null : substr((string) $earliest, 0, 10),
                'latest_period_end' => $latest === null ? null : substr((string) $latest, 0, 10),
            ],
        ]);
    }
}

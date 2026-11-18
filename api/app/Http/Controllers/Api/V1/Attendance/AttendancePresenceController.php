<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ConfirmPresenceRequest;
use App\Http\Resources\AttendancePresenceCheckResource;
use App\Models\Employee;
use App\Services\Attendance\PresenceChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Answering a presence check: the phone says it is the employee's own and
 * where it is. The check is the caller's latest one, so no id travels.
 */
class AttendancePresenceController extends Controller
{
    public function __construct(private readonly PresenceChecks $checks) {}

    public function confirm(ConfirmPresenceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $request->user()->employee;
        if (! $employee instanceof Employee) {
            return $this->problem(404, 'Not Found', __('attendance.no_employee_linked'));
        }

        $check = $this->checks->answer($employee, $request, $request->validated(), Carbon::now('UTC'));
        if ($check === null) {
            return $this->problem(404, 'Not Found', __('attendance.presence_nothing_pending'));
        }

        return (new AttendancePresenceCheckResource($check))->response()->setStatusCode(200);
    }

    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/not-found',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\OfflineSyncRequest;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OfflineSyncController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function sync(OfflineSyncRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $results = [];

        foreach ($request->validated('records') as $record) {
            $employee = Employee::where('public_id', $record['employee_public_id'])->first();

            if (! $employee) {
                $results[] = [
                    'idempotency_key' => $record['idempotency_key'],
                    'status' => 'error',
                    'detail' => 'Employee not found',
                ];
                continue;
            }

            try {
                $result = $this->engine->record(new AttendanceInput(
                    employeeId: $employee->id,
                    tenantId: $employee->tenant_id,
                    source: AttendanceSource::OFFLINE_MOBILE,
                    type: $record['type'],
                    idempotencyKey: $record['idempotency_key'],
                    latitude: $record['latitude'] ?? null,
                    longitude: $record['longitude'] ?? null,
                    offlineToken: $record['offline_token'],
                    ipAddress: $request->ip(),
                ));

                $results[] = [
                    'idempotency_key' => $record['idempotency_key'],
                    'status' => $result->wasDuplicate ? 'duplicate' : 'created',
                    'public_id' => $result->record->public_id,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'idempotency_key' => $record['idempotency_key'],
                    'status' => 'error',
                    'detail' => $e->getMessage(),
                ];
            }
        }

        $created = collect($results)->where('status', 'created')->count();
        $duplicates = collect($results)->where('status', 'duplicate')->count();
        $errors = collect($results)->where('status', 'error')->count();

        return response()->json([
            'results' => $results,
            'summary' => [
                'created' => $created,
                'duplicate' => $duplicates,
                'error' => $errors,
                'total' => count($results),
            ],
        ]);
    }
}

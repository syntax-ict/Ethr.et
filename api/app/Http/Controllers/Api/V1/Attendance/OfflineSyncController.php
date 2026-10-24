<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\OfflineSyncRequest;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\OfflineGrant;
use App\Services\FileStorageService;
use App\Support\PhotoConsent;
use App\Support\PunchSignals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class OfflineSyncController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly OfflineGrant $grants,
        private readonly FileStorageService $storage,
    ) {}

    /**
     * GET /attendance/offline-grant - fetched while online, presented with
     * every punch made offline (see OfflineGrant).
     */
    public function grant(Request $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.no_employee_linked'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        return response()->json($this->grants->issue($employee));
    }

    public function sync(OfflineSyncRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        // Every role holds attendance.checkIn, and this route accepted any
        // employee in the tenant at any time the device claimed: a colleague's
        // punches, or your own backdated by months (audit N50, the gap N15
        // closed for the kiosk route). Each record must now be the caller's
        // own unless they may manage attendance, and its time must fall in
        // the window config/attendance.php allows. Checked per record, so one
        // bad record does not lose the rest of a batch that waited for signal.
        $user = $request->user();
        $ownEmployeePublicId = $user->employee?->public_id;
        $mayRecordForOthers = $user->hasPermission('attendance.manage');
        $oldest = now()->subDays((int) config('attendance.offline_max_age_days'));
        $latest = now()->addMinutes((int) config('attendance.offline_future_skew_minutes'));

        $results = [];

        foreach ($request->validated('records') as $record) {
            // Compared before any lookup, so a colleague's public_id is refused
            // the same way whether or not it exists.
            if (! $mayRecordForOthers && $record['employee_public_id'] !== $ownEmployeePublicId) {
                $results[] = [
                    'idempotency_key' => $record['idempotency_key'],
                    'status' => 'error',
                    'detail' => __('attendance.offline_not_own'),
                ];

                continue;
            }

            $capturedAt = Carbon::parse($record['timestamp']);
            if ($capturedAt->lt($oldest) || $capturedAt->gt($latest)) {
                $results[] = [
                    'idempotency_key' => $record['idempotency_key'],
                    'status' => 'error',
                    'detail' => __('attendance.offline_outside_window', [
                        'days' => (int) config('attendance.offline_max_age_days'),
                    ]),
                ];

                continue;
            }

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
                    // The selfie is kept only for an employee who has agreed to it
                    // (PhotoConsent). The punch is kept either way; one without
                    // its photo is flagged when the photo is required.
                    photoPath: isset($record['photo']) && $record['photo'] !== '' && PhotoConsent::given($employee->user)
                        ? $this->storage->uploadDataUrlImage($record['photo'], 'selfies')['path']
                        : null,
                    offlineToken: OfflineGrant::fingerprint($record['offline_token']),
                    ipAddress: $request->ip(),
                    metadata: array_filter([
                        ...PunchSignals::from($record, $request->userAgent(), online: false),
                        'accuracy' => $record['accuracy'] ?? null,
                        'offline_grant' => $this->grants->check($record['offline_token'], $employee, $capturedAt->copy()->utc()),
                    ], fn ($v) => $v !== null),
                    // The punch happened on the device while offline; record it at
                    // that time, not at sync time. The field is already required
                    // and validated by OfflineSyncRequest.
                    occurredAt: $record['timestamp'],
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

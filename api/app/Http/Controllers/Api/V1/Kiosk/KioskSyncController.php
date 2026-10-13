<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceSource;
use App\Exceptions\AttendanceRefused;
use App\Exceptions\NoOpenCheckIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskSyncRequest;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Kiosk\KioskOfflineSeal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /kiosk/sync - punches a kiosk made while it was offline (a power cut,
 * a dropped connection), sent when the connection returns.
 *
 * Each punch is opened with the kiosk's private key and held to the same
 * rules as a live one: the employee must exist, the PIN must match where the
 * organisation requires one (wrong PINs count toward the same lock), and the
 * time must fall within the offline window. The kiosk's clock is bounded by
 * its grant; a punch outside it is recorded and flagged for review rather
 * than trusted. Results are per punch, so one bad punch never loses the rest.
 */
class KioskSyncController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly KioskOfflineSeal $seal,
    ) {}

    public function __invoke(KioskSyncRequest $request): JsonResponse
    {
        $session = KioskSession::resolveActiveByToken((string) ($request->header('X-Kiosk-Token') ?? ''));

        if (! $session) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.invalid_token'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $settings = AttendanceSetting::first();
        $pinRequired = (bool) ($settings?->kiosk_pin_required);
        $oldest = Carbon::now('UTC')->subDays((int) config('attendance.offline_max_age_days'));
        $latest = Carbon::now('UTC')->addMinutes((int) config('attendance.offline_future_skew_minutes'));

        $results = [];
        foreach ($request->validated('records') as $record) {
            $results[] = [
                'idempotency_key' => $record['idempotency_key'],
                ...$this->syncOne($session, $record, $pinRequired, $oldest, $latest),
            ];
        }

        $session->touchActivity();

        return response()->json(['results' => $results]);
    }

    /**
     * @param  array{idempotency_key: string, sealed: string, grant?: string|null}  $record
     * @return array{status: string, detail?: string, public_id?: string}
     */
    private function syncOne(KioskSession $session, array $record, bool $pinRequired, Carbon $oldest, Carbon $latest): array
    {
        $punch = $this->seal->open($session, $record['sealed']);
        if ($punch === null) {
            return ['status' => 'error', 'detail' => __('kiosk.offline_unreadable')];
        }

        try {
            $capturedAt = Carbon::parse($punch['at'])->utc();
        } catch (Throwable) {
            return ['status' => 'error', 'detail' => __('kiosk.offline_unreadable')];
        }

        if ($capturedAt->lt($oldest) || $capturedAt->gt($latest)) {
            return ['status' => 'error', 'detail' => __('attendance.offline_outside_window', [
                'days' => (int) config('attendance.offline_max_age_days'),
            ])];
        }

        $employee = Employee::where('employee_code', $punch['c'])->first();
        if (! $employee) {
            return ['status' => 'error', 'detail' => __('attendance.employee_not_found')];
        }

        if ($pinRequired) {
            $lockKey = "kiosk-pin:{$session->id}:{$employee->id}";
            if (RateLimiter::tooManyAttempts($lockKey, KioskCheckInController::PIN_ATTEMPTS)) {
                return ['status' => 'error', 'detail' => __('kiosk.pin_locked')];
            }

            if ($punch['p'] === null || ! $employee->kiosk_pin || ! Hash::check($punch['p'], $employee->kiosk_pin)) {
                if (RateLimiter::hit($lockKey, KioskCheckInController::PIN_LOCK_SECONDS) >= KioskCheckInController::PIN_ATTEMPTS) {
                    AuditLog::record('kiosk.pin_locked', $employee, ['kiosk_session_id' => $session->id, 'offline' => true]);
                }

                return ['status' => 'error', 'detail' => __('kiosk.invalid_pin')];
            }

            RateLimiter::clear($lockKey);
        }

        try {
            $result = $this->engine->record(new AttendanceInput(
                employeeId: $employee->id,
                tenantId: $session->tenant_id,
                source: AttendanceSource::KIOSK,
                type: $punch['t'],
                idempotencyKey: $punch['k'],
                metadata: [
                    'kiosk_session_id' => $session->id,
                    'kiosk_branch_id' => $session->branch_id,
                    'kiosk_offline' => true,
                    'offline_grant' => $this->seal->checkGrant($record['grant'] ?? null, $session, $capturedAt),
                ],
                occurredAt: $capturedAt->toIso8601String(),
            ));
        } catch (AttendanceRefused|NoOpenCheckIn $e) {
            // The engine's refusals are written for the person punching.
            return ['status' => 'error', 'detail' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'detail' => __('kiosk.offline_failed')];
        }

        return [
            'status' => $result->wasDuplicate ? 'duplicate' : 'created',
            'public_id' => $result->record->public_id,
        ];
    }
}

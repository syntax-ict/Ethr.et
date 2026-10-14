<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskCheckInRequest;
use App\Http\Resources\AttendancePunchResource;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class KioskCheckInController extends Controller
{
    public const PIN_ATTEMPTS = 5;

    public const PIN_LOCK_SECONDS = 900;

    public function __construct(
        private readonly AttendanceEngine $engine,
    ) {}

    public function __invoke(KioskCheckInRequest $request): JsonResponse
    {
        $kioskToken = $request->header('X-Kiosk-Token') ?? $request->query('kiosk_token');

        if (! $kioskToken) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.token_required'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $session = KioskSession::resolveActiveByToken((string) $kioskToken);

        if (! $session) {
            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => __('kiosk.invalid_token'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        // Plain tenant-scoped queries from here on. These two lookups used to
        // drop the scope and restate `tenant_id` from the session; the resolver
        // has now made the session's tenant current, so the scope says it.
        $employee = Employee::where('employee_code', $request->validated('employee_code'))->first();

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.employee_not_found'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $settings = AttendanceSetting::first();

        if ($settings?->kiosk_pin_required) {
            $pin = $request->validated('pin');
            if (! $pin || ! $employee->kiosk_pin) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/validation',
                    'title' => 'PIN Required',
                    'status' => 422,
                    'detail' => __('kiosk.pin_required'),
                ], 422)->header('Content-Type', 'application/problem+json');
            }

            // Five wrong PINs for one person at one kiosk lock that person
            // there for 15 minutes. The route limiter counts per IP, and a
            // kiosk is one IP for everyone in the queue, so it could neither
            // stop a colleague guessing four digits nor avoid locking out
            // the whole queue.
            $lockKey = "kiosk-pin:{$session->id}:{$employee->id}";
            if (RateLimiter::tooManyAttempts($lockKey, self::PIN_ATTEMPTS)) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/too-many-attempts',
                    'title' => 'PIN Locked',
                    'status' => 429,
                    'detail' => __('kiosk.pin_locked'),
                ], 429, ['Content-Type' => 'application/problem+json', 'Retry-After' => RateLimiter::availableIn($lockKey)]);
            }

            if (! Hash::check($pin, $employee->kiosk_pin)) {
                if (RateLimiter::hit($lockKey, self::PIN_LOCK_SECONDS) >= self::PIN_ATTEMPTS) {
                    AuditLog::record('kiosk.pin_locked', $employee, ['kiosk_session_id' => $session->id]);
                }

                return response()->json([
                    'type' => 'https://ethr.et/errors/unauthorized',
                    'title' => 'Invalid PIN',
                    'status' => 401,
                    'detail' => __('kiosk.invalid_pin'),
                ], 401)->header('Content-Type', 'application/problem+json');
            }

            RateLimiter::clear($lockKey);
        }

        if ($settings && ! $settings->isMethodEnabled('kiosk')) {
            return response()->json([
                'type' => 'https://ethr.et/errors/forbidden',
                'title' => 'Method Disabled',
                'status' => 403,
                'detail' => __('attendance.method_disabled'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        $result = $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $session->tenant_id,
            source: AttendanceSource::KIOSK,
            type: $request->validated('type'),
            idempotencyKey: $request->validated('idempotency_key'),
            ipAddress: $request->ip(),
            metadata: [
                'kiosk_session_id' => $session->id,
                'kiosk_branch_id' => $session->branch_id,
            ],
        ));

        $session->touchActivity();

        $result->record->load('employee', 'shift');

        $punch = new AttendancePunchResource($result->record, $result->wasDuplicate, $employee->name);

        if ($result->wasDuplicate || $request->validated('type') === 'check_out') {
            return $punch->response()->setStatusCode(200);
        }

        return $punch->response()->setStatusCode(201);
    }
}

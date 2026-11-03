<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceSource;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskCheckInRequest;
use App\Http\Resources\KioskPunchResource;
use App\Models\KioskSession;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\AttendancePolicy;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Services\Kiosk\KioskEmployeeCode;
use App\Services\Kiosk\KioskPinGuard;
use App\Support\FeatureUnavailable;
use Illuminate\Http\JsonResponse;

class KioskCheckInController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly KioskPinGuard $pins,
        private readonly KioskEmployeeCode $codes,
    ) {}

    public function __invoke(KioskCheckInRequest $request): JsonResponse
    {
        // The header only: a token in the address reaches access logs.
        $kioskToken = $request->header('X-Kiosk-Token');

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

        // A new punch at the kiosk is refused while kiosk check-in is switched
        // off. Sync stays open: what a kiosk captured offline before the switch
        // is a record, and is never discarded (design §6).
        $tenant = app(CurrentTenant::class)->get();
        $decision = $tenant === null ? null : app(FeatureGate::class)->decide($tenant, Feature::KioskCheckIn);
        if ($decision !== null && ! $decision->on) {
            return FeatureUnavailable::response(Feature::KioskCheckIn, $decision->reason);
        }

        // Plain tenant-scoped queries from here on. These two lookups used to
        // drop the scope and restate `tenant_id` from the session; the resolver
        // has now made the session's tenant current, so the scope says it.
        // A bare number stands for the full code (KioskEmployeeCode).
        $match = $this->codes->find($tenant, (string) $request->validated('employee_code'));

        if ($match->ambiguous) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Ambiguous code',
                'status' => 422,
                'detail' => __('kiosk.code_ambiguous'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $employee = $match->employee;

        if (! $employee) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('attendance.employee_not_found'),
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        // The kiosk's branch may require a PIN when the organisation does not,
        // or the other way round (AttendancePolicy).
        $settings = app(AttendancePolicy::class)->forBranch($session->branch_id);

        if ($settings->kiosk_pin_required) {
            $check = $this->pins->check($session, $employee, $request->validated('pin'));

            if ($check === KioskPinGuard::MISSING) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/validation',
                    'title' => 'PIN Required',
                    'status' => 422,
                    'detail' => __('kiosk.pin_required'),
                ], 422)->header('Content-Type', 'application/problem+json');
            }

            if ($check === KioskPinGuard::LOCKED) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/too-many-attempts',
                    'title' => 'PIN Locked',
                    'status' => 429,
                    'detail' => __('kiosk.pin_locked'),
                ], 429, ['Content-Type' => 'application/problem+json', 'Retry-After' => $this->pins->availableIn($session, $employee)]);
            }

            if ($check === KioskPinGuard::WRONG) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/unauthorized',
                    'title' => 'Invalid PIN',
                    'status' => 401,
                    'detail' => __('kiosk.invalid_pin'),
                ], 401)->header('Content-Type', 'application/problem+json');
            }
        }

        if (! $settings->isMethodEnabled('kiosk')) {
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

        // Never the employee resource: this answer goes to a shared terminal
        // that anyone can type a code into, and until 2026-11-01 it carried
        // the person's email, phone, date of birth and nationality. The
        // terminal needs to show who punched, and nothing more.
        $punch = new KioskPunchResource($result->record, $employee, $result->wasDuplicate, $result->punch);

        if ($result->wasDuplicate || $result->punch === 'check_out') {
            return $punch->response()->setStatusCode(200);
        }

        return $punch->response()->setStatusCode(201);
    }
}

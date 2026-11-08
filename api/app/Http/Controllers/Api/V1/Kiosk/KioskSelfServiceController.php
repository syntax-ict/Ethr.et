<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\ChangeKioskPinRequest;
use App\Http\Requests\Kiosk\StartKioskSelfServiceRequest;
use App\Http\Resources\KioskAttendanceDayResource;
use App\Http\Resources\KioskLeaveBalanceResource;
use App\Http\Resources\KioskPersonResource;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\KioskSession;
use App\Models\LeaveBalance;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Services\Kiosk\KioskEmployeeCode;
use App\Services\Kiosk\KioskPass;
use App\Services\Kiosk\KioskPinGuard;
use App\Services\Leave\LeaveYear;
use App\Support\FeatureUnavailable;
use App\Support\TenantTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service at a shared kiosk, for people with no phone or email: their own
 * attendance for the last week, their leave balances, and a new kiosk PIN.
 *
 * The code alone is never enough, because codes are short and often printed
 * on a badge: every call needs the person's own PIN, whether or not the
 * organisation asks for one to punch. Someone with no PIN set cannot use it,
 * so an organisation that never hands out PINs never exposes anything. PIN
 * guesses share the punch's counter (KioskPinGuard).
 *
 * The PIN buys a short pass bound to this kiosk (KioskPass). Nothing about pay
 * is shown: this is a screen in a lobby.
 */
class KioskSelfServiceController extends Controller
{
    public const RECENT_DAYS = 7;

    public function __construct(
        private readonly KioskPinGuard $pins,
        private readonly KioskPass $passes,
        private readonly KioskEmployeeCode $codes,
    ) {}

    /**
     * Start self-service with an employee code and that person's kiosk PIN.
     */
    public function start(StartKioskSelfServiceRequest $request): JsonResponse
    {
        $session = $this->kiosk($request);
        if ($session === null) {
            return $this->problem(401, 'Unauthorized', __('kiosk.invalid_token'));
        }

        $tenant = app(CurrentTenant::class)->get();
        $decision = $tenant === null ? null : app(FeatureGate::class)->decide($tenant, Feature::KioskCheckIn);
        if ($decision !== null && ! $decision->on) {
            return FeatureUnavailable::response(Feature::KioskCheckIn, $decision->reason);
        }

        // A bare number stands for the full code; one that could mean two
        // people is refused like an unknown one.
        $employee = $this->codes->find($tenant, (string) $request->validated('employee_code'))->employee;

        // One answer for an unknown code and a wrong PIN, so the screen does
        // not confirm which codes exist.
        if ($employee === null || in_array($employee->status, EmployeeStatus::leaving(), true)) {
            return $this->problem(401, 'Invalid PIN', __('kiosk.self_service_refused'));
        }

        $check = $this->pins->check($session, $employee, $request->validated('pin'), ['self_service' => true]);

        if ($check === KioskPinGuard::LOCKED) {
            return $this->problem(429, 'PIN Locked', __('kiosk.pin_locked'), ['Retry-After' => $this->pins->availableIn($session, $employee)]);
        }

        if ($check === KioskPinGuard::MISSING && ! $employee->kiosk_pin) {
            return $this->problem(422, 'PIN Not Set', __('kiosk.self_service_no_pin'));
        }

        if ($check !== KioskPinGuard::OK) {
            return $this->problem(401, 'Invalid PIN', __('kiosk.self_service_refused'));
        }

        $session->touchActivity();
        AuditLog::record('kiosk.self_service_started', $employee, ['kiosk_session_id' => $session->id]);

        $employee->load('position');
        $pass = $this->passes->issue($session, $employee);

        return response()->json([
            'pass' => $pass['pass'],
            'expires_at' => $pass['expires_at'],
            'employee' => new KioskPersonResource($employee),
        ]);
    }

    /**
     * The pass holder's own attendance for the last seven days, and their
     * leave balances this year.
     */
    public function show(Request $request): JsonResponse
    {
        $session = $this->kiosk($request);
        if ($session === null) {
            return $this->problem(401, 'Unauthorized', __('kiosk.invalid_token'));
        }

        $employee = $this->passes->resolve($session, $request->header('X-Kiosk-Pass'));
        if ($employee === null) {
            return $this->problem(401, 'Unauthorized', __('kiosk.self_service_expired'));
        }

        $session->touchActivity();
        $employee->load('position');

        $zone = TenantTime::zone(app(CurrentTenant::class)->get());
        $today = Carbon::now($zone);

        $days = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->where('date', '>=', $today->copy()->subDays(self::RECENT_DAYS - 1)->format('Y-m-d'))
            ->where('date', '<=', $today->format('Y-m-d'))
            ->where('status', '!=', AttendanceStatus::VOIDED->value)
            ->orderByDesc('date')
            ->orderByDesc('check_in')
            ->get();

        // The organisation's own leave year: on the Ethiopian one, the
        // Gregorian year showed nothing from Meskerem on.
        $balances = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('year', LeaveYear::forTenantId($employee->tenant_id)->current())
            ->with('leaveType', 'employee')
            ->get();

        return response()->json([
            'employee' => new KioskPersonResource($employee),
            'today' => $today->format('Y-m-d'),
            'days' => KioskAttendanceDayResource::collection($days),
            'leave_balances' => KioskLeaveBalanceResource::collection($balances),
        ]);
    }

    /**
     * End self-service now, rather than when the pass runs out.
     */
    public function end(Request $request): JsonResponse
    {
        $this->passes->revoke($request->header('X-Kiosk-Pass'));

        return response()->json(null, 204);
    }

    /**
     * Change the pass holder's own kiosk PIN. The current PIN is asked again:
     * a pass left open on the screen must not let the next person in the
     * queue choose someone else's PIN.
     */
    public function changePin(ChangeKioskPinRequest $request): JsonResponse
    {
        $session = $this->kiosk($request);
        if ($session === null) {
            return $this->problem(401, 'Unauthorized', __('kiosk.invalid_token'));
        }

        $employee = $this->passes->resolve($session, $request->header('X-Kiosk-Pass'));
        if ($employee === null) {
            return $this->problem(401, 'Unauthorized', __('kiosk.self_service_expired'));
        }

        $check = $this->pins->check($session, $employee, $request->validated('current_pin'), ['self_service' => true]);

        if ($check === KioskPinGuard::LOCKED) {
            $this->passes->revoke($request->header('X-Kiosk-Pass'));

            return $this->problem(429, 'PIN Locked', __('kiosk.pin_locked'), ['Retry-After' => $this->pins->availableIn($session, $employee)]);
        }

        if ($check !== KioskPinGuard::OK) {
            return $this->problem(422, 'Invalid PIN', __('kiosk.invalid_pin'));
        }

        $employee->forceFill(['kiosk_pin' => Hash::make($request->validated('pin'))])->save();
        AuditLog::record('employee.kiosk_pin_changed_at_kiosk', $employee, ['kiosk_session_id' => $session->id]);

        return response()->json(null, 204);
    }

    private function kiosk(Request $request): ?KioskSession
    {
        // The header only: a token in the address reaches access logs.
        $token = $request->header('X-Kiosk-Token');

        return $token ? KioskSession::resolveActiveByToken((string) $token) : null;
    }

    /** @param array<string, int|string> $headers */
    private function problem(int $status, string $title, string $detail, array $headers = []): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/'.match ($status) {
                401 => 'unauthorized',
                422 => 'validation',
                429 => 'too-many-attempts',
                default => 'error',
            },
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', ...$headers]);
    }
}

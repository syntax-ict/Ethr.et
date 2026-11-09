<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceSource;
use App\Enums\Feature;
use App\Exceptions\AttendanceRefused;
use App\Exceptions\NoOpenCheckIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskSyncRequest;
use App\Models\KioskSession;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\AttendancePolicy;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Services\Kiosk\KioskEmployeeCode;
use App\Services\Kiosk\KioskOfflineSeal;
use App\Services\Kiosk\KioskPinGuard;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
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
 *
 * Kiosk check-in switched off does not discard a punch captured while it was
 * on (docs/FEATURE-CONTROL-CENTER-DESIGN.md §6), and it no longer admits a
 * punch with nothing to show for that: while it is off, a punch is accepted
 * only with a grant this kiosk was issued. Start issues no grant while the
 * feature is off, so a grant is evidence the terminal was in service while
 * it was on. A punch whose time falls outside its grant is still recorded and
 * flagged, as it is with the feature on.
 * Before 2026-11-09 sync skipped the switch entirely, so a token alone, with
 * the key start hands out, recorded punches for an organisation that had
 * switched kiosks off.
 */
class KioskSyncController extends Controller
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly KioskOfflineSeal $seal,
        private readonly KioskPinGuard $pins,
        private readonly KioskEmployeeCode $codes,
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

        // The same refusal as a live punch at this kiosk's branch, for the
        // whole batch: nothing is recorded by a method the branch has off.
        $settings = app(AttendancePolicy::class)->forBranch($session->branch_id);
        if (! $settings->isMethodEnabled('kiosk')) {
            return response()->json([
                'type' => 'https://ethr.et/errors/forbidden',
                'title' => 'Method Disabled',
                'status' => 403,
                'detail' => __('attendance.method_disabled'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        $tenant = app(CurrentTenant::class)->get();
        $featureOn = $tenant === null || app(FeatureGate::class)->decide($tenant, Feature::KioskCheckIn)->on;

        $pinRequired = (bool) $settings->kiosk_pin_required;
        $oldest = Carbon::now('UTC')->subDays((int) config('attendance.offline_max_age_days'));
        $latest = Carbon::now('UTC')->addMinutes((int) config('attendance.offline_future_skew_minutes'));

        $results = [];
        foreach ($request->validated('records') as $record) {
            $results[] = [
                'idempotency_key' => $record['idempotency_key'],
                ...$this->syncOne($session, $record, $pinRequired, $featureOn, $oldest, $latest),
            ];
        }

        $session->touchActivity();

        return response()->json(['results' => $results]);
    }

    /**
     * @param  array{idempotency_key: string, sealed: string, grant?: string|null}  $record
     * @return array{status: string, detail?: string, public_id?: string}
     */
    private function syncOne(KioskSession $session, array $record, bool $pinRequired, bool $featureOn, Carbon $oldest, Carbon $latest): array
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

        $grant = $this->seal->checkGrant($record['grant'] ?? null, $session, $capturedAt);
        if (! $featureOn && ($grant === 'missing' || $grant === 'invalid')) {
            return ['status' => 'error', 'detail' => __('kiosk.offline_switched_off')];
        }

        $match = $this->codes->find(app(CurrentTenant::class)->get(), (string) $punch['c']);
        if ($match->ambiguous) {
            return ['status' => 'error', 'detail' => __('kiosk.code_ambiguous')];
        }
        $employee = $match->employee;
        if (! $employee) {
            return ['status' => 'error', 'detail' => __('attendance.employee_not_found')];
        }

        if ($pinRequired) {
            $check = $this->pins->check($session, $employee, $punch['p'], ['offline' => true]);
            if ($check === KioskPinGuard::LOCKED) {
                return ['status' => 'error', 'detail' => __('kiosk.pin_locked')];
            }
            if ($check !== KioskPinGuard::OK) {
                return ['status' => 'error', 'detail' => __('kiosk.invalid_pin')];
            }
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
                    'offline_grant' => $grant,
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

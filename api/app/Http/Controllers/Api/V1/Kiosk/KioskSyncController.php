<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Enums\AttendanceSource;
use App\Enums\Feature;
use App\Exceptions\AttendanceRefused;
use App\Exceptions\NoOpenCheckIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\KioskSyncRequest;
use App\Models\AuditLog;
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
     * Every error result says whether it is final. A final refusal can never
     * be accepted, so the terminal may drop the punch; it leaves one audit
     * entry first, because the person punched and nothing else would show it.
     * One that is not final is a passing fault and the terminal keeps the
     * punch.
     *
     * @param  array{idempotency_key: string, sealed: string, grant?: string|null}  $record
     * @return array{status: string, detail?: string, public_id?: string, final?: bool}
     */
    private function syncOne(KioskSession $session, array $record, bool $pinRequired, bool $featureOn, Carbon $oldest, Carbon $latest): array
    {
        $punch = $this->seal->open($session, $record['sealed']);
        if ($punch === null) {
            return $this->refused($session, $record, 'unreadable', __('kiosk.offline_unreadable'));
        }

        try {
            $capturedAt = Carbon::parse($punch['at'])->utc();
        } catch (Throwable) {
            return $this->refused($session, $record, 'unreadable', __('kiosk.offline_unreadable'), $punch);
        }

        if ($capturedAt->lt($oldest) || $capturedAt->gt($latest)) {
            return $this->refused($session, $record, 'outside_window', __('attendance.offline_outside_window', [
                'days' => (int) config('attendance.offline_max_age_days'),
            ]), $punch, $capturedAt);
        }

        $grant = $this->seal->checkGrant($record['grant'] ?? null, $session, $capturedAt);
        if (! $featureOn && ($grant === 'missing' || $grant === 'invalid')) {
            return $this->refused($session, $record, 'switched_off', __('kiosk.offline_switched_off'), $punch, $capturedAt);
        }

        $match = $this->codes->find(app(CurrentTenant::class)->get(), (string) $punch['c']);
        if ($match->ambiguous) {
            return $this->refused($session, $record, 'code_ambiguous', __('kiosk.code_ambiguous'), $punch, $capturedAt);
        }
        $employee = $match->employee;
        if (! $employee) {
            return $this->refused($session, $record, 'employee_not_found', __('attendance.employee_not_found'), $punch, $capturedAt);
        }

        if ($pinRequired) {
            $check = $this->pins->check($session, $employee, $punch['p'], ['offline' => true]);
            // A check made while locked is neither an attempt nor an extension
            // of the lock (KioskPinGuard), so this refusal is not final: the
            // punch is sent again once the lock has passed.
            if ($check === KioskPinGuard::LOCKED) {
                return ['status' => 'error', 'detail' => __('kiosk.pin_locked'), 'final' => false];
            }
            if ($check !== KioskPinGuard::OK) {
                $reason = $check === KioskPinGuard::MISSING ? 'pin_not_set' : 'invalid_pin';

                return $this->refused($session, $record, $reason, __('kiosk.invalid_pin'), $punch, $capturedAt);
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
            // The engine's refusals are written for the person punching. Only a
            // permanent one ends the punch: a check-out whose check-in has not
            // landed yet, or a setting changed for an hour, passes on a later try.
            if (! $e->permanent) {
                return ['status' => 'error', 'detail' => $e->getMessage(), 'final' => false];
            }

            return $this->refused($session, $record, 'refused', $e->getMessage(), $punch, $capturedAt);
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'detail' => __('kiosk.offline_failed'), 'final' => false];
        }

        return [
            'status' => $result->wasDuplicate ? 'duplicate' : 'created',
            'public_id' => $result->record->public_id,
        ];
    }

    /**
     * A final refusal and its audit entry. $punch is null while the punch
     * could not be opened. Only the code is read from it: the PIN never
     * reaches the entry.
     *
     * @param  array{idempotency_key: string, sealed: string, grant?: string|null}  $record
     * @param  array<string, mixed>|null  $punch
     * @return array{status: string, detail: string, final: bool}
     */
    private function refused(KioskSession $session, array $record, string $reason, string $detail, ?array $punch = null, ?Carbon $capturedAt = null): array
    {
        AuditLog::record('kiosk.offline_punch_refused', $session, array_filter([
            'reason' => $reason,
            'employee_code' => isset($punch['c']) ? (string) $punch['c'] : null,
            'captured_at' => $capturedAt?->toIso8601String(),
            'idempotency_key' => $record['idempotency_key'],
        ], fn ($v) => $v !== null));

        return ['status' => 'error', 'detail' => $detail, 'final' => true];
    }
}

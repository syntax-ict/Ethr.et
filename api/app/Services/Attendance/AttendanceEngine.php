<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Events\AttendanceRecorded;
use App\Exceptions\AttendanceRefused;
use App\Exceptions\DeviceBindingRefused;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Attendance\Device\AttendanceDeviceGate;
use App\Services\Attendance\Device\DeviceVerdict;
use App\Support\AttendanceRules;
use App\Support\AttendanceWebhookPayload;
use App\Support\TenantTime;
use App\Traits\DispatchesWebhooks;
use Carbon\Carbon;

final class AttendanceEngine
{
    use DispatchesWebhooks;

    public function __construct(
        private readonly ConfidenceScorer $scorer,
        private readonly ShiftMatcher $shiftMatcher,
        private readonly ConflictResolver $conflictResolver,
        private readonly IntegrityInspector $inspector,
        private readonly AttendancePolicy $policy,
        private readonly AttendancePlaces $places,
        private readonly AttendanceRiskScorer $risk,
    ) {}

    public function record(AttendanceInput $input): AttendanceResult
    {
        if ($input->idempotencyKey) {
            $existing = AttendanceRecord::query()
                ->where('tenant_id', $input->tenantId)
                ->where('idempotency_key', $input->idempotencyKey)
                ->first();

            // A check-out's key is kept on the record it closed (see
            // processCheckOut), so a phone retrying after a dropped response
            // gets that record back. Before, the replay looked for an open
            // check-in, found none, and showed the person an error for a
            // punch that had succeeded (attendance QA A6).
            if ($existing === null && in_array($input->type, ['check_out', 'auto'], true)) {
                $existing = AttendanceRecord::query()
                    ->where('tenant_id', $input->tenantId)
                    ->where('employee_id', $input->employeeId)
                    ->where('metadata->checkout_idempotency_key', $input->idempotencyKey)
                    ->first();
            }

            if ($existing) {
                return new AttendanceResult($existing, wasDuplicate: true);
            }
        }

        $organisation = AttendanceSetting::withoutGlobalScope('tenant')
            ->where('tenant_id', $input->tenantId)
            ->first();

        $employee = Employee::findOrFail($input->employeeId);

        // The rules for this person: the organisation's, their branch's
        // overrides, their attendance mode (AttendancePolicy).
        $settings = $this->policy->for($employee, $organisation ?? new AttendanceSetting(AttendanceSetting::defaults()));

        if (! $settings->isMethodEnabled($input->source->value)) {
            throw new AttendanceRefused(__('attendance.method_disabled'));
        }

        $input = $this->enforceDevice($input, $settings);

        $zone = TenantTime::zone(Tenant::query()->find($input->tenantId));

        // Someone who has left records nothing dated after they left. Nothing
        // checked this, so a terminated employee's code — on a badge they may
        // still hold — kept punching into attendance, overtime and payroll.
        // A punch from their last working day that reaches the server late
        // (a device backlog) is still dated on or before termination_date
        // and still counts.
        if (in_array($employee->status, [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED], true)) {
            $day = $this->resolveMoment($input)->setTimezone($zone)->format('Y-m-d');
            if ($employee->termination_date === null || $day > $employee->termination_date->format('Y-m-d')) {
                throw new AttendanceRefused(__('attendance.employee_has_left'));
            }
        }

        if ($input->type === 'auto') {
            return $this->processAuto($input, $employee, $zone, $settings);
        }

        if ($input->type === 'check_out') {
            return $this->processCheckOut($input, $employee, $zone, $settings);
        }

        return $this->processCheckIn($input, $employee, $zone, $settings);
    }

    /**
     * Whether a self-service punch came from the employee's registered phone.
     * Under `enforce` anything else is refused, including a punch that reached
     * here with no verdict at all: an endpoint that forgot to ask the gate
     * fails closed. Under `flag` it is kept, and the verdict goes on the punch
     * for the risk score. Kiosk, biometric, manual and import punches never
     * carry a phone and are never checked.
     */
    private function enforceDevice(AttendanceInput $input, AttendanceSetting $settings): AttendanceInput
    {
        $binding = (string) ($settings->device_binding ?? 'off');
        if (! in_array($input->source, AttendanceDeviceGate::SELF_SERVICE, true) || ! in_array($binding, ['flag', 'enforce'], true)) {
            return $input;
        }

        $verdict = $input->device ?? new DeviceVerdict(DeviceVerdict::INVALID, $binding);
        if ($binding === 'enforce' && ! $verdict->allowed()) {
            throw DeviceBindingRefused::from($verdict);
        }

        return $input->withMetadata($verdict->metadata());
    }

    /**
     * A punch from a device that does not say whether it is arriving or
     * leaving (a Hikvision terminal in access mode, a ZKTeco without state
     * keys, a middleware that sends no direction). It closes the open record
     * a check-out would close; with none open it is a check-in. Before this,
     * every such punch was a check-in, so an evening scan opened a second
     * record and a conflict instead of ending the day.
     */
    private function processAuto(AttendanceInput $input, Employee $employee, string $zone, ?AttendanceSetting $settings): AttendanceResult
    {
        $now = $this->resolveMoment($input);
        $open = $this->findOpenRecord($input, $now, $zone);

        if ($open === null) {
            return $this->processCheckIn($input, $employee, $zone, $settings);
        }

        if ($open->check_in->diffInMinutes($now, absolute: true) < AttendanceRules::MERGE_WINDOW_MINUTES) {
            return new AttendanceResult($open, wasDuplicate: true);
        }

        return $this->processCheckOut($input, $employee, $zone, $settings);
    }

    /**
     * The record a check-out at `$now` closes: today's open one, or
     * yesterday's when its shift crosses midnight or it began less than
     * MAX_OPEN_HOURS ago. Shared by check-out and `auto`, so both close the
     * same record.
     */
    private function findOpenRecord(AttendanceInput $input, Carbon $now, string $zone): ?AttendanceRecord
    {
        $local = $now->copy()->setTimezone($zone);
        $today = $local->format('Y-m-d');

        // Today's open record, or yesterday's when its shift crosses midnight:
        // a 22:00-06:00 record is dated the night it began, so a lookup on
        // today's date alone could never close it — the morning check-out
        // answered 500 and device webhooks dropped the punch.
        $candidates = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $input->tenantId)
            ->where('employee_id', $input->employeeId)
            ->whereDate('date', '>=', $local->copy()->subDay()->format('Y-m-d'))
            ->whereDate('date', '<=', $today)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            // A voided duplicate keeps its check-in; closing it would put the
            // check-out on a record nothing counts.
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->with('shift')
            ->latest('check_in')
            ->get();

        // Yesterday's record is also closable when it began less than
        // MAX_OPEN_HOURS ago: an evening shift whose overtime runs past the
        // tenant's midnight belongs to the day it began. The UTC clock hid
        // this until 03:00 Addis by accident; dating by the tenant's day
        // (processCheckIn) exposed it. A check-in left open yesterday morning
        // stays out of reach — it is a missing punch, not this check-out.
        return $candidates->first(fn (AttendanceRecord $r) => $r->date->format('Y-m-d') === $today)
            ?? $candidates->first(fn (AttendanceRecord $r) => $r->shift?->crosses_midnight === true
                || $r->check_in->diffInMinutes($now, absolute: true) < AttendanceRules::MAX_OPEN_HOURS * 60);
    }

    /**
     * The moment the punch happened, in UTC: the supplied event time for
     * delayed ingestion, otherwise now. Everything downstream (shift match,
     * status, date, stored timestamp) keys off this so a backlog is dated
     * correctly.
     *
     * UTC because Eloquent writes a Carbon by formatting it in its own zone
     * without converting (see TenantTime): a zoned instant would be stored as
     * its wall clock.
     */
    private function resolveMoment(AttendanceInput $input): Carbon
    {
        return $input->occurredAt !== null
            ? Carbon::parse($input->occurredAt)->utc()
            : Carbon::now('UTC');
    }

    /**
     * Shift times are wall-clock times in the tenant's zone, so the punch is
     * read in that zone for everything that compares with them: which day it
     * belongs to, which shift governs it, and whether it is late.
     *
     * Until 2026-10-12 all of it ran on the UTC clock. In Addis Ababa that put
     * every comparison three hours off: a punch 59 minutes after a 17:10 start
     * was stored `present`, lateness showed only after ~3 hours, and a punch
     * between 00:00 and 03:00 was dated the previous day (attendance QA A1/H2,
     * docs/audit/QA-ATTENDANCE-PRODUCTION-2026-10-12.md).
     */
    private function processCheckIn(AttendanceInput $input, Employee $employee, string $zone, ?AttendanceSetting $settings = null): AttendanceResult
    {
        $now = $this->resolveMoment($input);
        $local = $now->copy()->setTimezone($zone);
        ['shift' => $shift, 'date' => $workDate] = $this->shiftMatcher->forPunch($employee, $local);

        // Inside the branch or any of the person's work sites, giving a fix
        // the benefit of its reported accuracy (AttendancePlaces). Null when
        // there is no place to be inside.
        $geofenceVerified = null;
        if ($input->latitude !== null && $input->longitude !== null) {
            $accuracy = $input->metadata['accuracy'] ?? null;
            $geofenceVerified = $this->places->check(
                $this->places->for($employee),
                $input->latitude,
                $input->longitude,
                is_numeric($accuracy) ? (float) $accuracy : null,
            )['inside'];
        }

        // Refused only when the place is known and the punch is outside it.
        // A person with no placed branch or site cannot be checked
        // (AttendancePlaces answers null): until 2026-10-24 that refused
        // every phone punch at the branch the moment the switch was turned
        // on. It is recorded and flagged `geofence_unconfigured` instead
        // (IntegrityInspector).
        if ($settings?->geofence_required && $input->source->value === 'mobile') {
            if ($input->latitude === null || $input->longitude === null) {
                throw new AttendanceRefused(__('attendance.geofence_location_required'));
            }
            if ($geofenceVerified === false) {
                throw new AttendanceRefused(__('attendance.outside_geofence'));
            }
        }

        $confidence = $this->scorer->calculate($input, $geofenceVerified);

        $status = AttendanceStatus::PENDING;
        if ($shift) {
            $statusStr = $this->shiftMatcher->calculateStatus($local, $shift, $workDate);
            $status = AttendanceStatus::from($statusStr);
        }

        $record = AttendanceRecord::create([
            'tenant_id' => $input->tenantId,
            'employee_id' => $input->employeeId,
            'shift_id' => $shift?->id,
            'date' => $workDate,
            'check_in' => $now,
            'source' => $input->source,
            'confidence_score' => $confidence,
            'latitude' => $input->latitude,
            'longitude' => $input->longitude,
            'geofence_verified' => $geofenceVerified,
            'photo_path' => $input->photoPath,
            'device_id' => $input->deviceId,
            'status' => $status,
            'offline_token' => $input->offlineToken,
            'idempotency_key' => $input->idempotencyKey,
            'metadata' => array_filter([
                'ip_address' => $input->ipAddress,
                ...$input->metadata,
            ]),
        ]);

        // A manual entry is audited once, by ManualAttendanceController, after
        // it has written the entered times and with who entered it and why.
        // The engine wrote a second, bare row as well: on production five
        // entries left ten (attendance QA A9).

        $resolution = $this->conflictResolver->resolve($record);
        $record = $resolution->record;

        if ($resolution->action !== 'created') {
            AuditLog::record('attendance.conflict_'.$resolution->action, $record, [
                'source' => $input->source->value,
            ]);
        }

        // A merge can move check_in earlier (the survivor keeps the earliest
        // of the two), and the status was rated on the punch before it. A
        // backdated offline punch merged into a late biometric one stayed
        // `late` - or the other way round - until 2026-10-13.
        if (in_array($resolution->action, ['merged', 'deduped'], true)) {
            $this->reclassify($record);
            $record = $record->fresh() ?? $record;
        }

        $flags = $this->inspector->inspect($record, $input, $employee, $settings, 'check_in', $now);
        if ($settings !== null) {
            $this->risk->score($record, $input, $employee, $settings, 'check_in', $flags);
            $record = $record->fresh() ?? $record;
        }

        AttendanceRecorded::dispatch($record);

        // A punch that became a record of its own: a new one, or one kept
        // beside an earlier record of the day and flagged for review (a
        // second session, another device hours later) - its check-out is
        // announced too, so its check-in must be. One merged into or
        // deduplicated against an existing record - the same person seen by
        // a second device minutes apart - announces nothing: the record it
        // joined was announced when it was made.
        if (in_array($resolution->action, ['created', 'flagged'], true)) {
            $this->webhook($record->tenant_id, 'attendance.checked_in', AttendanceWebhookPayload::of($record, $employee));
        }

        return new AttendanceResult($record);
    }

    private function processCheckOut(AttendanceInput $input, Employee $employee, string $zone, ?AttendanceSetting $settings = null): AttendanceResult
    {
        $now = $this->resolveMoment($input);
        $record = $this->findOpenRecord($input, $now, $zone);

        if (! $record) {
            throw new NoOpenCheckIn(__('attendance.no_open_check_in'));
        }

        $updates = ['check_out' => $now];

        // The record's `photo_path` belongs to the check-in selfie; a check-out
        // selfie is kept alongside it so neither punch loses its evidence.
        // The check-out's own idempotency key is kept beside it too: the
        // record's `idempotency_key` column is the check-in's (see record()).
        // The check-out's own IP and device signals, beside the check-in's:
        // a day punched in on one phone and out on another is a finding
        // (AttendanceRiskScorer), and until 2026-10-24 only the check-in's
        // were kept.
        $signals = array_intersect_key($input->metadata, array_flip([
            'accuracy', 'altitude', 'speed', 'heading', 'fix_age_ms', 'device',
            'client_offset_seconds', 'webdriver', 'user_agent',
            'device_verdict', 'device_public_id', 'device_install_changed',
        ]));
        $metadata = array_filter([
            'checkout_photo_path' => $input->photoPath,
            'checkout_idempotency_key' => $input->idempotencyKey,
            'checkout_ip_address' => $input->ipAddress,
            'checkout_signals' => $signals !== [] ? $signals : null,
        ], fn ($value) => $value !== null);

        if ($metadata !== []) {
            $updates['metadata'] = array_merge($record->metadata ?? [], $metadata);
        }

        $record->update($updates);

        if ($record->shift && $record->status === AttendanceStatus::PRESENT && $this->leftEarly($record, $zone)) {
            $record->update(['status' => AttendanceStatus::EARLY_LEAVE]);
        }

        $flags = $this->inspector->inspect($record, $input, $employee, $settings, 'check_out', $now);
        if ($settings !== null) {
            $this->risk->score($record, $input, $employee, $settings, 'check_out', $flags);
            $record = $record->fresh() ?? $record;
        }

        AttendanceRecorded::dispatch($record);

        $this->webhook($record->tenant_id, 'attendance.checked_out', AttendanceWebhookPayload::of($record, $employee));

        return new AttendanceResult($record);
    }

    /**
     * Re-derive the status of a record whose punches were changed after the
     * fact — an approved correction, a manual entry's typed times — by the
     * same rules a live punch is rated with.
     *
     * Only the three statuses the punches decide are touched. Voided, on-leave,
     * holiday and absent are decisions about the day, not readings of a clock,
     * and a record with no shift has nothing to be rated against.
     *
     * Before 2026-10-12 nothing did this: a correction that moved a record to
     * a full shift on time left it `early_leave` (attendance QA A5).
     */
    public function reclassify(AttendanceRecord $record): void
    {
        $rated = [AttendanceStatus::PRESENT, AttendanceStatus::LATE, AttendanceStatus::EARLY_LEAVE];

        if (! $record->shift || ! $record->check_in || ! in_array($record->status, $rated, true)) {
            return;
        }

        $zone = TenantTime::zone(Tenant::query()->find($record->tenant_id));

        $status = AttendanceStatus::from($this->shiftMatcher->calculateStatus(
            $record->check_in->copy()->setTimezone($zone),
            $record->shift,
            $record->workDate($zone),
        ));

        // As at a live check-out, a late arrival stays late however the day
        // ended: early departure is read only for someone who arrived on time.
        if ($status === AttendanceStatus::PRESENT && $this->leftEarly($record, $zone)) {
            $status = AttendanceStatus::EARLY_LEAVE;
        }

        if ($status !== $record->status) {
            $record->update(['status' => $status]);
        }
    }

    /**
     * The minutes a `late` or `early_leave` record missed of its shift, read
     * from the same shift window the status was rated against: from the
     * shift's start to the check-in, or from the check-out to the shift's end.
     * Grace decides whether a punch is late, not how late it is. Zero for any
     * other status or a record with no shift. $zone is the tenant's, when the
     * caller already knows it and reads many records.
     */
    public function missedMinutes(AttendanceRecord $record, ?string $zone = null): int
    {
        if (! $record->shift || ! $record->check_in) {
            return 0;
        }

        $zone ??= TenantTime::zone(Tenant::query()->find($record->tenant_id));
        [$shiftStart, $shiftEnd] = $record->shift->windowOn($record->workDate($zone), $zone);

        return match ($record->status) {
            AttendanceStatus::LATE => max(0, (int) $shiftStart->diffInMinutes($record->check_in, false)),
            AttendanceStatus::EARLY_LEAVE => $record->check_out === null
                ? 0
                : max(0, (int) $record->check_out->diffInMinutes($shiftEnd, false)),
            default => 0,
        };
    }

    /** Whether the record's check-out came before its shift's early-departure threshold. */
    private function leftEarly(AttendanceRecord $record, string $zone): bool
    {
        if (! $record->shift || ! $record->check_in || ! $record->check_out) {
            return false;
        }

        // The shift's end on the record's work day, in the tenant's zone (see
        // processCheckIn). A shift that crosses midnight ends the next day:
        // leaving at 23:30 was once compared with that morning's 06:00 and so
        // never counted as early.
        [, $shiftEnd] = $record->shift->windowOn($record->workDate($zone), $zone);

        return $record->check_out->lt($shiftEnd->subMinutes($record->shift->early_departure_minutes));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Events\AttendanceRecorded;
use App\Exceptions\AttendanceRefused;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\TenantTime;
use Carbon\Carbon;

final class AttendanceEngine
{
    /** How long after a check-in a check-out on the next day may still close it. */
    private const MAX_OPEN_HOURS = 14;

    public function __construct(
        private readonly ConfidenceScorer $scorer,
        private readonly ShiftMatcher $shiftMatcher,
        private readonly ConflictResolver $conflictResolver,
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
            if ($existing === null && $input->type === 'check_out') {
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

        $settings = AttendanceSetting::withoutGlobalScope('tenant')
            ->where('tenant_id', $input->tenantId)
            ->first();

        if ($settings && ! $settings->isMethodEnabled($input->source->value)) {
            throw new AttendanceRefused(__('attendance.method_disabled'));
        }

        $employee = Employee::findOrFail($input->employeeId);
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

        if ($input->type === 'check_out') {
            return $this->processCheckOut($input, $employee, $zone);
        }

        return $this->processCheckIn($input, $employee, $zone, $settings);
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
        $shift = $this->shiftMatcher->match($employee, $local);

        $geofenceVerified = null;
        if ($input->latitude !== null && $input->longitude !== null && $employee->branch) {
            $geofenceVerified = $this->scorer->verifyGeofence(
                $input->latitude,
                $input->longitude,
                $employee->branch,
            );
        }

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
            $statusStr = $this->shiftMatcher->calculateStatus($local, $shift);
            $status = AttendanceStatus::from($statusStr);
        }

        $record = AttendanceRecord::create([
            'tenant_id' => $input->tenantId,
            'employee_id' => $input->employeeId,
            'shift_id' => $shift?->id,
            'date' => $local->format('Y-m-d'),
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

        AttendanceRecorded::dispatch($record);

        return new AttendanceResult($record);
    }

    private function processCheckOut(AttendanceInput $input, Employee $employee, string $zone): AttendanceResult
    {
        $now = $this->resolveMoment($input);
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
            ->with('shift')
            ->latest('check_in')
            ->get();

        // Yesterday's record is also closable when it began less than
        // MAX_OPEN_HOURS ago: an evening shift whose overtime runs past the
        // tenant's midnight belongs to the day it began. The UTC clock hid
        // this until 03:00 Addis by accident; dating by the tenant's day
        // (processCheckIn) exposed it. A check-in left open yesterday morning
        // stays out of reach — it is a missing punch, not this check-out.
        $record = $candidates->first(fn (AttendanceRecord $r) => $r->date->format('Y-m-d') === $today)
            ?? $candidates->first(fn (AttendanceRecord $r) => $r->shift?->crosses_midnight === true
                || $r->check_in->diffInMinutes($now, absolute: true) < self::MAX_OPEN_HOURS * 60);

        if (! $record) {
            throw new NoOpenCheckIn(__('attendance.no_open_check_in'));
        }

        $updates = ['check_out' => $now];

        // The record's `photo_path` belongs to the check-in selfie; a check-out
        // selfie is kept alongside it so neither punch loses its evidence.
        // The check-out's own idempotency key is kept beside it too: the
        // record's `idempotency_key` column is the check-in's (see record()).
        $metadata = array_filter([
            'checkout_photo_path' => $input->photoPath,
            'checkout_idempotency_key' => $input->idempotencyKey,
        ], fn ($value) => $value !== null);

        if ($metadata !== []) {
            $updates['metadata'] = array_merge($record->metadata ?? [], $metadata);
        }

        $record->update($updates);

        if ($record->shift && $record->status === AttendanceStatus::PRESENT && $this->leftEarly($record, $zone)) {
            $record->update(['status' => AttendanceStatus::EARLY_LEAVE]);
        }

        AttendanceRecorded::dispatch($record);

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

    /** Whether the record's check-out came before its shift's early-departure threshold. */
    private function leftEarly(AttendanceRecord $record, string $zone): bool
    {
        if (! $record->shift || ! $record->check_in || ! $record->check_out) {
            return false;
        }

        // The shift's end on the day the record began, in the tenant's zone
        // (see processCheckIn).
        $shiftEnd = $record->check_in->copy()->setTimezone($zone)
            ->setTimeFromTimeString($record->shift->end_time);

        // A shift that crosses midnight ends the day after it starts: leaving
        // at 23:30 was compared with this morning's 06:00 and so never counted
        // as early.
        if ($record->shift->crosses_midnight && $shiftEnd->lte($record->check_in)) {
            $shiftEnd->addDay();
        }

        return $record->check_out->lt($shiftEnd->subMinutes($record->shift->early_departure_minutes));
    }
}

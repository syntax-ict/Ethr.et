<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Events\AttendanceRecorded;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use Carbon\Carbon;

final class AttendanceEngine
{
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

            if ($existing) {
                return new AttendanceResult($existing, wasDuplicate: true);
            }
        }

        $settings = AttendanceSetting::withoutGlobalScope('tenant')
            ->where('tenant_id', $input->tenantId)
            ->first();

        if ($settings && ! $settings->isMethodEnabled($input->source->value)) {
            throw new \RuntimeException(__('attendance.method_disabled'));
        }

        $employee = Employee::findOrFail($input->employeeId);

        if ($input->type === 'check_out') {
            return $this->processCheckOut($input, $employee);
        }

        return $this->processCheckIn($input, $employee, $settings);
    }

    /**
     * The moment the punch happened: the supplied event time for delayed
     * ingestion, otherwise now. Everything downstream (shift match, status,
     * date, stored timestamp) keys off this so a backlog is dated correctly.
     */
    private function resolveMoment(AttendanceInput $input): Carbon
    {
        return $input->occurredAt !== null
            ? Carbon::parse($input->occurredAt)
            : Carbon::now();
    }

    private function processCheckIn(AttendanceInput $input, Employee $employee, ?AttendanceSetting $settings = null): AttendanceResult
    {
        $now = $this->resolveMoment($input);
        $shift = $this->shiftMatcher->match($employee, $now);

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
                throw new \RuntimeException(__('attendance.geofence_location_required'));
            }
            if ($geofenceVerified === false) {
                throw new \RuntimeException(__('attendance.outside_geofence'));
            }
        }

        $confidence = $this->scorer->calculate($input, $geofenceVerified);

        $status = AttendanceStatus::PENDING;
        if ($shift) {
            $statusStr = $this->shiftMatcher->calculateStatus($now, $shift);
            $status = AttendanceStatus::from($statusStr);
        }

        $record = AttendanceRecord::create([
            'tenant_id' => $input->tenantId,
            'employee_id' => $input->employeeId,
            'shift_id' => $shift?->id,
            'date' => $now->format('Y-m-d'),
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

        if ($input->source->value === 'manual') {
            AuditLog::record('attendance.manual_entry', $record);
        }

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

    private function processCheckOut(AttendanceInput $input, Employee $employee): AttendanceResult
    {
        $now = $this->resolveMoment($input);

        // Today's open record, or yesterday's when its shift crosses midnight:
        // a 22:00-06:00 record is dated the night it began, so a lookup on
        // today's date alone could never close it — the morning check-out
        // answered 500 and device webhooks dropped the punch.
        $candidates = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $input->tenantId)
            ->where('employee_id', $input->employeeId)
            ->whereDate('date', '>=', $now->copy()->subDay()->format('Y-m-d'))
            ->whereDate('date', '<=', $now->format('Y-m-d'))
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with('shift')
            ->latest('check_in')
            ->get();

        $record = $candidates->first(fn (AttendanceRecord $r) => $r->date->isSameDay($now))
            ?? $candidates->first(fn (AttendanceRecord $r) => $r->shift?->crosses_midnight === true);

        if (! $record) {
            throw new NoOpenCheckIn(__('attendance.no_open_check_in'));
        }

        $updates = ['check_out' => $now];

        // The record's `photo_path` belongs to the check-in selfie; a check-out
        // selfie is kept alongside it so neither punch loses its evidence.
        if ($input->photoPath !== null) {
            $updates['metadata'] = array_merge($record->metadata ?? [], [
                'checkout_photo_path' => $input->photoPath,
            ]);
        }

        $record->update($updates);

        if ($record->shift && $record->status === AttendanceStatus::PRESENT) {
            $shiftEnd = $now->copy()->setTimeFromTimeString($record->shift->end_time);

            // A shift that crosses midnight ends the day after it starts:
            // leaving at 23:30 was compared with this morning's 06:00 and so
            // never counted as early.
            if ($record->shift->crosses_midnight && $shiftEnd->lte($record->check_in)) {
                $shiftEnd->addDay();
            }

            $earlyThreshold = $shiftEnd->copy()->subMinutes($record->shift->early_departure_minutes);

            if ($now->lt($earlyThreshold)) {
                $record->update(['status' => AttendanceStatus::EARLY_LEAVE]);
            }
        }

        AttendanceRecorded::dispatch($record);

        return new AttendanceResult($record);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
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
use App\Services\FileStorageService;
use App\Support\AttendanceRules;
use App\Support\AttendanceWebhookPayload;
use App\Support\TenantTime;
use App\Traits\DispatchesWebhooks;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

final class AttendanceEngine
{
    use DispatchesWebhooks;

    /** The live self-service check-ins a required geofence refuses outside the place. */
    private const GEOFENCED = [AttendanceSource::MOBILE, AttendanceSource::QR, AttendanceSource::WEB];

    public function __construct(
        private readonly ConfidenceScorer $scorer,
        private readonly ShiftMatcher $shiftMatcher,
        private readonly ConflictResolver $conflictResolver,
        private readonly IntegrityInspector $inspector,
        private readonly AttendancePolicy $policy,
        private readonly AttendancePlaces $places,
        private readonly AttendanceRiskScorer $risk,
        private readonly FileStorageService $storage,
    ) {}

    /**
     * The phone's selfie is stored before the punch is decided. When the
     * punch is refused, or a retry is answered with the record that already
     * has its own photo, the new one belongs to no record: no retention
     * schedule would ever find it, so it is deleted here (A-Z review D-02).
     */
    public function record(AttendanceInput $input): AttendanceResult
    {
        try {
            $result = $this->serialised($input);
        } catch (\Throwable $e) {
            $this->discardPhoto($input->photoPath);

            throw $e;
        }

        if ($input->photoPath !== null && ! in_array($input->photoPath, $result->record->photoPaths(), true)) {
            $this->discardPhoto($input->photoPath);
        }

        return $result;
    }

    private function discardPhoto(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->storage->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * One person's punches are taken one at a time. Two methods can reach the
     * server in the same instant (the phone and the kiosk reader, a device
     * backlog landing while the person taps the web button); each looked for
     * an open record, found none and inserted its own, and only the unique
     * idempotency key — different for every method — stood between them. The
     * lock makes the second one see the first's record, so ConflictResolver
     * and the duplicate checks below decide, as they do for punches minutes
     * apart. A lock that cannot be had in time lets the punch through
     * unserialised: a delayed punch is worse than a flagged one.
     */
    /**
     * A punch's writes (the record, a merge that voids another, the punch
     * log, findings, the risk score, the conflict row) commit together or not
     * at all. They were separate statements: a failure after the record was
     * written left it saved without its findings or score, record() deleted
     * the selfie it pointed at, and the client's retry was answered with that
     * half-made record as a duplicate, so it was never inspected.
     *
     * The lock is released outside the transaction and a failure to release
     * it is only reported: an exception escaping after the commit would have
     * made record() delete the photo of a punch that was saved.
     */
    private function serialised(AttendanceInput $input): AttendanceResult
    {
        $lock = Cache::lock("attendance-punch:{$input->tenantId}:{$input->employeeId}", 20);

        try {
            $held = $lock->block(8);
        } catch (LockTimeoutException) {
            $held = false;
        }

        try {
            return DB::transaction(fn () => $this->decide($input));
        } finally {
            if ($held) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    private function decide(AttendanceInput $input): AttendanceResult
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
            if ($existing) {
                // A key answers only for the person whose punch used it. Keys
                // are unique per organisation, not per person, so a punch
                // carrying someone else's key was answered with their record
                // (A-Z review D-09).
                if ($existing->employee_id !== $input->employeeId) {
                    throw new AttendanceRefused(__('attendance.retry_key_taken'));
                }

                return new AttendanceResult($existing, wasDuplicate: true, outcome: $this->outcomeFor($existing, 'check_in'));
            }

            if (in_array($input->type, ['check_out', 'auto'], true)) {
                $existing = AttendanceRecord::query()
                    ->where('tenant_id', $input->tenantId)
                    ->where('employee_id', $input->employeeId)
                    ->where('metadata->checkout_idempotency_key', $input->idempotencyKey)
                    ->first();

                if ($existing) {
                    return new AttendanceResult($existing, wasDuplicate: true, punch: 'check_out', outcome: $this->outcomeFor($existing, 'check_out'));
                }
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

        if ($input->type === 'break_out' || $input->type === 'break_in') {
            return $this->processBreak($input, $employee, $zone, $settings);
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
        $open = $this->findOpenRecord($input, $now, $zone, directionGuessed: true);

        // A punch older than every open record's check-in (an offline kiosk
        // syncing late, a device backlog) finds none, since it cannot end a
        // day before it began. It is a check-in, and the conflict resolver
        // decides whether it joins the open record.
        if ($open === null) {
            // The day was closed a moment ago by another method and this is
            // the same person leaving, seen again (the gate reader after the
            // phone, a device re-sending). Not a new arrival.
            if (($closed = $this->findJustClosed($input, $now)) !== null) {
                return $this->duplicateCheckOut($closed, $input, $now);
            }

            return $this->processCheckIn($input, $employee, $zone, $settings);
        }

        if ($open->check_in->diffInMinutes($now, absolute: true) < AttendanceRules::MERGE_WINDOW_MINUTES) {
            $this->logPunch($open, $input, 'check_in', $now, 'duplicate');

            return new AttendanceResult($open, wasDuplicate: true, outcome: $this->alreadyIn($open));
        }

        return $this->processCheckOut($input, $employee, $zone, $settings);
    }

    /**
     * A record of this person's whose check-out landed within the merge window
     * of `$now`: the same departure reported by a second method.
     */
    private function findJustClosed(AttendanceInput $input, Carbon $now): ?AttendanceRecord
    {
        $window = AttendanceRules::MERGE_WINDOW_MINUTES;

        return AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $input->tenantId)
            ->where('employee_id', $input->employeeId)
            ->whereNotNull('check_out')
            ->where('check_in', '<=', $now)
            ->whereBetween('check_out', [$now->copy()->subMinutes($window), $now->copy()->addMinutes($window)])
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->latest('check_out')
            ->first();
    }

    private function duplicateCheckOut(AttendanceRecord $closed, AttendanceInput $input, Carbon $now): AttendanceResult
    {
        $this->logPunch($closed, $input, 'check_out', $now, 'duplicate');

        return new AttendanceResult($closed, wasDuplicate: true, punch: 'check_out', outcome: $this->alreadyOut($closed));
    }

    /**
     * What a check-in or check-out came to for the person who made it: on time
     * or late, out on time or early. Read from the record's status, which the
     * shift's rules set, so a replay says what the original said.
     */
    private function outcomeFor(AttendanceRecord $record, string $punch, ?string $zone = null): PunchOutcome
    {
        if ($punch === 'check_out') {
            return $this->isEarlyDeparture($record, $zone)
                ? new PunchOutcome(PunchOutcome::CHECKED_OUT_EARLY, $this->earlyMinutes($record, $zone))
                : new PunchOutcome(PunchOutcome::CHECKED_OUT);
        }

        return $record->status === AttendanceStatus::LATE
            ? new PunchOutcome(PunchOutcome::CHECKED_IN_LATE, $this->lateMinutes($record, $zone))
            : new PunchOutcome(PunchOutcome::CHECKED_IN);
    }

    /** The day is already open: who opened it, and when. */
    private function alreadyIn(AttendanceRecord $record): PunchOutcome
    {
        return new PunchOutcome(
            PunchOutcome::ALREADY_CHECKED_IN,
            0,
            $this->loggedSource($record, 'check_in', latest: false) ?? $record->source,
            $record->check_in,
        );
    }

    /** The day is already closed: who closed it, and when. */
    private function alreadyOut(AttendanceRecord $record): PunchOutcome
    {
        return new PunchOutcome(
            PunchOutcome::ALREADY_CHECKED_OUT,
            0,
            $this->loggedSource($record, 'check_out', latest: true) ?? $record->source,
            $record->check_out,
        );
    }

    /**
     * The method behind a punch of this kind on the record, from its punch
     * log: the first one that counted (a check-in) or the last (a check-out).
     * A repeat the log marks `duplicate` is not the punch itself.
     */
    private function loggedSource(AttendanceRecord $record, string $kind, bool $latest): ?AttendanceSource
    {
        $entries = collect((array) ($record->metadata['punches'] ?? []))
            ->filter(fn ($punch) => ($punch['kind'] ?? null) === $kind && ($punch['outcome'] ?? null) !== 'duplicate');
        $entry = $latest ? $entries->last() : $entries->first();

        return AttendanceSource::tryFrom((string) ($entry['source'] ?? ''));
    }

    /**
     * Keep every method's punch on the day's one record, so the timeline can
     * show who reported what when. A merge keeps only the survivor's `source`;
     * this is the rest of the story. Capped, because a device that re-sends
     * all day must not grow the row without end.
     */
    private function punchLog(array $metadata, AttendanceInput $input, string $kind, Carbon $at, string $outcome): array
    {
        $punches = $metadata['punches'] ?? [];
        $punches[] = [
            'kind' => $kind,
            'source' => $input->source->value,
            'at' => $at->toIso8601String(),
            'outcome' => $outcome,
        ];
        $metadata['punches'] = array_slice($punches, -30);

        return $metadata;
    }

    private function logPunch(AttendanceRecord $record, AttendanceInput $input, string $kind, Carbon $at, string $outcome): void
    {
        $record->update(['metadata' => $this->punchLog($record->metadata ?? [], $input, $kind, $at, $outcome)]);
    }

    /**
     * A terminal's break button. The break-out and break-in were sent as a
     * check-out and a check-in, so the break-out closed the morning's record
     * as an early leave and the break-in opened a second one, rated late
     * against the shift's start and logged as a conflict (A-Z review D-06).
     * A break is noted on the open record instead, which it never opens or
     * closes; the shift's `break_minutes` still decide what is paid. With no
     * record open there is nothing to note it on, and it is kept as an
     * ordinary punch rather than lost.
     */
    private function processBreak(AttendanceInput $input, Employee $employee, string $zone, AttendanceSetting $settings): AttendanceResult
    {
        $now = $this->resolveMoment($input);

        // A resent break is found by its key on whichever of the person's
        // records took it, before looking for an open one: the record may
        // have closed since, and the resend would otherwise open another.
        if ($input->idempotencyKey !== null) {
            $local = $now->copy()->setTimezone($zone);
            $noted = AttendanceRecord::withoutGlobalScope('tenant')
                ->where('tenant_id', $input->tenantId)
                ->where('employee_id', $input->employeeId)
                ->whereDate('date', '>=', $local->copy()->subDay()->format('Y-m-d'))
                ->whereDate('date', '<=', $local->format('Y-m-d'))
                ->get()
                ->first(fn (AttendanceRecord $r) => collect((array) ($r->metadata['breaks'] ?? []))->contains('key', $input->idempotencyKey));
            if ($noted !== null) {
                return new AttendanceResult($noted, wasDuplicate: true, punch: $input->type === 'break_out' ? 'check_out' : 'check_in');
            }
        }

        $open = $this->findOpenRecord($input, $now, $zone);

        if ($open === null) {
            return $this->processAuto($input, $employee, $zone, $settings);
        }

        // The published punch stays in or out: only devices send breaks, and
        // they read nothing back.
        $punch = $input->type === 'break_out' ? 'check_out' : 'check_in';

        $breaks = (array) ($open->metadata['breaks'] ?? []);
        $breaks[] = array_filter([
            'type' => $input->type === 'break_out' ? 'out' : 'in',
            'at' => $now->toIso8601String(),
            'key' => $input->idempotencyKey,
        ], fn ($value) => $value !== null);
        $open->update(['metadata' => array_merge($open->metadata ?? [], ['breaks' => $breaks])]);

        return new AttendanceResult($open, punch: $punch);
    }

    /**
     * The record a check-out at `$now` closes: today's open one, or
     * yesterday's when its shift crosses midnight or it began less than
     * MAX_OPEN_HOURS ago. Shared by check-out and `auto`, so both close the
     * same record.
     *
     * $directionGuessed is an `auto` punch, whose device does not say whether
     * the person is arriving or leaving. It closes yesterday's record only
     * when it could be the end of that record's shift, not merely within
     * MAX_OPEN_HOURS of its check-in.
     */
    private function findOpenRecord(AttendanceInput $input, Carbon $now, string $zone, bool $directionGuessed = false): ?AttendanceRecord
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
            // A check-out that arrives late (an offline phone, a device
            // backlog) closes the record it belongs to, never one that began
            // after it: that record would end before it started (A-Z review D-05).
            ->where('check_in', '<=', $now)
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
        //
        // A night shift's record is closable past MAX_OPEN_HOURS (a long
        // shift, an early arrival), but only until a few hours after the
        // shift ends. It was closable at any time on the next day: a guard
        // who missed the 06:00 exit scan had the next evening's arrival read
        // as the check-out of a 24-hour shift, and the morning's exit then
        // opened a new record.
        return $candidates->first(fn (AttendanceRecord $r) => $r->date->format('Y-m-d') === $today)
            ?? $candidates->first(function (AttendanceRecord $r) use ($now, $zone, $directionGuessed): bool {
                $nearShiftEnd = $r->shift !== null
                    && $now->lt($r->shift->windowOn($r->workDate($zone), $zone)[1]->addHours(AttendanceRules::CLOSE_HOURS_AFTER_SHIFT_END));

                // An `auto` punch hours after yesterday's shift ended is
                // today's arrival, however recently yesterday's check-in was.
                if ($directionGuessed && $r->shift !== null) {
                    return $nearShiftEnd;
                }

                return $r->check_in->diffInMinutes($now, absolute: true) < AttendanceRules::MAX_OPEN_HOURS * 60
                    || ($nearShiftEnd && $r->shift->crossesMidnight());
            });
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

        // One record a day, whichever method reports it. This person's work
        // day is already open, so this is not a new arrival: another method
        // (the phone, the kiosk, the web, a QR code) got there first. Within
        // the merge window it still goes through the resolver below, which
        // folds the evidence into the one record; later than that it used to
        // open a second record flagged for review, and now only says so. A
        // check-in after the day was closed is a second session and is kept.
        //
        // Only live punches. A manual or imported entry is times somebody
        // typed for a day, not an arrival: where it clashes with a punch it is
        // kept and flagged for review, never answered "already in".
        $live = ! in_array($input->source, [AttendanceSource::MANUAL, AttendanceSource::CSV], true);
        $open = $live ? $this->findOpenRecord($input, $now, $zone) : null;
        $already = null;
        if ($open !== null && $open->date->format('Y-m-d') === $workDate) {
            $already = $this->alreadyIn($open);

            if ($open->check_in->diffInMinutes($now, absolute: true) > AttendanceRules::MERGE_WINDOW_MINUTES) {
                $this->logPunch($open, $input, 'check_in', $now, 'duplicate');

                return new AttendanceResult($open, wasDuplicate: true, outcome: $already);
            }
        }

        $geofenceVerified = $this->insidePlace($input, $employee);

        // Refused only when the place is known and the punch is outside it.
        // A person with no placed branch or site cannot be checked
        // (AttendancePlaces answers null): until 2026-10-24 that refused
        // every phone punch at the branch the moment the switch was turned
        // on. It is recorded and flagged `geofence_unconfigured` instead
        // (IntegrityInspector).
        //
        // Every live self-service channel, not the phone alone: a forwarded
        // QR code or the web button pressed at home checked in from anywhere
        // (A-Z review D-01). A web check-in from the office network is the
        // office PC and needs no location. A punch an offline phone syncs
        // later is kept and flagged: refusing it then would lose it unseen.
        if ($settings?->geofence_required && in_array($input->source, self::GEOFENCED, true)
            && ! ($input->source === AttendanceSource::WEB && $this->onOfficeNetwork($input, $settings))) {
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

        $this->logPunch($record, $input, 'check_in', $now, $resolution->action);

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

        // Folded into the day's record: the arrival is already on record.
        // Otherwise a second session beside it, or the arrival itself.
        $outcome = match (true) {
            in_array($resolution->action, ['merged', 'deduped'], true) && $already !== null => $already,
            $resolution->action === 'flagged' => new PunchOutcome(PunchOutcome::CHECKED_IN_AGAIN),
            default => $this->outcomeFor($record, 'check_in', $zone),
        };

        return new AttendanceResult($record, outcome: $outcome);
    }

    private function processCheckOut(AttendanceInput $input, Employee $employee, string $zone, ?AttendanceSetting $settings = null): AttendanceResult
    {
        $now = $this->resolveMoment($input);
        $record = $this->findOpenRecord($input, $now, $zone);

        if (! $record) {
            // Closed a moment ago, by this method or another: the departure
            // is already recorded.
            if (($closed = $this->findJustClosed($input, $now)) !== null) {
                return $this->duplicateCheckOut($closed, $input, $now);
            }

            throw new NoOpenCheckIn(__('attendance.no_open_check_in'));
        }

        // A required geofence asks the phone and a QR scan for a location at
        // check-out too, as at check-in. Being outside the place is flagged,
        // not refused (IntegrityInspector): refusing would leave the day open
        // for someone who has already gone. The web button sends no location
        // and is flagged `location_missing` instead.
        if ($settings?->geofence_required && $input->latitude === null
            && in_array($input->source, [AttendanceSource::MOBILE, AttendanceSource::QR], true)) {
            throw new AttendanceRefused(__('attendance.geofence_location_required'));
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
        // Where the check-out was made, beside the check-in's columns. Only
        // the distance went into a finding before, and only when outside; a
        // late check-out disputed as overtime had no location on record.
        $metadata = array_filter([
            'checkout_photo_path' => $input->photoPath,
            'checkout_idempotency_key' => $input->idempotencyKey,
            'checkout_ip_address' => $input->ipAddress,
            'checkout_signals' => $signals !== [] ? $signals : null,
            'checkout_latitude' => $input->latitude,
            'checkout_longitude' => $input->longitude,
            'checkout_geofence_verified' => $this->insidePlace($input, $employee),
        ], fn ($value) => $value !== null);

        $updates['metadata'] = $this->punchLog(
            array_merge($record->metadata ?? [], $metadata),
            $input,
            'check_out',
            $now,
            'applied',
        );

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

        return new AttendanceResult($record, punch: 'check_out', outcome: $this->outcomeFor($record, 'check_out', $zone));
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
     * shift's start to the check-in, plus from the check-out to the shift's
     * end. Grace decides whether a punch is late, not how late it is. Zero for
     * any other status or a record with no shift. $zone is the tenant's, when
     * the caller already knows it and reads many records.
     *
     * A record keeps `late` however the day ended, so someone 20 minutes late
     * who left three hours early was counted 20 minutes — the cap on a
     * lateness deduction, and what payroll was shown — until 2026-11-08.
     */
    public function missedMinutes(AttendanceRecord $record, ?string $zone = null): int
    {
        return $this->lateMinutes($record, $zone) + $this->earlyMinutes($record, $zone);
    }

    /** From the shift's start to a `late` record's check-in. */
    private function lateMinutes(AttendanceRecord $record, ?string $zone): int
    {
        if (! $record->shift || ! $record->check_in || $record->status !== AttendanceStatus::LATE) {
            return 0;
        }

        $zone ??= TenantTime::zone(Tenant::query()->find($record->tenant_id));
        [$shiftStart] = $record->shift->windowOn($record->workDate($zone), $zone);

        return max(0, (int) $shiftStart->diffInMinutes($record->check_in, false));
    }

    /** From an early departure's check-out to the shift's end. */
    private function earlyMinutes(AttendanceRecord $record, ?string $zone): int
    {
        if (! $record->shift || ! $record->check_out || ! $this->isEarlyDeparture($record, $zone)) {
            return 0;
        }

        $zone ??= TenantTime::zone(Tenant::query()->find($record->tenant_id));
        [, $shiftEnd] = $record->shift->windowOn($record->workDate($zone), $zone);

        return max(0, (int) $record->check_out->diffInMinutes($shiftEnd, false));
    }

    /**
     * Whether the record ended in an early departure: `early_leave`, or a
     * `late` arrival that also left before the early-departure threshold.
     */
    private function isEarlyDeparture(AttendanceRecord $record, ?string $zone): bool
    {
        if ($record->status === AttendanceStatus::EARLY_LEAVE) {
            return true;
        }
        if ($record->status !== AttendanceStatus::LATE || ! $record->shift || ! $record->check_out) {
            return false;
        }

        return $this->leftEarly($record, $zone ?? TenantTime::zone(Tenant::query()->find($record->tenant_id)));
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

    /**
     * Whether the punch's fix is inside the branch or any of the person's
     * work sites, giving it the benefit of its reported accuracy
     * (AttendancePlaces). Null with no fix, or no place to be inside.
     */
    private function insidePlace(AttendanceInput $input, Employee $employee): ?bool
    {
        if ($input->latitude === null || $input->longitude === null) {
            return null;
        }

        $accuracy = $input->metadata['accuracy'] ?? null;

        return $this->places->check(
            $this->places->for($employee),
            $input->latitude,
            $input->longitude,
            is_numeric($accuracy) ? (float) $accuracy : null,
        )['inside'];
    }

    /** The same office networks the device gate reads (AttendanceDeviceGate). */
    private function onOfficeNetwork(AttendanceInput $input, AttendanceSetting $settings): bool
    {
        $networks = array_filter((array) ($settings->office_networks ?? []), 'is_string');

        return $networks !== [] && $input->ipAddress !== null && IpUtils::checkIp($input->ipAddress, $networks);
    }
}

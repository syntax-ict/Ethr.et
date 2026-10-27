<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Enums\CorrectionKind;
use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\OrgScope;
use App\Enums\OvertimeRequestStatus;
use App\Models\AttendanceConflict;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Models\User;
use App\Services\Attendance\AttendanceEngine;
use App\Support\AttendanceRules;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What still stands between a pay period's attendance and its payroll: every
 * exception payroll staff used to reconcile by hand, each with whether
 * someone has acted on it.
 *
 * It owns nothing and writes nothing. It reads the sources that already
 * exist, by the rules payroll itself applies (PayrollEngine's own detectors,
 * ExpectedDays through them), and links each exception to the request that
 * settles it, all three already on the approval engine:
 *
 *  - an unexplained absence: a missed-punch claim (AttendanceCorrection), or
 *    leave filed for the day (unpaid, excused or a business trip);
 *  - a late arrival or early departure, when the organisation deducts
 *    lateness: a lateness correction;
 *  - a punch left open or closed by auto check-out: a correction;
 *  - overtime past what was approved: an overtime request for the day.
 *
 * Integrity flags and duplicate-punch conflicts are listed too and settled on
 * their own screens. An exception's state is derived from what it points at,
 * never stored, so this cannot disagree with the requests: `open` (nothing
 * filed, or filed and rejected), `pending` (filed, undecided), `resolved`
 * (approved). Overtime over a limit is `info`: overtime worked is paid
 * (Art. 68), so there is nothing to decide and it never blocks a run.
 */
final class AttendanceReconciliation
{
    public const TYPE_ABSENCE = 'unexplained_absence';

    public const TYPE_LATE = 'late_arrival';

    public const TYPE_EARLY = 'early_leave';

    public const TYPE_MISSED_PUNCH = 'missed_punch';

    public const TYPE_AUTO_CHECKOUT = 'auto_checkout';

    public const TYPE_OVERTIME_UNAPPROVED = 'overtime_unapproved';

    public const TYPE_OVERTIME_OVER_LIMIT = 'overtime_over_limit';

    public const TYPE_INTEGRITY = 'integrity_flag';

    public const TYPE_CONFLICT = 'unresolved_conflict';

    public const STATE_OPEN = 'open';

    public const STATE_PENDING = 'pending';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_INFO = 'info';

    /** Ten minutes; any change to what it reads invalidates it sooner (forget()). */
    private const CACHE_SECONDS = 600;

    public function __construct(
        private readonly PayrollEngine $payroll,
        private readonly AttendanceEngine $attendance,
    ) {}

    /** The organisation's mode: `off`, `advisory` or `required`. */
    public static function mode(int $tenantId): string
    {
        $mode = AttendanceSetting::query()->where('tenant_id', $tenantId)->value('reconciliation_mode');

        return in_array($mode, ['advisory', 'required'], true) ? $mode : 'off';
    }

    /**
     * Anything a reconciliation reads changed for this organisation: every
     * cached answer for it is stale. Called from the model events the
     * AppServiceProvider registers.
     */
    public static function forget(int $tenantId): void
    {
        Cache::forever(self::versionKey($tenantId), (string) microtime(true));
    }

    /**
     * @return array{
     *     period: array{start: string, end: string},
     *     mode: string,
     *     lateness_deduction_enabled: bool,
     *     totals: array{open: int, pending: int, resolved: int, info: int, blocking: int},
     *     by_type: list<array{type: string, count: int}>,
     *     unmatched_punches: int,
     *     employees: list<array{employee_public_id: string, employee_name: string, employee_code: string|null, exceptions: list<array{type: string, date: string, state: string, minutes: int|null, record_public_id: string|null, request_kind: string|null, request_public_id: string|null}>}>,
     * }
     */
    public function forPeriod(Tenant $tenant, Carbon $start, Carbon $end, ?User $viewer = null): array
    {
        $key = implode(':', [
            'reconciliation',
            $tenant->id,
            (string) Cache::get(self::versionKey($tenant->id), '0'),
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $viewer === null ? 'all' : $viewer->id,
        ]);

        return Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->build($tenant, $start, $end, $viewer));
    }

    /**
     * How many exceptions in the period are still open or pending, across
     * everyone payroll pays. What `required` mode refuses a run's approval
     * on. Never cached: the gate reads the state as it is now.
     */
    public function blocking(Tenant $tenant, Carbon $start, Carbon $end): int
    {
        return $this->build($tenant, $start, $end, null)['totals']['blocking'];
    }

    /**
     * One employee's own exceptions in the period, what they are shown so
     * they can say a recorded absence is wrong (a missed-punch claim) before
     * payroll settles it. Overtime over a limit is left out: it is paid, and
     * there is nothing for them to do about it.
     *
     * @return list<array{type: string, date: string, state: string, minutes: int|null, record_public_id: string|null, request_kind: string|null, request_public_id: string|null}>
     */
    public function forEmployee(Tenant $tenant, Employee $employee, Carbon $start, Carbon $end): array
    {
        $rows = $this->build($tenant, $start, $end, null, $employee->id)['employees'];

        return array_values(array_filter(
            $rows[0]['exceptions'] ?? [],
            fn (array $exception): bool => $exception['state'] !== self::STATE_INFO,
        ));
    }

    /**
     * Whether a correction, leave or overtime request touching the period was
     * approved after $since: the run calculated before it pays from
     * attendance that has since been decided otherwise.
     */
    public static function decidedSince(int $tenantId, Carbon $start, Carbon $end, Carbon $since): bool
    {
        $first = $start->format('Y-m-d');
        $last = $end->format('Y-m-d');

        $corrections = AttendanceCorrection::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CorrectionStatus::APPROVED)
            ->where('updated_at', '>', $since)
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d->whereDate('date', '>=', $first)->whereDate('date', '<=', $last))
                ->orWhereHas('attendanceRecord', fn ($r) => $r->whereDate('date', '>=', $first)->whereDate('date', '<=', $last)))
            ->exists();

        if ($corrections) {
            return true;
        }

        $leave = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', LeaveStatus::APPROVED)
            ->where('updated_at', '>', $since)
            ->whereDate('start_date', '<=', $last)
            ->whereDate('end_date', '>=', $first)
            ->exists();

        if ($leave) {
            return true;
        }

        return OvertimeRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', OvertimeRequestStatus::APPROVED)
            ->where('updated_at', '>', $since)
            ->whereDate('date', '>=', $first)
            ->whereDate('date', '<=', $last)
            ->exists();
    }

    /**
     * @return array{
     *     period: array{start: string, end: string},
     *     mode: string,
     *     lateness_deduction_enabled: bool,
     *     totals: array{open: int, pending: int, resolved: int, info: int, blocking: int},
     *     by_type: list<array{type: string, count: int}>,
     *     unmatched_punches: int,
     *     employees: list<array{employee_public_id: string, employee_name: string, employee_code: string|null, exceptions: list<array{type: string, date: string, state: string, minutes: int|null, record_public_id: string|null, request_kind: string|null, request_public_id: string|null}>}>,
     * }
     */
    private function build(Tenant $tenant, Carbon $start, Carbon $end, ?User $viewer, ?int $onlyEmployeeId = null): array
    {
        $tenantId = $tenant->id;
        $first = $start->format('Y-m-d');
        $last = $end->format('Y-m-d');
        $today = TenantTime::today($tenant)->format('Y-m-d');
        $lateness = (bool) AttendanceSetting::query()->where('tenant_id', $tenantId)->value('lateness_deduction_enabled');

        $query = $this->payroll->payableEmployees($tenantId, $start, $end)
            ->when($onlyEmployeeId !== null, fn ($q) => $q->whereKey($onlyEmployeeId));
        if ($viewer !== null && $viewer->orgScope() !== OrgScope::ALL) {
            $viewer->scopeAccessibleEmployees($query);
        }
        /** @var Collection<int, Employee> $employees */
        $employees = $query->orderBy('name')->get();
        $ids = $employees->pluck('id')->all();

        $claims = $this->corrections($tenantId, $ids, $first, $last);
        $leave = $this->pendingLeave($tenantId, $ids, $first, $last);
        $overtimeRequests = $this->pendingOvertime($tenantId, $ids, $first, $last);
        $records = $this->recordsToReview($tenantId, $ids, $first, $last, $today, $lateness);
        $integrity = $this->openIntegrity($tenantId, $ids, $first, $last);
        $conflicts = $this->openConflicts($tenantId, $ids, $first, $last);

        $rows = [];
        $totals = ['open' => 0, 'pending' => 0, 'resolved' => 0, 'info' => 0, 'blocking' => 0];
        $byType = [];

        foreach ($employees as $employee) {
            $exceptions = [];

            foreach ($this->payroll->unexplainedAbsences($employee, $start, $end) as $day) {
                $claim = $claims['by_day'][$employee->id][$day] ?? null;
                $request = $claim !== null && $claim->status === CorrectionStatus::PENDING
                    ? ['correction', $claim->public_id]
                    : $this->leaveCovering($leave[$employee->id] ?? [], $day);

                $exceptions[] = self::item(self::TYPE_ABSENCE, $day, $request === null ? self::STATE_OPEN : self::STATE_PENDING, null, null, $request);
            }

            foreach ($records[$employee->id] ?? [] as $record) {
                $exceptions[] = $this->recordException($record, $claims['by_record'][$record->id] ?? [], $lateness);
            }

            ['by_day' => $byDay] = $this->payroll->gatherOvertimeByType($employee, $start, $end);
            if ($byDay !== []) {
                $limits = $this->payroll->overtimeLimits($employee, $byDay, $start, $end);
                foreach ($limits['days_over_approved'] as $over) {
                    $pending = $overtimeRequests[$employee->id][$over['date']] ?? null;
                    $exceptions[] = self::item(
                        self::TYPE_OVERTIME_UNAPPROVED,
                        $over['date'],
                        $pending === null ? self::STATE_OPEN : self::STATE_PENDING,
                        (int) ($over['worked_minutes'] - $over['approved_minutes']),
                        null,
                        $pending === null ? null : ['overtime', $pending],
                    );
                }
                foreach (array_unique([...$limits['days_over_daily_limit'], ...$limits['days_over_legal_daily_limit']]) as $day) {
                    $exceptions[] = self::item(self::TYPE_OVERTIME_OVER_LIMIT, $day, self::STATE_INFO, isset($byDay[$day]) ? (int) $byDay[$day] : null, null, null);
                }
            }

            foreach ($integrity[$employee->id] ?? [] as [$day, $recordPublicId]) {
                $exceptions[] = self::item(self::TYPE_INTEGRITY, $day, self::STATE_OPEN, null, $recordPublicId, null);
            }

            foreach ($conflicts[$employee->id] ?? [] as $day) {
                $exceptions[] = self::item(self::TYPE_CONFLICT, $day, self::STATE_OPEN, null, null, null);
            }

            if ($exceptions === []) {
                continue;
            }

            usort($exceptions, fn (array $a, array $b): int => [$a['date'], $a['type']] <=> [$b['date'], $b['type']]);

            foreach ($exceptions as $exception) {
                $totals[$exception['state']]++;
                $byType[$exception['type']] = ($byType[$exception['type']] ?? 0) + 1;
                if (in_array($exception['state'], [self::STATE_OPEN, self::STATE_PENDING], true)) {
                    $totals['blocking']++;
                }
            }

            $rows[] = [
                'employee_public_id' => (string) $employee->public_id,
                'employee_name' => (string) $employee->name,
                'employee_code' => $employee->employee_code === null ? null : (string) $employee->employee_code,
                'exceptions' => $exceptions,
            ];
        }

        ksort($byType);
        $types = [];
        foreach ($byType as $type => $count) {
            $types[] = ['type' => (string) $type, 'count' => (int) $count];
        }

        return [
            'period' => ['start' => $first, 'end' => $last],
            'mode' => self::mode($tenantId),
            'lateness_deduction_enabled' => $lateness,
            'totals' => $totals,
            'by_type' => $types,
            'unmatched_punches' => $viewer === null || $viewer->orgScope() === OrgScope::ALL
                ? $this->unmatchedPunches($tenantId, $first, $last)
                : 0,
            'employees' => $rows,
        ];
    }

    /**
     * A record waiting on a decision: late or early (when lateness is
     * deducted), left open, or closed by auto check-out. Its state follows the
     * newest correction filed against it.
     *
     * The times come first: lateness read from a punch still open, or from
     * a check-out auto check-out wrote, is not yet worth deciding.
     *
     * @param  list<AttendanceCorrection>  $corrections  newest first
     * @return array{type: string, date: string, state: string, minutes: int|null, record_public_id: string|null, request_kind: string|null, request_public_id: string|null}
     */
    private function recordException(AttendanceRecord $record, array $corrections, bool $lateness): array
    {
        $day = $record->date->format('Y-m-d');
        $autoClosed = ! empty($record->metadata['auto_checkout']) && empty($record->metadata['is_corrected']);
        $type = match (true) {
            $record->check_out === null => self::TYPE_MISSED_PUNCH,
            $autoClosed => self::TYPE_AUTO_CHECKOUT,
            $lateness && $record->status === AttendanceStatus::LATE => self::TYPE_LATE,
            default => self::TYPE_EARLY,
        };

        // A lateness decision settles a late or early record; a correction of
        // its times settles an open or auto-closed one.
        $kind = in_array($type, [self::TYPE_LATE, self::TYPE_EARLY], true) ? CorrectionKind::LATENESS : CorrectionKind::CORRECTION;
        $latest = collect($corrections)->first(fn (AttendanceCorrection $c): bool => $c->kind === $kind);

        $state = match ($latest?->status) {
            CorrectionStatus::PENDING => self::STATE_PENDING,
            CorrectionStatus::APPROVED, CorrectionStatus::APPLIED => self::STATE_RESOLVED,
            default => self::STATE_OPEN,
        };

        $minutes = in_array($type, [self::TYPE_LATE, self::TYPE_EARLY], true) ? $this->attendance->missedMinutes($record) : null;

        return self::item(
            $type,
            $day,
            $state,
            $minutes,
            (string) $record->public_id,
            $latest === null || $state === self::STATE_OPEN ? null : ['correction', (string) $latest->public_id],
        );
    }

    /**
     * @param  array{0: string, 1: string}|null  $request  [kind, public id]
     * @return array{type: string, date: string, state: string, minutes: int|null, record_public_id: string|null, request_kind: string|null, request_public_id: string|null}
     */
    private static function item(string $type, string $date, string $state, ?int $minutes, ?string $recordPublicId, ?array $request): array
    {
        return [
            'type' => $type,
            'date' => $date,
            'state' => $state,
            'minutes' => $minutes,
            'record_public_id' => $recordPublicId,
            'request_kind' => $request[0] ?? null,
            'request_public_id' => $request[1] ?? null,
        ];
    }

    /**
     * Corrections in the period: missed-punch claims by employee and day, and
     * every other kind by the record it is against, newest first.
     *
     * @param  list<int>  $ids
     * @return array{by_day: array<int, array<string, AttendanceCorrection>>, by_record: array<int, list<AttendanceCorrection>>}
     */
    private function corrections(int $tenantId, array $ids, string $first, string $last): array
    {
        $byDay = [];
        $byRecord = [];

        if ($ids === []) {
            return ['by_day' => $byDay, 'by_record' => $byRecord];
        }

        $corrections = AttendanceCorrection::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->where(fn ($q) => $q
                ->where(fn ($claim) => $claim
                    ->where('kind', CorrectionKind::MISSED_PUNCH->value)
                    ->whereDate('date', '>=', $first)
                    ->whereDate('date', '<=', $last))
                ->orWhereHas('attendanceRecord', fn ($r) => $r
                    ->whereDate('date', '>=', $first)
                    ->whereDate('date', '<=', $last)))
            ->orderByDesc('id')
            ->get(['id', 'public_id', 'employee_id', 'attendance_record_id', 'kind', 'date', 'status']);

        foreach ($corrections as $correction) {
            if ($correction->kind === CorrectionKind::MISSED_PUNCH && $correction->date !== null) {
                $byDay[$correction->employee_id][$correction->date->format('Y-m-d')] ??= $correction;
            }
            if ($correction->attendance_record_id !== null) {
                $byRecord[$correction->attendance_record_id][] = $correction;
            }
        }

        return ['by_day' => $byDay, 'by_record' => $byRecord];
    }

    /**
     * Pending leave overlapping the period, by employee.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{start: string, end: string, public_id: string}>>
     */
    private function pendingLeave(int $tenantId, array $ids, string $first, string $last): array
    {
        $out = [];

        if ($ids === []) {
            return $out;
        }

        $requests = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->where('status', LeaveStatus::PENDING)
            ->whereDate('start_date', '<=', $last)
            ->whereDate('end_date', '>=', $first)
            ->get(['employee_id', 'public_id', 'start_date', 'end_date']);

        foreach ($requests as $request) {
            $out[$request->employee_id][] = [
                'start' => Carbon::parse($request->start_date)->format('Y-m-d'),
                'end' => Carbon::parse($request->end_date)->format('Y-m-d'),
                'public_id' => (string) $request->public_id,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{start: string, end: string, public_id: string}>  $requests
     * @return array{0: string, 1: string}|null
     */
    private function leaveCovering(array $requests, string $day): ?array
    {
        foreach ($requests as $request) {
            if ($request['start'] <= $day && $day <= $request['end']) {
                return ['leave', $request['public_id']];
            }
        }

        return null;
    }

    /**
     * Pending overtime requests by employee and day.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private function pendingOvertime(int $tenantId, array $ids, string $first, string $last): array
    {
        $out = [];

        if ($ids === []) {
            return $out;
        }

        $requests = OvertimeRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->where('status', OvertimeRequestStatus::PENDING)
            ->whereDate('date', '>=', $first)
            ->whereDate('date', '<=', $last)
            ->get(['employee_id', 'public_id', 'date']);

        foreach ($requests as $request) {
            $out[$request->employee_id][$request->date->format('Y-m-d')] ??= (string) $request->public_id;
        }

        return $out;
    }

    /**
     * The period's records that need a decision, by employee: late and early
     * ones when the organisation deducts lateness, punches still open past the
     * longest a shift can run, and check-outs auto check-out wrote that no one
     * has corrected.
     *
     * @param  list<int>  $ids
     * @return array<int, list<AttendanceRecord>>
     */
    private function recordsToReview(int $tenantId, array $ids, string $first, string $last, string $today, bool $lateness): array
    {
        $out = [];

        if ($ids === []) {
            return $out;
        }

        $openBefore = now()->subHours(AttendanceRules::MAX_OPEN_HOURS);

        $records = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $first)
            ->whereDate('date', '<=', min($last, $today))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->where(fn ($q) => $q
                ->when($lateness, fn ($late) => $late->orWhereIn('status', [AttendanceStatus::LATE, AttendanceStatus::EARLY_LEAVE]))
                ->orWhere(fn ($open) => $open->whereNotNull('check_in')->whereNull('check_out')->where('check_in', '<', $openBefore))
                ->orWhere(fn ($auto) => $auto->whereNotNull('metadata->auto_checkout')->whereNull('metadata->is_corrected')))
            ->with('shift')
            ->orderBy('date')
            ->get();

        foreach ($records as $record) {
            $out[$record->employee_id][] = $record;
        }

        return $out;
    }

    /**
     * Open integrity flags at a serious level, as the run register lists them.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{0: string, 1: string}>> employee id => [[day, record public id]]
     */
    private function openIntegrity(int $tenantId, array $ids, string $first, string $last): array
    {
        $out = [];

        if ($ids === []) {
            return $out;
        }

        $flags = AttendanceIntegrityFlag::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->where('status', 'open')
            ->where(fn ($q) => $q->where('severity', 'high')->orWhere('type', 'high_risk'))
            ->whereHas('record', fn ($q) => $q
                ->whereDate('date', '>=', $first)
                ->whereDate('date', '<=', $last))
            ->with('record:id,public_id,date')
            ->get(['id', 'employee_id', 'attendance_record_id']);

        $seen = [];
        foreach ($flags as $flag) {
            $record = $flag->record;
            if ($record === null || isset($seen[$record->id])) {
                continue;
            }
            $seen[$record->id] = true;
            $out[$flag->employee_id][] = [$record->date->format('Y-m-d'), (string) $record->public_id];
        }

        return $out;
    }

    /**
     * Duplicate-punch conflicts still pending, by employee and day.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function openConflicts(int $tenantId, array $ids, string $first, string $last): array
    {
        $out = [];

        if ($ids === []) {
            return $out;
        }

        $conflicts = AttendanceConflict::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->where('resolution', ConflictResolutionStatus::PENDING->value)
            ->with('recordA:id,date')
            ->get(['id', 'employee_id', 'record_a_id']);

        foreach ($conflicts as $conflict) {
            $day = $conflict->recordA?->date?->format('Y-m-d');
            if ($day !== null && $day >= $first && $day <= $last) {
                $out[$conflict->employee_id][] = $day;
            }
        }

        return $out;
    }

    /** Device punches no one could be matched to, received in the period. */
    private function unmatchedPunches(int $tenantId, string $first, string $last): int
    {
        return UnmatchedDevicePunch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->whereDate('created_at', '>=', $first)
            ->whereDate('created_at', '<=', $last)
            ->count();
    }

    private static function versionKey(int $tenantId): string
    {
        return "reconciliation:{$tenantId}:version";
    }
}

<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\TimesheetCode;
use App\Exceptions\AttendanceRefused;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\EnteredAttendance;
use App\Services\Attendance\TimesheetLedger;
use App\Services\CurrentTenant;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;

/*
 * The engine side of the accuracy suite, shared by every test file that feeds a
 * case to the engine (GoldenCasesTest, GeneratedMonthsTest, PropertiesTest).
 * Each of those loads it with require_once, so no test file depends on another
 * having been loaded first.
 *
 * Nothing here implements a rule. It builds the organisation a case describes,
 * sends the punches through AttendanceEngine::record() (a manual entry through
 * EnteredAttendance, as the manual endpoint does) and reads back through the
 * engine's own read functions.
 *
 * Every helper here is global in Pest, so each is prefixed `accuracy`.
 */

const ACCURACY_DAY = '2026-10-13';

const ACCURACY_ZONE = 'Africa/Addis_Ababa';

/**
 * A shift a case may name, from fixtures/shifts.php, which the oracle reads
 * too.
 *
 * @return array<string, mixed>
 */
function accuracyShift(string $name): array
{
    $shifts = require __DIR__.'/../fixtures/shifts.php';

    return $shifts[$name] ?? throw new InvalidArgumentException("Unknown shift {$name}");
}

/**
 * "08:40", "08:45:30", "+1 06:05" (the next day) or "-1 22:00" on the case's
 * work date, read in the case's zone, as a UTC moment.
 */
function accuracyMoment(string $day, string $zone, string $text): Carbon
{
    $offset = 0;
    if (preg_match('/^([+-]\d+) (.+)$/', $text, $m) === 1) {
        $offset = (int) $m[1];
        $text = $m[2];
    }
    $date = Carbon::parse($day, $zone)->addDays($offset)->format('Y-m-d');
    $time = strlen($text) === 5 ? $text.':00' : $text;

    return Carbon::parse("{$date} {$time}", $zone)->utc();
}

/**
 * AttendanceRecord keeps each tenant's zone in a static array keyed by tenant
 * id, for the life of the process. Every test's in-memory database numbers its
 * tenants from 1 again, so a zone remembered from an earlier test is applied to
 * a later organisation and shifts its overtime by the difference of the two
 * zones (found 2026-11-28: a month's answer depended on which months ran before
 * it). Each run starts from an empty cache, as a new process would.
 */
function accuracyForgetProcessState(): void
{
    (new ReflectionProperty(AttendanceRecord::class, 'zones'))->setValue(null, []);
}

/**
 * @param  array<string, mixed>  $case
 * @return array{tenant: Tenant, employee: Employee, peer: ?Employee, zone: string, day: string}
 */
function accuracyFixture(array $case): array
{
    accuracyForgetProcessState();

    $zone = $case['zone'] ?? ACCURACY_ZONE;
    $day = $case['day'] ?? ACCURACY_DAY;
    $setup = $case['setup'] ?? [];

    Carbon::setTestNow(Carbon::parse($day.' 00:00', $zone)->utc()->subDays(2));

    $tenant = createTenant(['timezone' => $zone]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $elsewhere = Branch::factory()->create(['tenant_id' => $tenant->id]);

    if (isset($setup['holiday'])) {
        Holiday::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'date' => $setup['holiday'] === true ? $day : $setup['holiday'], 'recurring' => false, 'is_active' => true,
        ]);
    }
    if (isset($setup['holiday_elsewhere'])) {
        Holiday::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $elsewhere->id,
            'date' => $setup['holiday_elsewhere'] === true ? $day : $setup['holiday_elsewhere'], 'recurring' => false, 'is_active' => true,
        ]);
    }

    if (isset($setup['auto_checkout'])) {
        AttendanceSetting::create([
            ...AttendanceSetting::defaults(),
            'tenant_id' => $tenant->id,
            'auto_checkout_enabled' => true,
            'auto_checkout_after_minutes' => (int) $setup['auto_checkout'],
        ]);
    }

    $shift = ($case['shift'] ?? null) === null ? null : Shift::factory()->create([
        'tenant_id' => $tenant->id,
        ...accuracyShift($case['shift']),
        ...($setup['shift'] ?? []),
    ]);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => 'ACC-1',
        'badge_number' => '9001',
        'hire_date' => $setup['hire_date'] ?? '2024-01-01',
        'termination_date' => $setup['termination_date'] ?? null,
    ]);
    if ($shift !== null) {
        ShiftAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'shift_id' => $shift->id,
            'assignable_type' => Employee::class,
            'assignable_id' => $employee->id,
            'effective_from' => '2024-01-01',
        ]);
    }

    if (isset($setup['leave'])) {
        $type = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
        LeaveRequest::factory()->approved()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $setup['leave'][0],
            'end_date' => $setup['leave'][1],
            'days' => 1,
            'status' => LeaveStatus::APPROVED,
        ]);
    }

    // Absence is read only for an organisation that recorded attendance in the
    // period (AR-ABS-05): a second person's record supplies it.
    $peer = null;
    if (isset($setup['peer'])) {
        $peerShift = Shift::factory()->create(['tenant_id' => $tenant->id, ...accuracyShift('S1'), 'name' => 'Peer']);
        $peer = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'hire_date' => '2024-01-01']);
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $peer->id,
            'shift_id' => $peerShift->id,
            'date' => $setup['peer'] === true ? $day : $setup['peer'],
            'check_in' => accuracyMoment($setup['peer'] === true ? $day : $setup['peer'], $zone, '08:30'),
            'check_out' => accuracyMoment($setup['peer'] === true ? $day : $setup['peer'], $zone, '17:30'),
            'status' => AttendanceStatus::PRESENT,
        ]);
    }

    return compact('tenant', 'employee', 'peer', 'zone', 'day');
}

/**
 * Sends one punch and says what came of it: ok, duplicate, or refused:<reason>.
 * With `$delaySeconds` the clock is held that long after the punch's own time,
 * as when a punch reaches the server late; the punch keeps its own time.
 *
 * @param  array<string, mixed>  $punch
 * @param  array{tenant: Tenant, employee: Employee, zone: string, day: string}  $ctx
 */
function accuracyPunch(array $ctx, array $punch, string $key, int $delaySeconds = 0): string
{
    $zone = $ctx['zone'];
    $day = $ctx['day'];
    $employee = $ctx['employee'];
    $key = $punch['key'] ?? $key;
    $source = AttendanceSource::from($punch['method'] ?? 'web');

    try {
        if (($punch['type'] ?? '') === 'manual') {
            $in = accuracyMoment($day, $zone, $punch['in']);
            $out = isset($punch['out']) ? accuracyMoment($day, $zone, $punch['out']) : null;
            Carbon::setTestNow(($out ?? $in)->copy());
            $result = app(EnteredAttendance::class)->enter($employee, $punch['date'] ?? $day, $in, $out, ['reason' => 'accuracy case'], $key, null, $source);
        } else {
            $at = accuracyMoment($day, $zone, $punch['at']);
            Carbon::setTestNow($at->copy()->addSeconds($delaySeconds));
            $type = match ($punch['type']) {
                'in' => 'check_in',
                'out' => 'check_out',
                'auto' => 'auto',
                default => throw new InvalidArgumentException("Unknown punch type {$punch['type']}"),
            };
            $result = app(AttendanceEngine::class)->record(new AttendanceInput(
                employeeId: $employee->id,
                tenantId: $ctx['tenant']->id,
                source: $source,
                type: $type,
                idempotencyKey: $key,
                occurredAt: $at->toIso8601String(),
            ));
        }
    } catch (AttendanceRefused $e) {
        return 'refused:'.$e->reasonCode();
    }

    return $result->wasDuplicate ? 'duplicate' : 'ok';
}

/** A moment as the clock on the wall in the case's zone, `H:i:s`; null stays null. */
function accuracyClock(?Carbon $moment, string $zone): ?string
{
    return $moment?->copy()->setTimezone($zone)->format('H:i:s');
}

/** A wall-clock reading at the precision the hand value was typed with (`H:i` or `H:i:s`). */
function accuracyLocal(?string $clock, string $like): ?string
{
    if ($clock === null) {
        return null;
    }

    return strlen($like) === 8 ? $clock : substr($clock, 0, 5);
}

/**
 * Reads the engine back into the shape CaseAdapter::run() answers in, for the
 * given work dates: `outcomes`, `live`, `conflicts`, and per date `live`,
 * `class`, `absent`, `overtime` (buckets of minutes, zeros dropped), `worked_day`
 * (the plain sum of every live record's workedMinutes()) and `records`, one
 * entry per live record by check-in with `status`, `late`, `early`, `worked`,
 * `check_in`, `check_out` (`H:i:s` in the zone), `breaks` and `metadata`. A date
 * with exactly one live record also carries those per-record keys flat, as the
 * oracle does. `code` is the timesheet's short code. `metadata` is the stored
 * array as it is.
 *
 * The clock is left at the moment of reading: `$readAt` when given, else the
 * case's `now`, else wherever the last punch left it. With no dates nothing is
 * read from the ledger.
 *
 * @param  array<string, mixed>  $case
 * @param  array<string, mixed>  $ctx
 * @param  list<string>  $outcomes
 * @param  list<string>  $dates
 * @return array{outcomes: list<string>, live: int, conflicts: int, days: array<string, array<string, mixed>>}
 */
function accuracyEngineAnswer(array $case, array $ctx, array $outcomes, array $dates, ?Carbon $readAt = null): array
{
    $zone = $ctx['zone'];
    $employee = $ctx['employee'];
    $tenant = $ctx['tenant'];

    $engine = app(AttendanceEngine::class);
    app(CurrentTenant::class)->set($tenant);
    $all = AttendanceRecord::query()->where('employee_id', $employee->id)->with('shift')->orderBy('check_in')->orderBy('id')->get();
    $live = $all->reject(fn (AttendanceRecord $r): bool => $r->status === AttendanceStatus::VOIDED)->values();

    $answer = [
        'outcomes' => $outcomes,
        'live' => $live->count(),
        'conflicts' => AttendanceConflict::query()->where('employee_id', $employee->id)->count(),
        'days' => [],
    ];

    if ($dates === []) {
        return $answer;
    }

    // The case's clock for reading: after the last punch unless it says so.
    $now = $readAt ?? (isset($case['now']) ? accuracyMoment($ctx['day'], $zone, $case['now']) : Carbon::now());
    Carbon::setTestNow($now);

    $sorted = $dates;
    sort($sorted);
    $from = Carbon::parse($sorted[0]);
    $to = Carbon::parse($sorted[count($sorted) - 1]);
    $ledger = app(TimesheetLedger::class)->build(
        collect([$employee->fresh()]), $from, $to, app(StatutoryRules::class)->for($tenant, $from),
    )['employees'][$employee->id]['cells'];

    foreach ($dates as $date) {
        $cell = $ledger[$date];
        $onDay = $live->filter(fn (AttendanceRecord $r): bool => $r->date->format('Y-m-d') === $date)->values();

        $records = $onDay->map(fn (AttendanceRecord $r): array => [
            'status' => $r->status->value,
            'late' => $engine->lateMinutes($r, $zone),
            'early' => $engine->earlyMinutes($r, $zone),
            'worked' => $r->workedMinutes(),
            'check_in' => accuracyClock($r->check_in, $zone),
            'check_out' => accuracyClock($r->check_out, $zone),
            'breaks' => collect((array) ($r->metadata['breaks'] ?? []))->pluck('type')->all(),
            'metadata' => (array) ($r->metadata ?? []),
        ])->all();

        $overtime = array_filter($cell['overtime']);
        ksort($overtime);

        $day = [
            'live' => $onDay->count(),
            'class' => $cell['kind']->value,
            'code' => $cell['code']?->short(),
            'absent' => $cell['code'] === TimesheetCode::ABSENT,
            'overtime' => $overtime,
            'worked_day' => (int) array_sum(array_column($records, 'worked')),
            'records' => $records,
        ];
        if (count($records) === 1) {
            $day += $records[0];
        }

        $answer['days'][$date] = $day;
    }

    return $answer;
}

/**
 * The hand values of a case against what the engine answered, collecting every
 * disagreement instead of stopping at the first.
 *
 * @param  array<string, mixed>  $case
 * @param  array<string, mixed>  $ctx
 * @param  list<string>  $outcomes
 * @return list<string>
 */
function accuracyCompare(array $case, array $ctx, array $outcomes): array
{
    $expect = $case['expect'];
    $rules = implode(', ', (array) $case['rule']);
    $miss = [];
    $say = function (string $field, mixed $want, mixed $got) use (&$miss, $case, $rules): void {
        $fmt = fn (mixed $v): string => is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : var_export($v, true);
        $miss[] = "[{$case['id']}] rule {$rules}: {$field} expected {$fmt($want)}, engine said {$fmt($got)}";
    };

    $days = $expect['days'] ?? [];
    $answer = accuracyEngineAnswer($case, $ctx, $outcomes, array_keys($days));

    if (isset($expect['outcomes']) && $expect['outcomes'] !== $outcomes) {
        $say('punch outcomes', $expect['outcomes'], $outcomes);
    }
    if (isset($expect['live']) && $expect['live'] !== $answer['live']) {
        $say('live records', $expect['live'], $answer['live']);
    }
    if (isset($expect['conflicts']) && $expect['conflicts'] !== $answer['conflicts']) {
        $say('conflicts', $expect['conflicts'], $answer['conflicts']);
    }

    foreach ($days as $date => $want) {
        $got = $answer['days'][$date];

        if (isset($want['live']) && $want['live'] !== $got['live']) {
            $say("{$date} live records", $want['live'], $got['live']);
        }
        if (isset($want['class']) && $want['class'] !== $got['class']) {
            $say("{$date} expected-day class", $want['class'], $got['class']);
        }
        if (array_key_exists('code', $want) && $want['code'] !== $got['code']) {
            $say("{$date} timesheet code", $want['code'], $got['code']);
        }
        if (isset($want['overtime'])) {
            $wantOt = array_filter($want['overtime']);
            ksort($wantOt);
            if ($wantOt !== $got['overtime']) {
                $say("{$date} overtime minutes", $wantOt, $got['overtime']);
            }
        }

        // Per record: the first live record unless the case lists them.
        $records = $want['records'] ?? (array_intersect_key($want, array_flip(['status', 'late', 'early', 'worked', 'check_in', 'check_out', 'breaks', 'metadata'])) !== [] ? [$want] : []);
        foreach ($records as $i => $rw) {
            $r = $got['records'][$i] ?? null;
            if ($r === null) {
                $say("{$date} record #".($i + 1), 'a live record', 'none');

                continue;
            }
            $label = "{$date} record #".($i + 1);
            if (isset($rw['status']) && $rw['status'] !== $r['status']) {
                $say("{$label} status", $rw['status'], $r['status']);
            }
            if (isset($rw['late']) && $rw['late'] !== $r['late']) {
                $say("{$label} late minutes", $rw['late'], $r['late']);
            }
            if (isset($rw['early']) && $rw['early'] !== $r['early']) {
                $say("{$label} early minutes", $rw['early'], $r['early']);
            }
            if (isset($rw['worked']) && $rw['worked'] !== $r['worked']) {
                $say("{$label} worked minutes", $rw['worked'], $r['worked']);
            }
            foreach (['check_in', 'check_out'] as $col) {
                if (array_key_exists($col, $rw)) {
                    $seen = accuracyLocal($r[$col], (string) ($rw[$col] ?? '00:00'));
                    if ($rw[$col] !== $seen) {
                        $say("{$label} {$col}", $rw[$col], $seen);
                    }
                }
            }
            if (isset($rw['breaks']) && $rw['breaks'] !== $r['breaks']) {
                $say("{$label} breaks", $rw['breaks'], $r['breaks']);
            }
            if (isset($rw['metadata'])) {
                foreach ($rw['metadata'] as $k => $v) {
                    $seen = $r['metadata'][$k] ?? null;
                    if ($v !== $seen) {
                        $say("{$label} metadata.{$k}", $v, $seen);
                    }
                }
            }
        }
    }

    return $miss;
}

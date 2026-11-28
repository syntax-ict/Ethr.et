<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
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
 * The golden cases: every expected value in tests/Accuracy/cases is typed by
 * hand from docs/ATTENDANCE-RULES.md or the QA pack, before the engine is
 * asked. This file only builds the day, sends the punches through
 * AttendanceEngine::record() (a manual entry through EnteredAttendance, as the
 * manual endpoint does) and reads back through the engine's own read
 * functions. It implements no rule: it compares a hand value with an answer.
 *
 * A case the engine disagrees with is never edited to pass. It is listed in
 * findings.php, reported as incomplete with the text there, and triaged.
 * A case whose rule is Undecided in the register is skipped, never asserted.
 *
 * Every helper here is global in Pest, so each is prefixed `accuracy`.
 */

const ACCURACY_DAY = '2026-10-13';

const ACCURACY_ZONE = 'Africa/Addis_Ababa';

/** @return array<string, array{0: array<string, mixed>}> */
function accuracyDataset(): array
{
    $cases = [];
    foreach (glob(__DIR__.'/cases/*.php') ?: [] as $file) {
        foreach (require $file as $case) {
            $cases[$case['id']] = [$case];
        }
    }
    ksort($cases);

    // ACCURACY_CASE=GC-STATUS-002,GC-ZONE-001 runs only those cases. PHPUnit's
    // --filter cannot see a dataset key.
    $only = array_filter(array_map('trim', explode(',', (string) getenv('ACCURACY_CASE'))));

    return $only === [] ? $cases : array_intersect_key($cases, array_flip($only));
}

/** @return array<string, string> case id => what the engine answered */
function accuracyFindings(): array
{
    return require __DIR__.'/findings.php';
}

/**
 * The shifts a case may name. S1 and S2 are the two the brief and the QA pack
 * use; S3 is a plain 08:00 to 17:00 day.
 *
 * @return array<string, mixed>
 */
function accuracyShift(string $name): array
{
    return match ($name) {
        'S1' => ['name' => 'S1', 'start_time' => '08:30', 'end_time' => '17:30', 'crosses_midnight' => false, 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => '1,2,3,4,5'],
        'S2' => ['name' => 'S2', 'start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true, 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => '1,2,3,4,5,6,7'],
        'S3' => ['name' => 'S3', 'start_time' => '08:00', 'end_time' => '17:00', 'crosses_midnight' => false, 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => '1,2,3,4,5'],
        default => throw new InvalidArgumentException("Unknown shift {$name}"),
    };
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
 * @param  array<string, mixed>  $case
 * @return array{tenant: Tenant, employee: Employee, peer: ?Employee, zone: string, day: string}
 */
function accuracyFixture(array $case): array
{
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
 *
 * @param  array<string, mixed>  $punch
 * @param  array{tenant: Tenant, employee: Employee, zone: string, day: string}  $ctx
 */
function accuracyPunch(array $ctx, array $punch, string $key): string
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
            Carbon::setTestNow($at->copy());
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

function accuracyLocal(?Carbon $moment, string $zone, string $like): ?string
{
    if ($moment === null) {
        return null;
    }
    $format = strlen($like) === 8 ? 'H:i:s' : 'H:i';

    return $moment->copy()->setTimezone($zone)->format($format);
}

/**
 * Reads the day back and compares it with the hand values, collecting every
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
    $zone = $ctx['zone'];
    $employee = $ctx['employee'];
    $tenant = $ctx['tenant'];
    $rules = implode(', ', (array) $case['rule']);
    $miss = [];
    $say = function (string $field, mixed $want, mixed $got) use (&$miss, $case, $rules): void {
        $fmt = fn (mixed $v): string => is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : var_export($v, true);
        $miss[] = "[{$case['id']}] rule {$rules}: {$field} expected {$fmt($want)}, engine said {$fmt($got)}";
    };

    if (isset($expect['outcomes']) && $expect['outcomes'] !== $outcomes) {
        $say('punch outcomes', $expect['outcomes'], $outcomes);
    }

    $engine = app(AttendanceEngine::class);
    app(CurrentTenant::class)->set($tenant);
    $all = AttendanceRecord::query()->where('employee_id', $employee->id)->with('shift')->orderBy('check_in')->orderBy('id')->get();
    $live = $all->reject(fn (AttendanceRecord $r): bool => $r->status === AttendanceStatus::VOIDED)->values();

    if (isset($expect['live']) && $expect['live'] !== $live->count()) {
        $say('live records', $expect['live'], $live->count());
    }
    if (isset($expect['conflicts'])) {
        $got = AttendanceConflict::query()->where('employee_id', $employee->id)->count();
        if ($expect['conflicts'] !== $got) {
            $say('conflicts', $expect['conflicts'], $got);
        }
    }

    $days = $expect['days'] ?? [];
    if ($days === []) {
        return $miss;
    }

    // The case's clock for reading: after the last punch unless it says so.
    $now = isset($case['now']) ? accuracyMoment($ctx['day'], $zone, $case['now']) : Carbon::now();
    Carbon::setTestNow($now);

    $keys = array_keys($days);
    sort($keys);
    $from = Carbon::parse($keys[0]);
    $to = Carbon::parse($keys[count($keys) - 1]);
    $ledger = app(TimesheetLedger::class)->build(
        collect([$employee->fresh()]), $from, $to, app(StatutoryRules::class)->for($tenant, $from),
    )['employees'][$employee->id]['cells'];

    foreach ($days as $date => $want) {
        $cell = $ledger[$date];
        $onDay = $live->filter(fn (AttendanceRecord $r): bool => $r->date->format('Y-m-d') === $date)->values();

        if (isset($want['live']) && $want['live'] !== $onDay->count()) {
            $say("{$date} live records", $want['live'], $onDay->count());
        }
        if (isset($want['class']) && $want['class'] !== $cell['kind']->value) {
            $say("{$date} expected-day class", $want['class'], $cell['kind']->value);
        }
        if (array_key_exists('code', $want) && $want['code'] !== $cell['code']?->short()) {
            $say("{$date} timesheet code", $want['code'], $cell['code']?->short());
        }
        if (isset($want['overtime'])) {
            $wantOt = array_filter($want['overtime']);
            $gotOt = array_filter($cell['overtime']);
            ksort($wantOt);
            ksort($gotOt);
            if ($wantOt !== $gotOt) {
                $say("{$date} overtime minutes", $wantOt, $gotOt);
            }
        }

        // Per record: the first live record unless the case lists them.
        $records = $want['records'] ?? (array_intersect_key($want, array_flip(['status', 'late', 'early', 'worked', 'check_in', 'check_out', 'breaks', 'metadata'])) !== [] ? [$want] : []);
        foreach ($records as $i => $rw) {
            $r = $onDay->get($i);
            if ($r === null) {
                $say("{$date} record #".($i + 1), 'a live record', 'none');

                continue;
            }
            $label = "{$date} record #".($i + 1);
            if (isset($rw['status']) && $rw['status'] !== $r->status->value) {
                $say("{$label} status", $rw['status'], $r->status->value);
            }
            if (isset($rw['late']) && $rw['late'] !== $engine->lateMinutes($r, $zone)) {
                $say("{$label} late minutes", $rw['late'], $engine->lateMinutes($r, $zone));
            }
            if (isset($rw['early']) && $rw['early'] !== $engine->earlyMinutes($r, $zone)) {
                $say("{$label} early minutes", $rw['early'], $engine->earlyMinutes($r, $zone));
            }
            if (isset($rw['worked']) && $rw['worked'] !== $r->workedMinutes()) {
                $say("{$label} worked minutes", $rw['worked'], $r->workedMinutes());
            }
            foreach (['check_in', 'check_out'] as $col) {
                if (array_key_exists($col, $rw)) {
                    $got = accuracyLocal($r->{$col}, $zone, (string) ($rw[$col] ?? '00:00'));
                    if ($rw[$col] !== $got) {
                        $say("{$label} {$col}", $rw[$col], $got);
                    }
                }
            }
            if (isset($rw['breaks'])) {
                $got = collect((array) ($r->metadata['breaks'] ?? []))->pluck('type')->all();
                if ($rw['breaks'] !== $got) {
                    $say("{$label} breaks", $rw['breaks'], $got);
                }
            }
            if (isset($rw['metadata'])) {
                foreach ($rw['metadata'] as $k => $v) {
                    $got = $r->metadata[$k] ?? null;
                    if ($v !== $got) {
                        $say("{$label} metadata.{$k}", $v, $got);
                    }
                }
            }
        }
    }

    return $miss;
}

it('gives the answer typed by hand', function (array $case) {
    $rules = implode(', ', (array) $case['rule']);

    if ($case['undecided'] ?? false) {
        test()->markTestSkipped("{$case['id']}: rule undecided ({$rules}), the register has no answer to assert");
    }

    $ctx = accuracyFixture($case);

    $outcomes = [];
    foreach ($case['punches'] ?? [] as $i => $punch) {
        $outcomes[] = accuracyPunch($ctx, $punch, "acc-{$case['id']}-{$i}");
    }

    if (isset($case['setup']['auto_checkout'])) {
        Carbon::setTestNow(accuracyMoment($ctx['day'], $ctx['zone'], $case['auto_run_at']));
        test()->artisan('attendance:auto-checkout')->assertSuccessful();
    }

    $misses = accuracyCompare($case, $ctx, $outcomes);
    Carbon::setTestNow();

    $finding = accuracyFindings()[$case['id']] ?? null;
    if ($finding !== null) {
        expect($misses)->not->toBeEmpty("{$case['id']} is listed in findings.php but the engine now agrees with the hand value: remove it from the list");
        test()->markTestIncomplete("RECORDED FINDING {$case['id']} ({$rules}): {$finding}\n".implode("\n", $misses));
    }

    expect($misses)->toBe([], "\n".implode("\n", $misses));
})->with(accuracyDataset());

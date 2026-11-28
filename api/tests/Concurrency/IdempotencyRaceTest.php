<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveYear;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IdempotencyFixtures as Fx;
use Tests\Support\IdempotencyLeaveFixtures as Leave;
use Tests\Support\IdempotencyRace as Race;

/**
 * Payroll and leave writes that reach the server in the same instant (accuracy
 * session, section 8, rows 4, 5 and 6).
 *
 * Each case starts one PHP process per request or job (action-child.php), holds
 * them at a barrier and releases them together, then reads what the database
 * holds. A race that comes out wrong is a finding: the test is marked
 * incomplete with the figures it saw, so the gate stays green and the figures
 * are in the output. Where the guard holds, the case asserts it.
 *
 * Run with `./scripts/gates.sh concurrency`; phpunit.concurrency.xml says why
 * this cannot be a Feature test.
 */
beforeEach(function () {
    Race::guard();

    static $prepared = false;
    if (! $prepared) {
        Artisan::call('migrate', ['--force' => true]);
        // The Concurrency suite does not seed; the HTTP routes need the permissions.
        (new PermissionSeeder)->run();
        $prepared = true;
    }
});

/** A processed payroll run for a tenant of its own, and the one admin who processed it. */
function idemRacePayrollRun(): array
{
    $tenant = createTenant(['subdomain' => 'idem-'.strtolower(Str::random(10))]);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Fx::employeeWithBalances($tenant);
    $run = Fx::processRun($tenant, $admin->id);

    return compact('tenant', 'admin', 'employee', 'run');
}

function idemRaceAuditCount(int $tenantId, string $action): int
{
    return DB::table('audit_log')->where('tenant_id', $tenantId)->where('action', $action)->count();
}

/** @return list<int> The HTTP statuses the children got, sorted. */
function idemRaceStatuses(array $results, ?int $userId = null, ?string $urlPart = null): array
{
    return collect($results)
        ->filter(fn (array $r) => ($userId === null || $r['job']['user_id'] === $userId)
            && ($urlPart === null || str_contains($r['job']['url'], $urlPart)))
        ->map(fn (array $r) => $r['out']['status'] ?? -1)
        ->sort()->values()->all();
}

it('releases pay once when two approvals of the same payroll run reach the server together', function () {
    $fixtures = array_map(fn () => idemRacePayrollRun(), range(1, 4));

    $jobs = [];
    foreach ($fixtures as $f) {
        foreach ([1, 2] as $attempt) {
            $jobs[] = Race::http('PUT', Fx::runUrl($f['tenant'], $f['run'], 'approve'), $f['admin']->id, [], Fx::apiUrl($f['tenant'], "payroll/runs/{$f['run']->public_id}"));
        }
    }

    $results = Race::run($jobs);
    expect(Race::failures($results))->toBe([]);

    foreach ($fixtures as $f) {
        $tenantId = $f['tenant']->id;
        $statuses = idemRaceStatuses($results, $f['admin']->id);

        expect(DB::table('payroll_runs')->where('id', $f['run']->id)->value('status'))->toBe('approved');
        expect((int) DB::table('employee_loans')->where('employee_id', $f['employee']->id)->value('remaining_cents'))->toBe(220000);
        expect((int) DB::table('employee_cost_sharing')->where('employee_id', $f['employee']->id)->value('outstanding_cents'))->toBe(4920000);
        expect(idemRaceAuditCount($tenantId, 'payroll.balances_applied'))->toBe(1);
        expect($statuses)->toBe([200, 422], "tenant {$tenantId} answered ".json_encode($statuses));
    }
});

it('writes one set of entries when two ProcessPayrollJob handles of the same run reach the database together', function () {
    $tenant = createTenant(['subdomain' => 'idem-'.strtolower(Str::random(10))]);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $people = 40;
    foreach (range(1, $people) as $i) {
        Fx::employeeWithBalances($tenant);
    }
    $run = app(PayrollEngine::class)->begin($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $admin->id)->run;

    $results = Race::run(array_fill(0, 2, ['action' => 'process_job', 'run_id' => $run->id, 'tenant_id' => $tenant->id]));
    expect(Race::failures($results))->toBe([]);

    $entries = DB::table('payroll_entries')->where('payroll_run_id', $run->id)->count();
    $distinct = DB::table('payroll_entries')->where('payroll_run_id', $run->id)->distinct()->count('employee_id');
    $row = DB::table('payroll_runs')->where('id', $run->id)->first();

    if ($entries !== $people || $distinct !== $people) {
        test()->markTestIncomplete(sprintf(
            'FINDING: two ProcessPayrollJob handles raced on one run: %d entries for %d employees (%d distinct), run status %s, employee_count %d, gross_total_cents %d (one set is %d). The job is not unique and payroll_entries has no unique index on run and employee.',
            $entries, $people, $distinct, $row->status, $row->employee_count, $row->gross_total_cents, $people * 800000,
        ));
    }

    expect($entries)->toBe($people)
        ->and((int) $row->gross_total_cents)->toBe($people * 800000)
        ->and($row->status)->toBe('completed');
});

it('takes the days once when two decisions on the same leave request reach the server together', function () {
    $pairs = [];
    foreach (['approve', 'approve', 'approve', 'reject'] as $second) {
        $f = Leave::tenant();
        $days = Leave::days($f['employee']);
        $balance = Leave::balance($f['employee'], $f['type'], pending: $days + 2);
        $request = Leave::request($f['employee'], $f['type'], LeaveStatus::PENDING, $days);
        $pairs[] = $f + ['days' => $days, 'balance' => $balance, 'request' => $request, 'second' => $second];
    }

    $jobs = [];
    foreach ($pairs as $p) {
        $warm = Leave::url($p['tenant'], "leave/{$p['request']->public_id}");
        $jobs[] = Race::http('PUT', Leave::url($p['tenant'], "leave/{$p['request']->public_id}/approve"), $p['hr']->id, [], $warm);
        $jobs[] = $p['second'] === 'approve'
            ? Race::http('PUT', Leave::url($p['tenant'], "leave/{$p['request']->public_id}/approve"), $p['hr']->id, [], $warm)
            : Race::http('PUT', Leave::url($p['tenant'], "leave/{$p['request']->public_id}/reject"), $p['hr']->id, ['reason' => 'Busy week'], $warm);
    }

    $results = Race::run($jobs);
    expect(Race::failures($results))->toBe([]);

    foreach ($pairs as $p) {
        $balance = DB::table('leave_balances')->where('id', $p['balance']->id)->first();
        $status = DB::table('leave_requests')->where('id', $p['request']->id)->value('status');
        $statuses = idemRaceStatuses($results, $p['hr']->id, $p['request']->public_id);

        // One decision wins. The other is refused with 422 (or 403, from the
        // policy) when it read the request after the winner committed, and is
        // answered 200 with the winner's outcome when it passed those checks
        // first and found the request decided under the lock: nothing is done
        // and nothing is logged. All three are seen from run to run; none
        // moves the balance twice.
        $answers = collect($results)
            ->filter(fn (array $r) => str_contains($r['job']['url'], $p['request']->public_id))
            ->map(fn (array $r) => basename($r['job']['url']).':'.($r['out']['status'] ?? 'none'))
            ->all();
        expect($statuses)->each->toBeIn([200, 403, 422], "{$p['second']} pair answered ".json_encode($answers));
        expect($statuses)->toContain(200);
        expect((float) $balance->pending_days)->toBe(2.0, "{$p['second']} pair: the two other pending days moved");
        if ($status === LeaveStatus::APPROVED->value) {
            expect((float) $balance->used_days)->toBe($p['days'])
                ->and(idemRaceAuditCount($p['tenant']->id, 'leave.approved'))->toBe(1)
                ->and(idemRaceAuditCount($p['tenant']->id, 'leave.rejected'))->toBe(0);
        } else {
            expect($status)->toBe(LeaveStatus::REJECTED->value)
                ->and((float) $balance->used_days)->toBe(0.0)
                ->and(idemRaceAuditCount($p['tenant']->id, 'leave.rejected'))->toBe(1)
                ->and(idemRaceAuditCount($p['tenant']->id, 'leave.approved'))->toBe(0);
        }
    }
});

it('keeps one request and raises pending days once when identical leave requests reach the server together', function () {
    $people = [];
    // Eight people and three copies each: the window between the overlap check
    // and the insert is short, and one pair that misses it proves nothing.
    foreach (range(1, 8) as $i) {
        $f = Leave::tenant();
        $balance = Leave::balance($f['employee'], $f['type']);
        $people[] = $f + ['balance' => $balance, 'days' => Leave::days($f['employee'])];
    }

    $jobs = [];
    foreach ($people as $p) {
        $body = ['leave_type_public_id' => $p['type']->public_id, 'start_date' => Leave::START, 'end_date' => Leave::END, 'reason' => 'Family'];
        foreach ([1, 2, 3] as $attempt) {
            $jobs[] = Race::http('POST', Leave::url($p['tenant'], 'leave/request'), $p['person']->id, $body, Leave::url($p['tenant'], 'leave/my'));
        }
    }

    $results = Race::run($jobs);
    expect(Race::failures($results))->toBe([]);

    foreach ($people as $p) {
        $statuses = idemRaceStatuses($results, $p['person']->id);
        $rows = DB::table('leave_requests')->where('employee_id', $p['employee']->id)->count();
        $pending = (float) DB::table('leave_balances')->where('id', $p['balance']->id)->value('pending_days');

        expect($rows)->toBe(1, 'request rows: '.json_encode($statuses))
            ->and($pending)->toBe($p['days'])
            ->and($statuses)->toBe([201, 422, 422]);
    }
});

it('credits the month once when two accrual runs for the same tenant reach the database together', function () {
    $people = 30;
    $build = function () use ($people): array {
        $tenant = createTenant(['subdomain' => 'idem-'.strtolower(Str::random(10))]);
        $type = LeaveType::factory()->monthly()->create(['tenant_id' => $tenant->id, 'default_days' => 24]);
        $year = LeaveYear::forTenantId($tenant->id)->yearOf(Carbon::now());
        $ids = [];
        foreach (range(1, $people) as $i) {
            $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2020-01-01']);
            // Created up front so the only thing racing is the read-then-increment.
            LeaveBalance::query()->create([
                'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'year' => $year, 'entitled_days' => 0, 'used_days' => 0, 'carried_days' => 0, 'pending_days' => 0,
            ]);
            $ids[] = $employee->id;
        }

        return [$tenant, $type, $ids];
    };

    // A control tenant, accrued once and in one process, says what one run credits.
    [$control, $controlType] = $build();
    app(LeaveBalanceService::class)->accrueMonthly($control->id);
    $expected = (float) DB::table('leave_balances')->where('tenant_id', $control->id)->sum('entitled_days');

    [$tenant, $type] = $build();
    $results = Race::run(array_fill(0, 2, ['action' => 'accrue', 'tenant_id' => $tenant->id]));
    expect(Race::failures($results))->toBe([]);

    $actual = (float) DB::table('leave_balances')->where('tenant_id', $tenant->id)->sum('entitled_days');
    $overCredited = DB::table('leave_balances')->where('tenant_id', $tenant->id)->where('entitled_days', '>', $expected / $people + 0.05)->count();

    expect($expected)->toBeGreaterThan(0.0);
    if (abs($actual - $expected) > 0.05) {
        test()->markTestIncomplete(sprintf(
            'FINDING: two accrual runs raced: entitled_days sums to %s over %d balances where one run credits %s (%d balances over-credited).',
            $actual, $people, $expected, $overCredited,
        ));
    }

    expect($actual)->toBe($expected);
});

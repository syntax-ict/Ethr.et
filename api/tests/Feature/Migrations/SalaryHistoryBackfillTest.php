<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ApplyDuePersonnelActionsJob;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeSalaryHistory;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\SalaryInForce;
use App\Services\PersonnelActionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The migration that creates employee_salary_history also replays what the
 * database remembers: personnel actions, and the audit log's direct edits and
 * contracts, in the order they were recorded. The table already exists in the
 * test database, so each test drops it and runs the migration again over data
 * built with raw inserts, the way a database from before the release looks.
 */
function salaryHistoryMigration(): object
{
    return require database_path('migrations/2026_11_20_000001_create_employee_salary_history.php');
}

function backfilledEmployee(int $tenantId, int $salary, string $hired = '2025-01-01'): Employee
{
    return Employee::factory()->create(['tenant_id' => $tenantId, 'salary_cents' => $salary, 'hire_date' => $hired]);
}

function storedAction(Employee $employee, string $date, array $changes, ?array $pending = null, bool $applied = true, bool $temporary = false, string $recorded = '2026-01-01 00:00:00'): int
{
    return DB::table('personnel_actions')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'type' => 'promotion',
        'effective_date' => $date,
        'changes' => json_encode($changes),
        'pending_values' => $pending === null ? null : json_encode($pending),
        'is_temporary' => $temporary,
        'applied_at' => $applied ? $recorded : null,
        'created_at' => $recorded,
        'updated_at' => $recorded,
    ]);
}

function storedEdit(Employee $employee, int $before, int $after, string $recorded): int
{
    return DB::table('audit_log')->insertGetId([
        'tenant_id' => $employee->tenant_id,
        'action' => 'employee.updated',
        'auditable_type' => Employee::class,
        'auditable_id' => $employee->id,
        'payload' => json_encode(['before' => ['salary_cents' => $before], 'after' => ['salary_cents' => $after]]),
        'created_at' => $recorded,
    ]);
}

/** @return list<array{int, string, string, int|null}> */
function backfilled(Employee $employee): array
{
    return EmployeeSalaryHistory::query()
        ->where('employee_id', $employee->id)
        ->orderBy('effective_date')
        ->orderBy('id')
        ->get()
        ->map(fn (EmployeeSalaryHistory $r) => [$r->salary_cents, $r->effective_date->toDateString(), $r->source, $r->source_id])
        ->all();
}

function rebuildHistory(): void
{
    Schema::dropIfExists('employee_salary_history');
    salaryHistoryMigration()->up();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-20 09:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('gives an employee with no actions one row, at the hire date', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 900_000);

    rebuildHistory();

    expect(backfilled($employee))->toBe([[900_000, '2025-01-01', 'backfill', null]]);
});

it('rebuilds the trail from two applied salary actions', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_200_000);
    $first = storedAction($employee, '2025-07-01', ['salary' => ['from' => 900_000, 'to' => 1_000_000]], recorded: '2025-07-01 08:00:00');
    $second = storedAction($employee, '2026-03-01', ['salary' => ['from' => 1_000_000, 'to' => 1_200_000]], recorded: '2026-03-01 08:00:00');
    // Neither of these moves pay: a transfer, and a temporary acting allowance.
    storedAction($employee, '2025-09-01', ['department' => ['from' => 'A', 'to' => 'B']]);
    storedAction($employee, '2026-05-01', ['salary' => ['from' => 1_200_000, 'to' => 1_900_000]], temporary: true);

    rebuildHistory();

    expect(backfilled($employee))->toBe([
        [900_000, '2025-01-01', 'backfill', null],
        [1_000_000, '2025-07-01', 'personnel_action', $first],
        [1_200_000, '2026-03-01', 'personnel_action', $second],
    ]);
});

it('writes a future-dated row for a pending action', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_000_000);
    $pending = storedAction(
        $employee,
        '2026-12-01',
        ['salary' => ['from' => 1_000_000, 'to' => 1_400_000]],
        ['salary_cents' => 1_400_000],
        applied: false,
    );

    rebuildHistory();

    expect(backfilled($employee))->toBe([
        [1_000_000, '2025-01-01', 'backfill', null],
        [1_400_000, '2026-12-01', 'personnel_action', $pending],
    ]);
});

it('adds a row for today when the salary differs from the trail', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_300_000);
    $action = storedAction($employee, '2025-07-01', ['salary' => ['from' => 900_000, 'to' => 1_000_000]], recorded: '2025-07-01 08:00:00');

    rebuildHistory();

    expect(backfilled($employee))->toBe([
        [900_000, '2025-01-01', 'backfill', null],
        [1_000_000, '2025-07-01', 'personnel_action', $action],
        [1_300_000, '2026-11-20', 'backfill', null],
    ]);
});

it('dates a direct edit known only from the audit log at the day it was made', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_100_000);
    $audit = storedEdit($employee, 900_000, 1_100_000, '2026-09-10 10:00:00');

    rebuildHistory();

    expect(backfilled($employee))->toBe([
        [900_000, '2025-01-01', 'backfill', null],
        [1_100_000, '2026-09-10', 'edit', $audit],
    ]);
});

it('lets a back-dated action recorded after a later edit win', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_200_000);
    storedEdit($employee, 900_000, 1_000_000, '2026-11-01 10:00:00');
    $action = storedAction($employee, '2026-10-20', ['salary' => ['from' => 1_000_000, 'to' => 1_200_000]], recorded: '2026-11-10 08:00:00');

    rebuildHistory();

    $rows = EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->orderBy('id')->get();
    $edit = $rows->firstWhere('source', 'edit');
    $won = $rows->where('source', 'personnel_action')->firstWhere('source_id', $action);

    expect($edit->superseded_by)->toBe($won->id)
        ->and(SalaryInForce::on($employee->fresh(), Carbon::parse('2026-11-30'))->salary_cents)->toBe(1_200_000)
        ->and($rows->filter(fn (EmployeeSalaryHistory $r) => $r->effective_date->toDateString() === '2026-11-20')->count())->toBe(0);
});

it('never lets an audit edit replace an action in the replay, as the writer would not', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_000_000);
    $action = storedAction($employee, '2026-11-15', ['salary' => ['from' => 900_000, 'to' => 1_300_000]], recorded: '2026-11-15 08:00:00');
    storedEdit($employee, 1_300_000, 1_000_000, '2026-11-18 10:00:00');
    $edit = DB::table('audit_log')->where('action', 'employee.updated')->value('id');

    rebuildHistory();

    $rows = EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->orderBy('id')->get();
    $promotion = $rows->where('source', 'personnel_action')->firstWhere('source_id', $action);

    expect($promotion->superseded_by)->toBeNull()
        ->and($rows->firstWhere('source_id', $edit)->superseded_by)->toBeNull();
});

it('keeps a due but unapplied action, so applying it leaves the record and the row at its figure', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_100_000);
    $pending = storedAction(
        $employee,
        '2026-11-15',
        ['salary' => ['from' => 1_000_000, 'to' => 1_400_000]],
        ['salary_cents' => 1_400_000],
        applied: false,
        recorded: '2026-11-01 08:00:00',
    );

    rebuildHistory();

    expect(backfilled($employee))->toBe([
        [1_000_000, '2025-01-01', 'backfill', null],
        [1_100_000, '2026-11-14', 'backfill', null],
        [1_400_000, '2026-11-15', 'personnel_action', $pending],
    ]);

    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_400_000)
        ->and(SalaryInForce::on($employee->fresh(), Carbon::parse('2026-11-20'))->salary_cents)->toBe(1_400_000);
});

it('includes an employee who has been removed, and writes each under its own tenant', function () {
    $mine = createTenant();
    $other = createTenant();
    $removed = backfilledEmployee($mine->id, 700_000);
    $removed->delete();
    $theirs = backfilledEmployee($other->id, 600_000);

    rebuildHistory();

    $rows = DB::table('employee_salary_history')->orderBy('employee_id')->get(['tenant_id', 'employee_id', 'salary_cents']);
    expect($rows->all())->toEqual([
        (object) ['tenant_id' => $mine->id, 'employee_id' => $removed->id, 'salary_cents' => 700_000],
        (object) ['tenant_id' => $other->id, 'employee_id' => $theirs->id, 'salary_cents' => 600_000],
    ]);
});

it('writes nothing for an employee with no salary', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 0);

    rebuildHistory();

    expect(backfilled($employee))->toBe([]);
});

it('does not refuse a run calculated before the release, whatever happened to the salary since', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = backfilledEmployee($tenant->id, 1_000_000);

    $run = app(PayrollEngine::class)
        ->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $user->id)->run;

    // The entry as release 1.8 wrote it, with the salary the column held then.
    $entry = $run->entries()->sole();
    $log = $entry->calculation_log;
    $log['version'] = '1.8';
    $log['inputs']['basic_salary_cents'] = 1_000_000;
    DB::table('payroll_entries')->where('id', $entry->id)->update(['calculation_log' => json_encode($log)]);

    $this->travelTo(Carbon::parse('2026-11-21 09:00:00', 'UTC'));
    $employee->update(['salary_cents' => 1_100_000]);
    rebuildHistory();

    expect(backfilled($employee))->toBe([[1_100_000, '2025-01-01', 'backfill', null]]);

    $this->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('can be run twice without writing a row twice', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_000_000);
    $action = storedAction($employee, '2025-07-01', ['salary' => ['from' => 900_000, 'to' => 1_000_000]], recorded: '2025-07-01 08:00:00');

    rebuildHistory();
    $first = backfilled($employee);
    salaryHistoryMigration()->up();

    expect(backfilled($employee))->toBe($first)
        ->and($first)->toBe([
            [900_000, '2025-01-01', 'backfill', null],
            [1_000_000, '2025-07-01', 'personnel_action', $action],
        ]);
});

it('refills only the employee whose rows are missing when it is run again', function () {
    $tenant = createTenant();
    $kept = backfilledEmployee($tenant->id, 1_000_000);
    $lost = backfilledEmployee($tenant->id, 700_000);

    rebuildHistory();
    EmployeeSalaryHistory::query()->where('employee_id', $lost->id)->delete();
    EmployeeSalaryHistory::query()->where('employee_id', $kept->id)->update(['salary_cents' => 1_234_000]);

    salaryHistoryMigration()->up();

    expect(backfilled($lost))->toBe([[700_000, '2025-01-01', 'backfill', null]])
        ->and(backfilled($kept))->toBe([[1_234_000, '2025-01-01', 'backfill', null]]);
});

it('lets a raise back-dated before the release day beat the reconciliation row', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = backfilledEmployee($tenant->id, 1_300_000);
    storedAction($employee, '2025-07-01', ['salary' => ['from' => 900_000, 'to' => 1_000_000]], recorded: '2025-07-01 08:00:00');

    rebuildHistory();
    expect(backfilled($employee))->toContain([1_300_000, '2026-11-20', 'backfill', null]);

    $this->travelTo(Carbon::parse('2026-12-03 09:00:00', 'UTC'));
    app(PersonnelActionService::class)->record($employee->fresh(), [
        'type' => 'promotion', 'effective_date' => '2026-11-10', 'new_salary_cents' => 1_500_000,
    ], $user->id);

    expect(SalaryInForce::on($employee->fresh(), Carbon::parse('2026-12-31'))->salary_cents)->toBe(1_500_000)
        ->and($employee->fresh()->salary_cents)->toBe(1_500_000);

    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_500_000);
});

it('finishes an employee fully when a live write came in before their chunk was reached', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = backfilledEmployee($tenant->id, 1_000_000);
    $applied = storedAction($employee, '2025-07-01', ['salary' => ['from' => 900_000, 'to' => 1_000_000]], recorded: '2025-07-01 08:00:00');

    rebuildHistory();
    EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->delete();

    $live = app(PersonnelActionService::class)->record($employee->fresh(), [
        'type' => 'promotion', 'effective_date' => '2026-12-15', 'new_salary_cents' => 1_400_000,
    ], $user->id);

    salaryHistoryMigration()->up();
    $first = backfilled($employee);

    expect($first)->toBe([
        [900_000, '2025-01-01', 'backfill', null],
        [1_000_000, '2025-07-01', 'personnel_action', $applied],
        [1_400_000, '2026-12-15', 'personnel_action', $live->id],
    ]);

    salaryHistoryMigration()->up();

    expect(backfilled($employee))->toBe($first);
});

it('writes a future row for a contract that was recorded before the release and has not started', function () {
    $tenant = createTenant();
    $employee = backfilledEmployee($tenant->id, 1_000_000);
    $future = EmployeeContract::create([
        'employee_id' => $employee->id, 'contract_type' => 'permanent', 'start_date' => '2026-12-01',
        'salary_cents' => 1_800_000, 'status' => 'active',
    ]);
    // Ended before it started: it never takes effect.
    EmployeeContract::create([
        'employee_id' => $employee->id, 'contract_type' => 'permanent', 'start_date' => '2026-12-10',
        'ended_at' => '2026-11-01', 'salary_cents' => 2_500_000, 'status' => 'terminated_early',
    ]);

    rebuildHistory();
    $first = backfilled($employee);
    salaryHistoryMigration()->up();

    expect($first)->toBe([
        [1_000_000, '2025-01-01', 'backfill', null],
        [1_800_000, '2026-12-01', 'contract', $future->id],
    ])->and(backfilled($employee))->toBe($first);
});

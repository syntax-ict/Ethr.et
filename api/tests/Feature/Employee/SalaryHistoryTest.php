<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ApplyDuePersonnelActionsJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeSalaryHistory;
use App\Services\CurrentTenant;
use App\Services\EmployeeContractService;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\SalaryInForce;
use App\Services\PersonnelActionService;
use App\Support\SalaryChangeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Every way a salary can change leaves exactly one history row, dated by the
 * writer and not by the day the job happened to run (docs/audit/BASELINE.md
 * section 25w). The Employee model writes it; the dated writers say when.
 */

function historyOf(Employee $employee): array
{
    return EmployeeSalaryHistory::query()
        ->where('employee_id', $employee->id)
        ->orderBy('id')
        ->get()
        ->map(fn (EmployeeSalaryHistory $row) => [
            $row->salary_cents,
            $row->effective_date->toDateString(),
            $row->source,
            $row->source_id,
        ])
        ->all();
}

beforeEach(function () {
    // 09:00 in Addis Ababa on 5 November 2026.
    $this->travelTo(Carbon::parse('2026-11-05 06:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dates the salary an employee is created with at the hire date', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    expect(historyOf($employee))->toBe([[900_000, '2025-03-01', 'hire', null]]);
});

it('writes no row for an employee created without a salary', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0]);

    expect(historyOf($employee))->toBe([]);
});

it('writes no second row when the same instance is saved again for another reason', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);
    $employee->update(['name' => 'Renamed']);

    expect(historyOf($employee))->toHaveCount(1);
});

it('dates a direct salary edit today', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_100_000])->assertOk();

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [1_100_000, '2026-11-05', 'edit', null],
    ]);
});

it('dates a personnel action applied now at its own effective date', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $action = app(PersonnelActionService::class)->record($employee, [
        'type' => 'promotion', 'effective_date' => '2026-10-10', 'new_salary_cents' => 1_200_000,
    ], $user->id);

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [1_200_000, '2026-10-10', 'personnel_action', $action->id],
    ]);
});

it('writes a scheduled action row when it is recorded, none when it applies, and removes it when it is cancelled', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);
    $service = app(PersonnelActionService::class);

    $kept = $service->record($employee, ['type' => 'promotion', 'effective_date' => '2026-11-10', 'new_salary_cents' => 1_200_000], $user->id);

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [1_200_000, '2026-11-10', 'personnel_action', $kept->id],
    ]);

    $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_200_000)
        ->and(historyOf($employee))->toHaveCount(2);

    $this->travelTo(Carbon::parse('2026-11-11 06:00:00', 'UTC'));
    $withdrawn = $service->record($employee->fresh(), ['type' => 'promotion', 'effective_date' => '2026-12-01', 'new_salary_cents' => 1_500_000], $user->id);

    expect(historyOf($employee))->toHaveCount(3);

    $service->cancel($withdrawn, $employee->fresh());

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [1_200_000, '2026-11-10', 'personnel_action', $kept->id],
    ]);
});

it('dates a contract that has started at its start date', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $contract = app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent', 'start_date' => '2026-10-01', 'salary_cents' => 1_300_000,
    ], $user->id);

    expect($employee->fresh()->salary_cents)->toBe(1_300_000)
        ->and(historyOf($employee))->toBe([
            [900_000, '2025-03-01', 'hire', null],
            [1_300_000, '2026-10-01', 'contract', $contract->id],
        ]);
});

it('writes a future-dated row for a contract that has not started and leaves the salary alone', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $contract = app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent', 'start_date' => '2026-12-01', 'salary_cents' => 1_300_000,
    ], $user->id);

    expect($employee->fresh()->salary_cents)->toBe(900_000)
        ->and(historyOf($employee))->toBe([
            [900_000, '2025-03-01', 'hire', null],
            [1_300_000, '2026-12-01', 'contract', $contract->id],
        ]);
});

it('removes the row of a contract that is ended before it starts, and keeps one that had started', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);
    $service = app(EmployeeContractService::class);

    $started = $service->create($employee, ['contract_type' => 'permanent', 'start_date' => '2026-10-01', 'salary_cents' => 1_300_000], $user->id);
    $future = $service->create($employee->fresh(), ['contract_type' => 'permanent', 'start_date' => '2026-12-01', 'salary_cents' => 1_600_000], $user->id);

    $service->end($future, ['status' => 'terminated_early', 'ended_at' => '2026-11-05']);

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [1_300_000, '2026-10-01', 'contract', $started->id],
    ]);

    // An ended_at typed before the start of a contract already in force takes nothing away.
    $service->end($started, ['status' => 'terminated_early', 'ended_at' => '2026-09-01']);

    expect(historyOf($employee))->toHaveCount(2);
});

it('dates an imported employee at the row hire date, as an import', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'salary-history',
        'rows' => [['name' => 'Semira Aragaw Sisay', 'hire_date' => '2026-01-05', 'salary_cents' => 800_000]],
    ])->assertSuccessful()->assertJsonPath('created', 1);

    $employee = Employee::query()->where('tenant_id', $tenant->id)->sole();

    expect(historyOf($employee))->toBe([[800_000, '2026-01-05', 'import', null]]);
});

it('does not let a date leak into the next save, even when the block throws', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    SalaryChangeContext::during('2026-01-01', 'contract', 7, fn () => $employee->update(['salary_cents' => 950_000]));

    try {
        SalaryChangeContext::during('2026-02-02', 'contract', 8, function () {
            throw new RuntimeException('stop');
        });
    } catch (RuntimeException) {
    }

    expect(SalaryChangeContext::current())->toBeNull();

    $employee->update(['salary_cents' => 1_000_000]);

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [950_000, '2026-01-01', 'contract', 7],
        [1_000_000, '2026-11-05', 'edit', null],
    ]);
});

it('keeps the tenant of the employee when no tenant is resolved', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);
    app(CurrentTenant::class)->forget();

    SalaryChangeContext::during('2026-10-01', 'edit', null, fn () => $employee->forceFill(['salary_cents' => 910_000])->save());
    SalaryChangeContext::during('2025-03-01', 'edit', null, fn () => $employee->forceFill(['salary_cents' => 920_000])->save());

    // The later-recorded, earlier-dated row replaced the October one, with no tenant resolved.
    $superseded = DB::table('employee_salary_history')->where('employee_id', $employee->id)->whereNotNull('superseded_by')->get();

    app(CurrentTenant::class)->set($tenant);

    $row = EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->orderByDesc('id')->first();
    expect($row?->salary_cents)->toBe(920_000)
        ->and($row?->tenant_id)->toBe($tenant->id)
        ->and($superseded)->toHaveCount(1)
        ->and($superseded[0]->salary_cents)->toBe(910_000);
});

it('brings the record up to a future contract on its start date, once', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $contract = app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent', 'start_date' => '2026-11-10', 'salary_cents' => 1_300_000,
    ], $user->id);

    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);
    expect($employee->fresh()->salary_cents)->toBe(900_000);

    $this->travelTo(Carbon::parse('2026-11-10 06:00:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_300_000)
        ->and(EmployeeSalaryHistory::query()->where('source', 'contract')->where('source_id', $contract->id)->count())->toBe(1)
        ->and(historyOf($employee))->toHaveCount(2)
        ->and(AuditLog::where('action', 'employee.salary_in_force_applied')->count())->toBe(1);

    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_300_000)
        ->and(historyOf($employee))->toHaveCount(2)
        ->and(AuditLog::where('action', 'employee.salary_in_force_applied')->count())->toBe(1);
});

it('lets a change dated earlier but recorded later replace an edit, and the sync leaves it alone', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2025-03-01']);

    $employee->update(['salary_cents' => 1_100_000]);

    $this->travelTo(Carbon::parse('2026-12-03 06:00:00', 'UTC'));
    app(PersonnelActionService::class)->record($employee->fresh(), [
        'type' => 'promotion', 'effective_date' => '2026-11-15', 'new_salary_cents' => 1_300_000,
    ], $user->id);

    $employee = $employee->fresh();
    expect(SalaryInForce::on($employee, Carbon::parse('2026-11-30'))->salary_cents)->toBe(1_300_000)
        ->and(SalaryInForce::on($employee, Carbon::parse('2026-12-31'))->salary_cents)->toBe(1_300_000)
        ->and(EmployeeSalaryHistory::query()->where('source', 'edit')->sole()->superseded_by)->not->toBeNull();

    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_300_000);
});

it('does not let a back-dated edit erase a promotion that is already in force', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2025-03-01']);

    $action = app(PersonnelActionService::class)->record($employee, [
        'type' => 'promotion', 'effective_date' => '2026-11-15', 'new_salary_cents' => 1_300_000,
    ], $user->id);

    SalaryChangeContext::during('2026-11-10', 'edit', null, fn () => $employee->fresh()->update(['salary_cents' => 1_200_000]));

    $employee = $employee->fresh();
    $promotion = EmployeeSalaryHistory::query()->where('source', 'personnel_action')->where('source_id', $action->id)->sole();

    expect($promotion->superseded_by)->toBeNull()
        ->and(SalaryInForce::on($employee, Carbon::parse('2026-11-12'))->salary_cents)->toBe(1_200_000)
        ->and(SalaryInForce::on($employee, Carbon::parse('2026-11-30'))->salary_cents)->toBe(1_300_000);
});

it('does not let a back-dated edit erase a contract that has started', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2025-03-01']);

    $contract = app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent', 'start_date' => '2026-11-15', 'salary_cents' => 1_300_000,
    ], $user->id);

    SalaryChangeContext::during('2026-11-10', 'edit', null, fn () => $employee->fresh()->update(['salary_cents' => 1_200_000]));

    $row = EmployeeSalaryHistory::query()->where('source', 'contract')->where('source_id', $contract->id)->sole();

    expect($row->superseded_by)->toBeNull()
        ->and(SalaryInForce::on($employee->fresh(), Carbon::parse('2026-11-30'))->salary_cents)->toBe(1_300_000);
});

it('lets a back-dated edit replace an earlier edit, as before', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2025-03-01']);

    $employee->update(['salary_cents' => 1_100_000]);
    SalaryChangeContext::during('2026-11-10', 'edit', null, fn () => $employee->fresh()->update(['salary_cents' => 1_200_000]));

    expect(EmployeeSalaryHistory::query()->where('salary_cents', 1_100_000)->sole()->superseded_by)->not->toBeNull()
        ->and(SalaryInForce::on($employee->fresh(), Carbon::parse('2026-11-30'))->salary_cents)->toBe(1_200_000);
});

it('does not let a back-dated change replace a scheduled one', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2025-03-01']);

    $scheduled = app(PersonnelActionService::class)->record($employee, [
        'type' => 'promotion', 'effective_date' => '2026-12-15', 'new_salary_cents' => 1_500_000,
    ], $user->id);

    SalaryChangeContext::during('2026-11-10', 'edit', null, fn () => $employee->fresh()->update(['salary_cents' => 1_200_000]));

    $row = EmployeeSalaryHistory::query()->where('source_id', $scheduled->id)->sole();
    expect($row->superseded_by)->toBeNull()
        ->and(SalaryInForce::on($employee->fresh(), Carbon::parse('2026-12-31'))->salary_cents)->toBe(1_500_000);
});

it('reads the column only for an employee with no rows, and the earliest row for a month before it', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2026-10-10']);
    SalaryChangeContext::during('2026-11-01', 'edit', null, fn () => $employee->update(['salary_cents' => 1_300_000]));
    $engine = app(PayrollEngine::class);
    $september = [Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')];

    // Not today's figure: the first row covers the months before it.
    expect($engine->salaryFor($employee->fresh(), ...$september)['cents'])->toBe(1_000_000);

    EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->delete();

    expect($engine->salaryFor($employee->fresh(), ...$september)['cents'])->toBe(1_300_000);
});

it('pays nothing, then the contract, when the row in force is zero', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2025-01-01']);
    $employee->update(['salary_cents' => 0]);
    $engine = app(PayrollEngine::class);
    $period = [Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')];

    expect($engine->salaryFor($employee->fresh(), ...$period)['cents'])->toBe(0);

    EmployeeContract::create([
        'employee_id' => $employee->id, 'contract_type' => 'permanent', 'start_date' => '2026-05-01',
        'salary_cents' => 1_700_000, 'status' => 'active',
    ]);

    expect($engine->salaryFor($employee->fresh(), ...$period)['cents'])->toBe(1_700_000);
});

it('leaves the employee unchanged and writes no row when the history row cannot be written', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    EmployeeSalaryHistory::creating(function (): never {
        throw new RuntimeException('history unavailable');
    });

    try {
        expect(fn () => $employee->update(['salary_cents' => 950_000]))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.EmployeeSalaryHistory::class);
    }

    expect($employee->fresh()->salary_cents)->toBe(900_000)
        ->and(historyOf($employee))->toHaveCount(1);

    EmployeeSalaryHistory::creating(function (): never {
        throw new RuntimeException('history unavailable');
    });

    try {
        expect(fn () => Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800_000, 'name' => 'Nobody Saved']))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.EmployeeSalaryHistory::class);
    }

    expect(Employee::query()->where('name', 'Nobody Saved')->exists())->toBeFalse();
});

it('dates a direct edit at the effective date the form sent, never before the hire date', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_100_000, 'salary_effective_date' => '2026-10-01'])->assertOk();
    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_200_000, 'salary_effective_date' => '2020-01-01'])->assertOk();

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [1_100_000, '2026-10-01', 'edit', null],
        [1_200_000, '2025-03-01', 'edit', null],
    ]);

    // The second edit is dated at the hire date, so it replaced the October one:
    // October is paid the corrected figure.
    $employee = $employee->fresh();
    expect(EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->whereDate('effective_date', '2026-10-01')->sole()->superseded_by)->not->toBeNull()
        ->and(SalaryInForce::on($employee, Carbon::parse('2026-10-31'))->salary_cents)->toBe(1_200_000)
        ->and(app(PayrollEngine::class)->salaryFor($employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['cents'])->toBe(1_200_000);
});

it('ignores an effective date when the salary does not change, and refuses one in the future', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $this->putJson("/api/v1/employees/{$employee->public_id}", ['name' => 'Renamed', 'salary_cents' => 900_000, 'salary_effective_date' => '2026-10-01'])->assertOk();
    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 950_000, 'salary_effective_date' => '2026-12-25'])->assertStatus(422);

    expect(historyOf($employee))->toBe([[900_000, '2025-03-01', 'hire', null]]);
});

it('dates an edit made before a future hire at the hire date, so the hire row does not win', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2026-12-01']);

    $employee->update(['salary_cents' => 1_200_000]);

    expect(historyOf($employee))->toBe([
        [1_000_000, '2026-12-01', 'hire', null],
        [1_200_000, '2026-12-01', 'edit', null],
    ])
        ->and(app(PayrollEngine::class)->salaryFor($employee->fresh(), Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'))['cents'])->toBe(1_200_000);

    $this->travelTo(Carbon::parse('2026-12-02 06:00:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(1_200_000);
});

it('keeps a raise ahead of the hire row when the hire date is corrected to a later day', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);
    SalaryChangeContext::during('2025-09-01', 'edit', null, fn () => $employee->update(['salary_cents' => 950_000]));

    $employee->update(['hire_date' => '2025-12-01']);

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [950_000, '2025-09-01', 'edit', null],
    ])
        ->and(app(PayrollEngine::class)->salaryFor($employee->fresh(), Carbon::parse('2025-12-01'), Carbon::parse('2025-12-31'))['cents'])->toBe(950_000);

    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect($employee->fresh()->salary_cents)->toBe(950_000);
});

it('pays the earliest row for the months a hire date corrected to an earlier day gains', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $employee->update(['hire_date' => '2025-01-01']);

    expect(app(PayrollEngine::class)->salaryFor($employee->fresh(), Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31'))['cents'])->toBe(900_000);
});

it('keeps an edit clamped to the hire date ahead of the hire row when the hire date moves later', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_200_000, 'salary_effective_date' => '2020-01-01'])->assertOk();
    $employee->fresh()->update(['hire_date' => '2025-12-01']);

    expect(app(PayrollEngine::class)->salaryFor($employee->fresh(), Carbon::parse('2025-12-01'), Carbon::parse('2025-12-31'))['cents'])->toBe(1_200_000);
});

it('leaves no month at zero when a first salary is followed by an earlier hire date', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0, 'hire_date' => '2026-09-01']);

    $employee->update(['salary_cents' => 1_000_000]);
    $employee->update(['hire_date' => '2026-08-01']);

    $engine = app(PayrollEngine::class);
    expect($engine->salaryFor($employee->fresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))['cents'])->toBe(1_000_000)
        ->and($engine->salaryFor($employee->fresh(), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['cents'])->toBe(1_000_000);
});

it('pays a contract that starts before a future hire from the hire date', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2026-12-05']);

    app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent', 'start_date' => '2026-12-01', 'salary_cents' => 1_800_000,
    ], null);

    expect(app(PayrollEngine::class)->salaryFor($employee->fresh(), Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'))['cents'])->toBe(1_800_000);
});

it('uses the organisation day, not the UTC day, for the date of a change and for the effective date check', function () {
    // 22:00 UTC on 20 November is 01:00 on 21 November in Addis Ababa.
    $this->travelTo(Carbon::parse('2026-11-20 22:00:00', 'UTC'));
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2025-03-01']);

    $employee->update(['salary_cents' => 950_000]);

    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_000_000, 'salary_effective_date' => '2026-11-21'])->assertOk();
    $this->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_100_000, 'salary_effective_date' => '2026-11-22'])->assertStatus(422);

    expect(historyOf($employee))->toBe([
        [900_000, '2025-03-01', 'hire', null],
        [950_000, '2026-11-21', 'edit', null],
        [1_000_000, '2026-11-21', 'edit', null],
    ]);
});

it('dates a first salary set after creation at the hire date', function () {
    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0, 'hire_date' => '2026-09-01']);

    $employee->update(['salary_cents' => 1_000_000]);

    expect(historyOf($employee))->toBe([[1_000_000, '2026-09-01', 'edit', null]]);
});

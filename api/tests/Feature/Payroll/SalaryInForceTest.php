<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\PayrollRunRefused;
use App\Jobs\ProcessPayrollJob;
use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\CurrentTenant;
use App\Services\EmployeeContractService;
use App\Services\Payroll\PayrollEngine;
use App\Services\PersonnelActionService;
use App\Support\SalaryChangeContext;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\DB;

/**
 * Found 2026-11-20 (docs/audit/BASELINE.md §25w). Payroll read the employee as
 * they were when the job ran, so a month reprocessed later paid today's
 * salary and a raise back-dated into a month was never paid for it. A run now
 * pays the salary in force on its period's last day.
 */
function salaryTenant(): array
{
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 1_000_000,
        'hire_date' => '2024-01-01',
    ]);

    return [$tenant, $user, $employee];
}

function runFor(int $tenantId, int $userId, string $start, string $end): PayrollRun
{
    return app(PayrollEngine::class)->process($tenantId, Carbon::parse($start), Carbon::parse($end), $userId)->run;
}

function entryOf(PayrollRun $run, Employee $employee): PayrollEntry
{
    return PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
}

function raise(Employee $employee, int $userId, string $effective, int $cents): void
{
    app(PersonnelActionService::class)->record($employee, [
        'type' => 'promotion',
        'effective_date' => $effective,
        'new_salary_cents' => $cents,
    ], $userId);
}

afterEach(function () {
    IlluminateCarbon::setTestNow();
});

it('pays a reprocessed month the salary it had, not the one it has now', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();

    $october = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    expect(entryOf($october, $employee)->basic_salary_cents)->toBe(1_000_000);

    app(PayrollEngine::class)->void($october, $user->id, 'redo');
    raise($employee, $user->id, '2026-11-01', 1_500_000);
    expect($employee->fresh()->salary_cents)->toBe(1_500_000);

    $again = app(PayrollEngine::class)->reprocess($october->fresh(), $user->id)->run;
    app(PayrollEngine::class)->runEntries($again);

    $entry = entryOf($again, $employee);
    expect($entry->basic_salary_cents)->toBe(1_000_000)
        ->and($entry->calculation_log['version'])->toBe('1.11')
        ->and($entry->calculation_log['inputs']['salary_source'])->toBe('history')
        ->and($entry->calculation_log['inputs']['salary_changed_in_period'])->toBeFalse();
});

it('pays a raise back-dated into a voided month when the month is reprocessed', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();

    $october = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    app(PayrollEngine::class)->void($october, $user->id, 'redo');
    raise($employee, $user->id, '2026-10-10', 1_500_000);

    $again = app(PayrollEngine::class)->reprocess($october->fresh(), $user->id)->run;
    app(PayrollEngine::class)->runEntries($again);

    $entry = entryOf($again, $employee);
    expect($entry->basic_salary_cents)->toBe(1_500_000)
        ->and($entry->calculation_log['inputs']['salary_history_source'])->toBe('personnel_action')
        ->and($entry->calculation_log['inputs']['salary_effective_date'])->toBe('2026-10-10')
        ->and($entry->calculation_log['inputs']['salary_changed_in_period'])->toBeTrue();
});

it('pays a change scheduled for the last day of the period before the scheduled action has applied', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-10-20 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();

    raise($employee, $user->id, '2026-10-31', 1_500_000);
    expect($employee->fresh()->salary_cents)->toBe(1_000_000);

    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    expect(entryOf($run, $employee)->basic_salary_cents)->toBe(1_500_000);
});

it('pays a future-dated contract from its start date and not before', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-10-20 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();

    app(EmployeeContractService::class)->create($employee, [
        'contract_type' => 'permanent',
        'start_date' => '2026-11-10',
        'salary_cents' => 1_800_000,
    ], $user->id);

    expect($employee->fresh()->salary_cents)->toBe(1_000_000);

    $october = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    $november = runFor($tenant->id, $user->id, '2026-11-01', '2026-11-30');

    expect(entryOf($october, $employee)->basic_salary_cents)->toBe(1_000_000)
        ->and(entryOf($november, $employee)->basic_salary_cents)->toBe(1_800_000);
});

it('previews the salary a run pays', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    raise($employee, $user->id, '2026-10-15', 1_500_000);

    $preview = app(PayrollEngine::class)->preview($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    expect($preview['entries'])->toHaveCount(1)
        ->and($preview['entries'][0]['attributes']['basic_salary_cents'])->toBe(1_500_000)
        ->and(entryOf($run, $employee)->basic_salary_cents)->toBe(1_500_000);
});

it('pays an employee with no history rows the salary on the record, as before', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->delete();

    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $entry = entryOf($run, $employee);
    expect($entry->basic_salary_cents)->toBe(1_000_000)
        ->and($entry->calculation_log['inputs']['salary_source'])->toBe('employee');
});

it('refuses to approve a run after a salary dated inside its period was recorded', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $this->travelTo(IlluminateCarbon::parse('2026-11-05 10:00:00', 'UTC'));
    raise($employee, $user->id, '2026-10-20', 1_500_000);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/inputs-changed-since-processed')
        ->assertJsonPath('detail', __('payroll.inputs_changed_since_processed'));

    expect($run->fresh()->status)->toBe('completed');
});

it('approves a run when the salary change is dated after its period', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $this->travelTo(IlluminateCarbon::parse('2026-11-05 10:00:00', 'UTC'));
    raise($employee, $user->id, '2026-11-01', 1_500_000);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();

    expect($run->fresh()->status)->toBe('approved');
});

it('pays the salary in force when the run is calculated by the queued job, with no tenant resolved beforehand', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    raise($employee, $user->id, '2026-10-15', 1_500_000);

    $run = app(PayrollEngine::class)->begin($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $user->id)->run;
    app(CurrentTenant::class)->forget();
    ProcessPayrollJob::dispatchSync($run->id, $tenant->id);

    app(CurrentTenant::class)->set($tenant);
    expect(entryOf($run->fresh(), $employee)->basic_salary_cents)->toBe(1_500_000);
});

it('refuses to approve a run whose scheduled raise was cancelled after it was calculated', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-25 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $service = app(PersonnelActionService::class);
    $action = $service->record($employee, ['type' => 'promotion', 'effective_date' => '2026-11-28', 'new_salary_cents' => 1_500_000], $user->id);

    $run = runFor($tenant->id, $user->id, '2026-11-01', '2026-11-30');
    expect(entryOf($run, $employee)->basic_salary_cents)->toBe(1_500_000);

    $this->travelTo(IlluminateCarbon::parse('2026-11-26 09:00:00', 'UTC'));
    $service->cancel($action, $employee->fresh());

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/inputs-changed-since-processed');

    app(PayrollEngine::class)->void($run, $user->id, 'raise cancelled');
    $again = app(PayrollEngine::class)->reprocess($run->fresh(), $user->id)->run;
    app(PayrollEngine::class)->runEntries($again);

    expect(entryOf($again, $employee)->basic_salary_cents)->toBe(1_000_000);
    test()->putJson("/api/v1/payroll/runs/{$again->public_id}/approve")->assertOk();
});

it('refuses to approve a run that paid a future contract which was then ended before it started', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-10-20 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $contracts = app(EmployeeContractService::class);
    $contract = $contracts->create($employee, ['contract_type' => 'permanent', 'start_date' => '2026-11-10', 'salary_cents' => 1_800_000], $user->id);

    $run = runFor($tenant->id, $user->id, '2026-11-01', '2026-11-30');
    expect(entryOf($run, $employee)->basic_salary_cents)->toBe(1_800_000);

    $contracts->end($contract, ['status' => 'terminated_early']);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/inputs-changed-since-processed');
});

it('approves a run when a direct salary edit is dated after the period end', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $employee->update(['salary_cents' => 1_400_000]);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('approves a run whose entries carry no salary input in their log', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $entry = entryOf($run, $employee);
    $log = $entry->calculation_log;
    $log['version'] = '1.8';
    unset($log['inputs']['basic_salary_cents']);
    DB::table('payroll_entries')->where('id', $entry->id)->update(['calculation_log' => json_encode($log)]);
    raise($employee, $user->id, '2026-10-20', 1_500_000);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('does not count a mid-period hire as a salary change', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user] = salaryTenant();
    $hired = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800_000, 'hire_date' => '2026-10-10']);

    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    expect(entryOf($run, $hired)->calculation_log['inputs']['salary_changed_in_period'])->toBeFalse();
});

it('pays a reprocessed earlier month the corrected figure when the edit is dated at the hire date', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();

    $october = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    expect(entryOf($october, $employee)->basic_salary_cents)->toBe(1_000_000);
    app(PayrollEngine::class)->void($october, $user->id, 'typo');

    test()->putJson("/api/v1/employees/{$employee->public_id}", ['salary_cents' => 1_200_000, 'salary_effective_date' => '2024-01-01'])->assertOk();

    $again = app(PayrollEngine::class)->reprocess($october->fresh(), $user->id)->run;
    app(PayrollEngine::class)->runEntries($again);

    expect(entryOf($again, $employee)->basic_salary_cents)->toBe(1_200_000);
});

it('refuses to approve a calculated 1.9 run whose salary moved before approval, and lets a 1.8 run through', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $employee->update(['salary_cents' => 1_300_000]);
    SalaryChangeContext::during('2026-10-12', 'edit', null, fn () => $employee->fresh()->update(['salary_cents' => 1_400_000]));

    expect(fn () => app(PayrollEngine::class)->approve($run, $user->id))->toThrow(PayrollRunRefused::class);

    $entry = entryOf($run, $employee);
    $log = $entry->calculation_log;
    $log['version'] = '1.8';
    DB::table('payroll_entries')->where('id', $entry->id)->update(['calculation_log' => json_encode($log)]);

    expect(app(PayrollEngine::class)->approve($run->fresh(), $user->id)->status)->toBe('approved');
});

it('refuses to approve a run when someone with a salary was hired into its period after it was calculated', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800_000, 'hire_date' => '2026-10-10']);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/inputs-changed-since-processed');

    expect($run->fresh()->status)->toBe('completed');
});

it('approves a run when the person added after it was calculated has no salary to pay', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0, 'hire_date' => '2026-10-10']);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('approves a complete run in which someone with no salary was skipped', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user] = salaryTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0, 'hire_date' => '2024-02-01']);
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('does not judge a run calculated before 1.9 on who is missing from it', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    $entry = entryOf($run, $employee);
    $log = $entry->calculation_log;
    $log['version'] = '1.8';
    DB::table('payroll_entries')->where('id', $entry->id)->update(['calculation_log' => json_encode($log)]);

    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800_000, 'hire_date' => '2026-10-10']);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('pays a corrected hire date its months, and a first salary set after creation from the hire date', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-20 09:00:00', 'UTC'));
    [$tenant, $user] = salaryTenant();
    $moved = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_100_000, 'hire_date' => '2026-12-01']);
    $late = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0, 'hire_date' => '2026-09-01']);

    $moved->update(['hire_date' => '2026-11-01']);
    $late->update(['salary_cents' => 1_300_000]);

    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    expect(entryOf($run, $late)->basic_salary_cents)->toBe(1_300_000);

    $november = runFor($tenant->id, $user->id, '2026-11-01', '2026-11-30');
    expect(entryOf($november, $moved)->basic_salary_cents)->toBe(1_100_000);
});

function leaveOn(Employee $employee, string $date): void
{
    Employee::query()->withoutGlobalScopes()->whereKey($employee->id)->where('tenant_id', $employee->tenant_id)->update([
        'status' => 'terminated',
        'termination_date' => $date,
    ]);
}

it('refuses to approve a run when a leaving date inside its period was recorded after it was calculated', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    leaveOn($employee, '2026-10-15');

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/inputs-changed-since-processed');

    expect($run->fresh()->status)->toBe('completed');
});

it('refuses to approve a run when a leaving date before its period was recorded after it was calculated', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    leaveOn($employee, '2026-09-15');

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/inputs-changed-since-processed');
});

it('approves a run when a leaving date after its period was recorded after it was calculated', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');

    leaveOn($employee, '2026-11-20');

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

it('approves a run when a leaving date after its period only moved to another day after it', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    [$tenant, $user, $employee] = salaryTenant();
    leaveOn($employee, '2026-11-10');
    $run = runFor($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    expect(entryOf($run, $employee)->calculation_log['inputs']['termination_date'])->toBe('2026-11-10');

    leaveOn($employee, '2026-12-05');

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
});

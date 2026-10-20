<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

// Blueprint assessment 2026-10-20, defect D3. The engine selected only hired,
// probation and confirmed employees, so someone who left on the 15th was paid
// nothing for the first half of their last month, while
// docs/payroll/ACCOUNTANT-SIGN-OFF.md §1 tells the accountant a mid-period
// termination is prorated.

/** Process June 2026 (30 days) and return the entry for `$employee`, if any. */
function finalPayEntry(int $tenantId, int $userId, Employee $employee): ?PayrollEntry
{
    $run = app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first();
}

function finalPayProration(PayrollEntry $entry): array
{
    return collect($entry->calculation_log['steps'])->firstWhere('step', 'proration');
}

it('pays a leaver for the days of the period they worked', function (EmployeeStatus $status) {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 600_000,
        'hire_date' => '2020-01-01',
        'status' => $status,
        'termination_date' => '2026-06-15',
    ]);

    $entry = finalPayEntry($tenant->id, $user->id, $employee);

    expect($entry)->not->toBeNull();
    expect(finalPayProration($entry)['proration_factor'])->toBe(15 / 30);
    expect($entry->basic_salary_cents)->toBe(300_000);
    expect($entry->calculation_log['inputs']['termination_date'])->toBe('2026-06-15');
})->with([
    'resigned' => EmployeeStatus::RESIGNED,
    'terminated' => EmployeeStatus::TERMINATED,
    'retired' => EmployeeStatus::RETIRED,
]);

it('prorates both ends for someone who joined and left in the same period', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 600_000,
        'hire_date' => '2026-06-11',
        'status' => EmployeeStatus::RESIGNED,
        'termination_date' => '2026-06-20',
    ]);

    $entry = finalPayEntry($tenant->id, $user->id, $employee);

    expect(finalPayProration($entry)['proration_factor'])->toBe(10 / 30);
    expect($entry->basic_salary_cents)->toBe(200_000);
});

it('pays an employee working their notice up to the termination date', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 600_000,
        'hire_date' => '2020-01-01',
        'status' => EmployeeStatus::CONFIRMED,
        'termination_date' => '2026-06-10',
    ]);

    expect(finalPayEntry($tenant->id, $user->id, $employee)->basic_salary_cents)->toBe(200_000);
});

it('leaves out someone who left before the period', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 600_000,
        'hire_date' => '2020-01-01',
        'status' => EmployeeStatus::RESIGNED,
        'termination_date' => '2026-05-31',
    ]);

    expect(finalPayEntry($tenant->id, $user->id, $employee))->toBeNull();
});

it('still pays a full month to someone whose termination date is after the period', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 600_000,
        'hire_date' => '2020-01-01',
        'status' => EmployeeStatus::CONFIRMED,
        'termination_date' => '2026-07-15',
    ]);

    $entry = finalPayEntry($tenant->id, $user->id, $employee);

    expect(finalPayProration($entry)['proration_factor'])->toEqual(1);
    expect($entry->basic_salary_cents)->toBe(600_000);
});

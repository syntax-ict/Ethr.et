<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Services\Payroll\AllowanceService;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

// A normal Gregorian month with no attendance (0 overtime) and no loans, so
// gross = basic + allowances exactly. Employees are hired long before the
// period to avoid mid-hire proration.
function runAllowancePayroll(int $tenantId, int $userId): PayrollEntry
{
    $run = app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

test('a fixed taxable allowance is added to gross and taxed', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000, // 5,000 ETB
        'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->fixed(100000)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Transport Allowance',
    ]);

    $entry = runAllowancePayroll($tenant->id, $user->id);

    expect($entry->gross_cents)->toBe(600000);              // 500,000 + 100,000
    expect($entry->income_tax_cents)->toBe(93500);          // tax on 600,000 (bracket 5)
    expect($entry->employee_pension_cents)->toBe(35000);    // 7% of basic 500,000
    expect($entry->net_cents)->toBe(471500);                // 600,000 - 93,500 - 35,000

    expect($entry->allowances)->toHaveCount(1);
    expect($entry->allowances[0]['name'])->toBe('Transport Allowance');
    expect($entry->allowances[0]['amount_cents'])->toBe(100000);
    expect($entry->allowances[0]['taxable'])->toBeTrue();
});

test('a percentage allowance is computed on basic salary', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->percentage(10)->create([
        'tenant_id' => $tenant->id,
        'name' => 'Position Allowance',
    ]);

    $entry = runAllowancePayroll($tenant->id, $user->id);

    expect($entry->allowances[0]['amount_cents'])->toBe(50000); // 10% of 500,000
    expect($entry->gross_cents)->toBe(550000);
});

test('a non-taxable allowance raises gross but not taxable income', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->fixed(100000)->nonTaxable()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Transport (non-taxable)',
    ]);

    $entry = runAllowancePayroll($tenant->id, $user->id);

    // Gross includes the allowance, but tax is computed on 500,000 (basic only).
    expect($entry->gross_cents)->toBe(600000);
    expect($entry->income_tax_cents)->toBe(69750);   // tax on 500,000 (bracket 4), not 600,000
    expect($entry->net_cents)->toBe(495250);         // 600,000 - 69,750 - 35,000

    $taxStep = collect($entry->calculation_log['steps'])->firstWhere('step', 'income_tax');
    expect($taxStep['taxable_amount_cents'])->toBe(500000);
    expect($taxStep['non_taxable_allowances_cents'])->toBe(100000);
});

test('a non-taxable allowance yields less tax than the same taxable allowance', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $service = app(AllowanceService::class);

    $taxableEmployee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->fixed(100000)->create(['tenant_id' => $tenant->id]);

    $resolved = $service->resolve($taxableEmployee, 500000);
    expect($resolved['taxable_cents'])->toBe(100000);
    expect($resolved['non_taxable_cents'])->toBe(0);
    expect($resolved['total_cents'])->toBe(100000);
});

test('percentage allowances round to whole cents', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 333333, 'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->percentage(7.5)->create(['tenant_id' => $tenant->id]);

    $resolved = app(AllowanceService::class)->resolve($employee, 333333);

    // 333333 * 7.5 / 100 = 24999.975 -> 25000 (half-up), integer.
    expect($resolved['items'][0]['amount_cents'])->toBe(25000);
    expect($resolved['items'][0]['amount_cents'])->toBeInt();
});

test('inactive allowance rules are ignored', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->fixed(100000)->inactive()->create(['tenant_id' => $tenant->id]);

    $entry = runAllowancePayroll($tenant->id, $user->id);

    expect($entry->allowances)->toBe([]);
    expect($entry->gross_cents)->toBe(500000);
});

test("another tenant's allowance rules do not apply", function () {
    $tenantA = createTenant();
    $tenantB = createTenant(['subdomain' => 'tenantb']);

    // Rule belongs to tenant A only.
    PayrollRule::factory()->fixed(100000)->create(['tenant_id' => $tenantA->id]);

    $employeeB = Employee::factory()->create([
        'tenant_id' => $tenantB->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);

    $resolved = app(AllowanceService::class)->resolve($employeeB, 500000);

    expect($resolved['items'])->toBe([]);
    expect($resolved['total_cents'])->toBe(0);
});

test('the calculation log records the allowance breakdown', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);

    PayrollRule::factory()->fixed(80000)->create(['tenant_id' => $tenant->id, 'name' => 'Housing']);
    PayrollRule::factory()->fixed(20000)->nonTaxable()->create(['tenant_id' => $tenant->id, 'name' => 'Hardship']);

    $entry = runAllowancePayroll($tenant->id, $user->id);

    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'allowances');

    expect($step)->not->toBeNull();
    expect($step['total_allowances_cents'])->toBe(100000);
    expect($step['taxable_allowances_cents'])->toBe(80000);
    expect($step['non_taxable_allowances_cents'])->toBe(20000);
    expect($step['items'])->toHaveCount(2);
});

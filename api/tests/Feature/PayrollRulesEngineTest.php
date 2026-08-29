<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Services\Payroll\LoanService;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PensionCalculator;
use App\Services\Payroll\TaxCalculator;
use Carbon\Carbon;

// ── Tax Calculator: Ethiopian Income Tax Brackets ──
// Proclamation No. 1395/2025, in force since 7 July 2025 (superseding
// No. 979/2016). All amounts in cents. Bands per monthly ETB:
//     0-2,000      (0-200000):        0%
//     2,001-4,000  (200001-400000):  15% - 30000
//     4,001-7,000  (400001-700000):  20% - 50000
//     7,001-10,000 (700001-1000000): 25% - 85000
//    10,001-14,000 (1000001-1400000):30% - 135000
//    14,001+       (1400001+):       35% - 205000
//
// The pre-amendment ladder and the effective-date switch between the two are
// covered in tests/Unit/TaxCalculatorTest.php; these cases pin the current one.

test('tax calculator: 0 ETB income', function () {
    $calc = new TaxCalculator;
    expect($calc->calculate(0))->toBe(0);
});

test('tax calculator: band 1 — 500 ETB (exempt)', function () {
    $calc = new TaxCalculator;
    expect($calc->calculate(50000))->toBe(0);
});

test('tax calculator: band 1 — 1000 ETB is exempt under 1395/2025', function () {
    $calc = new TaxCalculator;
    // Taxed 4000 cents under 979/2016; the threshold rose from 600 to 2,000 ETB.
    expect($calc->calculate(100000))->toBe(0);
});

test('tax calculator: band 2 — 2500 ETB', function () {
    $calc = new TaxCalculator;
    // 250000 * 15% - 30000 = 37500 - 30000 = 7500
    expect($calc->calculate(250000))->toBe(7500);
});

test('tax calculator: band 2 ceiling — 4000 ETB', function () {
    $calc = new TaxCalculator;
    // 400000 * 15% - 30000 = 60000 - 30000 = 30000
    expect($calc->calculate(400000))->toBe(30000);
});

test('tax calculator: band 3 — 6000 ETB', function () {
    $calc = new TaxCalculator;
    // 600000 * 20% - 50000 = 120000 - 50000 = 70000
    expect($calc->calculate(600000))->toBe(70000);
});

test('tax calculator: band 4 — 9000 ETB', function () {
    $calc = new TaxCalculator;
    // 900000 * 25% - 85000 = 225000 - 85000 = 140000
    expect($calc->calculate(900000))->toBe(140000);
});

test('tax calculator: band 6 — 15000 ETB', function () {
    $calc = new TaxCalculator;
    // 1500000 * 35% - 205000 = 525000 - 205000 = 320000
    expect($calc->calculate(1500000))->toBe(320000);
});

test('tax calculator: exact band 5/6 boundary — 14000.00 ETB stays in band 5', function () {
    $calc = new TaxCalculator;
    // 1400000 * 30% - 135000 = 420000 - 135000 = 285000
    expect($calc->calculate(1400000))->toBe(285000);
});

test('tax calculator: one cent over the boundary — 14000.01 ETB moves into band 6', function () {
    $calc = new TaxCalculator;
    // 1400001 * 35% - 205000 = 490000.35 - 205000 = 285000.35, rounds to 285000
    expect($calc->calculate(1400001))->toBe(285000);
});

test('tax calculator: the band boundary is continuous, not a cliff', function () {
    $calc = new TaxCalculator;

    $justBelow = $calc->calculate(1400000);
    $justAbove = $calc->calculate(1400001);

    // A one-cent raise must not produce a jump in tax owed.
    expect(abs($justAbove - $justBelow))->toBeLessThanOrEqual(1);
});

test('tax calculator: a mid-month raise crossing a band boundary taxes only the new gross, not a blend', function () {
    $calc = new TaxCalculator;

    // Employee starts the month at 900000 cents (band 4) and gets a raise to
    // 1500000 cents (band 6) mid-month. Payroll taxes the period's actual gross
    // taxable amount for that band — there is no proration of the tax
    // calculation itself across bands mid-period.
    $beforeRaise = $calc->calculate(900000);
    $afterRaise = $calc->calculate(1500000);

    expect($beforeRaise)->toBe(140000);
    expect($afterRaise)->toBe(320000);
    expect($afterRaise)->toBeGreaterThan($beforeRaise);
});

// ── Pension Calculator ──

test('pension calculates 7% employee and 11% employer', function () {
    $calc = new PensionCalculator;
    $result = $calc->calculate(500000); // 5000 ETB

    expect($result['employee_cents'])->toBe(35000); // 7%
    expect($result['employer_cents'])->toBe(55000); // 11%
});

test('pension calculates correctly for low salary', function () {
    $calc = new PensionCalculator;
    $result = $calc->calculate(100000); // 1000 ETB

    expect($result['employee_cents'])->toBe(7000);
    expect($result['employer_cents'])->toBe(11000);
});

// ── Overtime Calculator ──

test('overtime normal rate 1.25x', function () {
    $calc = new OvertimeCalculator;
    // 5000 ETB basic, 22 working days, 8 hours/day
    // Hourly = 500000 / (22 * 8) = 2841 (approx)
    // 120 min = 2 hours overtime
    // 2841 * 2 * 1.25 = 7102 (approx)
    $amount = $calc->calculate(500000, 22, 8, 120, 'normal');
    expect($amount)->toBeGreaterThan(0);
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 1.25));
});

test('overtime night rate 1.5x', function () {
    $calc = new OvertimeCalculator;
    $amount = $calc->calculate(500000, 22, 8, 120, 'night');
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 1.5));
});

test('overtime holiday rate 2.0x', function () {
    $calc = new OvertimeCalculator;
    $amount = $calc->calculate(500000, 22, 8, 120, 'holiday');
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 2.0));
});

test('overtime holiday night rate 2.5x', function () {
    $calc = new OvertimeCalculator;
    $amount = $calc->calculate(500000, 22, 8, 120, 'holiday_night');
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 2.5));
});

test('overtime with zero minutes returns 0', function () {
    $calc = new OvertimeCalculator;
    expect($calc->calculate(500000, 22, 8, 0))->toBe(0);
});

// ── Loan Service ──

test('loan service calculates monthly deduction', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'monthly_deduction_cents' => 200000,
    ]);

    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'monthly_deduction_cents' => 100000,
    ]);

    $service = new LoanService;
    expect($service->calculateMonthlyDeduction($employee))->toBe(300000);
});

test('loan deduction marks loan completed when fully repaid', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $loan = EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 100000,
        'remaining_cents' => 50000,
        'monthly_deduction_cents' => 50000,
    ]);

    $service = new LoanService;
    $service->applyDeduction($loan, 50000);

    $loan->refresh();
    expect($loan->remaining_cents)->toBe(0);
    expect($loan->status)->toBe('completed');
});

test('completed loans not included in deduction', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'monthly_deduction_cents' => 200000,
        'status' => 'active',
    ]);

    EmployeeLoan::factory()->completed()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'monthly_deduction_cents' => 100000,
    ]);

    $service = new LoanService;
    expect($service->calculateMonthlyDeduction($employee))->toBe(200000);
});

// ── Loan API ──

test('finance admin can create a loan', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/loans", [
        'employee_public_id' => $employee->public_id,
        'amount_cents' => 1200000,
        'monthly_deduction_cents' => 100000,
        'reason' => 'Personal loan',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('amount_cents', 1200000)
        ->assertJsonPath('remaining_cents', 1200000)
        ->assertJsonPath('status', 'active')
        ->assertJsonMissingPath('id');
});

test('employee cannot create a loan', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/loans", [
        'employee_public_id' => $employee->public_id,
        'amount_cents' => 100000,
        'monthly_deduction_cents' => 10000,
    ])->assertForbidden();
});

test('finance admin can list loans', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    EmployeeLoan::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/loans");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('loan creation is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/loans", [
        'employee_public_id' => $employee->public_id,
        'amount_cents' => 500000,
        'monthly_deduction_cents' => 50000,
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'loan.created',
    ]);
});

test('loans require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/payroll/loans')
        ->assertUnauthorized();
});

// ── Pagumen (13th month) Proration ──
// Reference: Pagume 2015 EC == 2023-09-06 .. 2023-09-11 (leap year, 6 days).
// Salary 3,650,000 cents (36,500 ETB) is chosen so the daily rate is exact:
// annual = 3,650,000 x 12 = 43,800,000; / 365 = 120,000 cents/day.

test('pagumen full_month strategy pays the whole monthly salary for a short Pagume run', function () {
    $tenant = createTenant();
    $tenant->update(['settings' => ['pagumen_proration_strategy' => 'full_month']]);
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 3650000,
        'hire_date' => '2020-01-01',
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2023-09-06'),
        Carbon::parse('2023-09-11'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    // full_month => Pagume treated as a normal month, no day-based reduction.
    expect($entry->basic_salary_cents)->toBe(3650000);

    $pagumenStep = collect($entry->calculation_log['steps'])->firstWhere('step', 'pagumen_proration');
    expect($pagumenStep['strategy'])->toBe('full_month');
    expect($pagumenStep['applied'])->toBeFalse();
    expect($pagumenStep['pagume_days_in_period'])->toBe(6);
});

test('pagumen daily_rate strategy pays annual/365 per Pagume day (leap year, 6 days)', function () {
    $tenant = createTenant();
    $tenant->update(['settings' => ['pagumen_proration_strategy' => 'daily_rate']]);
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 3650000,
        'hire_date' => '2020-01-01',
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2023-09-06'),
        Carbon::parse('2023-09-11'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    // 3,650,000 x 12 / 365 x 6 = 120,000 x 6 = 720,000 cents. Integer, exact.
    expect($entry->basic_salary_cents)->toBe(720000);

    $pagumenStep = collect($entry->calculation_log['steps'])->firstWhere('step', 'pagumen_proration');
    expect($pagumenStep['strategy'])->toBe('daily_rate');
    expect($pagumenStep['applied'])->toBeTrue();
    expect($pagumenStep['pagume_days_in_period'])->toBe(6);
    expect($pagumenStep['pagume_total_days_in_year'])->toBe(6);
    expect($pagumenStep['ethiopian_year'])->toBe(2015);
});

test('pagumen daily_rate strategy pays 5 days in a common Ethiopian year', function () {
    $tenant = createTenant();
    $tenant->update(['settings' => ['pagumen_proration_strategy' => 'daily_rate']]);
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 3650000,
        'hire_date' => '2020-01-01',
    ]);

    $engine = app(PayrollEngine::class);
    // Pagume 2016 EC == 2024-09-06 .. 2024-09-10 (common year, 5 days).
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2024-09-06'),
        Carbon::parse('2024-09-10'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    // 120,000 x 5 = 600,000 cents.
    expect($entry->basic_salary_cents)->toBe(600000);

    $pagumenStep = collect($entry->calculation_log['steps'])->firstWhere('step', 'pagumen_proration');
    expect($pagumenStep['pagume_total_days_in_year'])->toBe(5);
    expect($pagumenStep['ethiopian_year'])->toBe(2016);
});

test('pagumen daily_rate composes multiplicatively with a mid-Pagume hire', function () {
    $tenant = createTenant();
    $tenant->update(['settings' => ['pagumen_proration_strategy' => 'daily_rate']]);
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Hired on Pagume day 4 (2023-09-09) of a 6-day Pagume run (2023-09-06..11).
    // Hire proration = worked 3 of 6 period days = 0.5.
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 3650000,
        'hire_date' => '2023-09-09',
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2023-09-06'),
        Carbon::parse('2023-09-11'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    // Daily-rate Pagume base 720,000 x hire factor 0.5 = 360,000 cents.
    expect($entry->basic_salary_cents)->toBe(360000);

    $prorationStep = collect($entry->calculation_log['steps'])->firstWhere('step', 'proration');
    expect($prorationStep['proration_factor'])->toBe(0.5);
    expect($prorationStep['period_basic_cents'])->toBe(720000);
});

test('a normal Gregorian month is untouched by daily_rate strategy', function () {
    $tenant = createTenant();
    $tenant->update(['settings' => ['pagumen_proration_strategy' => 'daily_rate']]);
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 3650000,
        'hire_date' => '2020-01-01',
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2024-06-01'),
        Carbon::parse('2024-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    // No Pagume overlap => full monthly salary, strategy not applied.
    expect($entry->basic_salary_cents)->toBe(3650000);

    $pagumenStep = collect($entry->calculation_log['steps'])->firstWhere('step', 'pagumen_proration');
    expect($pagumenStep['applied'])->toBeFalse();
    expect($pagumenStep['pagume_days_in_period'])->toBe(0);
});

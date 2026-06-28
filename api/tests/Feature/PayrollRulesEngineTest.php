<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Services\Payroll\LoanService;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PensionCalculator;
use App\Services\Payroll\TaxCalculator;

// ── Tax Calculator: Ethiopian Income Tax Brackets ──
// All amounts in cents. Brackets per ETB:
// 0-600 ETB (0-60000 cents): 0%
// 601-1650 (60001-165000): 10% - 6000
// 1651-3200 (165001-320000): 15% - 14250
// 3201-5250 (320001-525000): 20% - 30250
// 5251-7800 (525001-780000): 25% - 56500
// 7801-10900 (780001-1090000): 30% - 95500
// 10901+ (1090001+): 35% - 150000

test('tax calculator: 0 ETB income', function () {
    $calc = new TaxCalculator();
    expect($calc->calculate(0))->toBe(0);
});

test('tax calculator: bracket 1 — 500 ETB (exempt)', function () {
    $calc = new TaxCalculator();
    expect($calc->calculate(50000))->toBe(0);
});

test('tax calculator: bracket 2 — 1000 ETB', function () {
    $calc = new TaxCalculator();
    // 100000 * 10% - 6000 = 4000
    expect($calc->calculate(100000))->toBe(4000);
});

test('tax calculator: bracket 3 — 2500 ETB', function () {
    $calc = new TaxCalculator();
    // 250000 * 15% - 14250 = 37500 - 14250 = 23250
    expect($calc->calculate(250000))->toBe(23250);
});

test('tax calculator: bracket 4 — 4000 ETB', function () {
    $calc = new TaxCalculator();
    // 400000 * 20% - 30250 = 80000 - 30250 = 49750
    expect($calc->calculate(400000))->toBe(49750);
});

test('tax calculator: bracket 5 — 6000 ETB', function () {
    $calc = new TaxCalculator();
    // 600000 * 25% - 56500 = 150000 - 56500 = 93500
    expect($calc->calculate(600000))->toBe(93500);
});

test('tax calculator: bracket 6 — 9000 ETB', function () {
    $calc = new TaxCalculator();
    // 900000 * 30% - 95500 = 270000 - 95500 = 174500
    expect($calc->calculate(900000))->toBe(174500);
});

test('tax calculator: bracket 7 — 15000 ETB', function () {
    $calc = new TaxCalculator();
    // 1500000 * 35% - 150000 = 525000 - 150000 = 375000
    expect($calc->calculate(1500000))->toBe(375000);
});

// ── Pension Calculator ──

test('pension calculates 7% employee and 11% employer', function () {
    $calc = new PensionCalculator();
    $result = $calc->calculate(500000); // 5000 ETB

    expect($result['employee_cents'])->toBe(35000); // 7%
    expect($result['employer_cents'])->toBe(55000); // 11%
});

test('pension calculates correctly for low salary', function () {
    $calc = new PensionCalculator();
    $result = $calc->calculate(100000); // 1000 ETB

    expect($result['employee_cents'])->toBe(7000);
    expect($result['employer_cents'])->toBe(11000);
});

// ── Overtime Calculator ──

test('overtime normal rate 1.25x', function () {
    $calc = new OvertimeCalculator();
    // 5000 ETB basic, 22 working days, 8 hours/day
    // Hourly = 500000 / (22 * 8) = 2841 (approx)
    // 120 min = 2 hours overtime
    // 2841 * 2 * 1.25 = 7102 (approx)
    $amount = $calc->calculate(500000, 22, 8, 120, 'normal');
    expect($amount)->toBeGreaterThan(0);
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 1.25));
});

test('overtime night rate 1.5x', function () {
    $calc = new OvertimeCalculator();
    $amount = $calc->calculate(500000, 22, 8, 120, 'night');
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 1.5));
});

test('overtime holiday rate 2.0x', function () {
    $calc = new OvertimeCalculator();
    $amount = $calc->calculate(500000, 22, 8, 120, 'holiday');
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 2.0));
});

test('overtime holiday night rate 2.5x', function () {
    $calc = new OvertimeCalculator();
    $amount = $calc->calculate(500000, 22, 8, 120, 'holiday_night');
    expect($amount)->toBe((int) round(500000 / (22 * 8) * 2 * 2.5));
});

test('overtime with zero minutes returns 0', function () {
    $calc = new OvertimeCalculator();
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

    $service = new LoanService();
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

    $service = new LoanService();
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

    $service = new LoanService();
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

<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;

// ── Executive Dashboard Overview ──

test('tenant admin can access executive dashboard', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(5)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive");

    $response->assertOk()
        ->assertJsonStructure([
            'headcount' => ['total', 'active', 'by_department'],
            'attendance_rate' => ['today', 'period'],
            'payroll_summary',
            'turnover',
            'workforce_growth',
            'leave_utilization',
        ]);
});

test('headcount returns correct numbers', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => EmployeeStatus::TERMINATED,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive");

    $response->assertOk();
    expect($response->json('headcount.total'))->toBe(4);
    expect($response->json('headcount.active'))->toBe(3);
});

test('executive dashboard supports date range', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive?from=2026-01-01&to=2026-06-30");

    $response->assertOk();
});

test('employee cannot access executive dashboard', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive")
        ->assertForbidden();
});

// ── Attendance Deep-dive ──

test('attendance deep-dive returns structured data', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive/attendance");

    $response->assertOk()
        ->assertJsonStructure([
            'daily_trend',
            'by_department',
            'by_source',
            'top_late',
        ]);
});

// ── Payroll Deep-dive ──

test('payroll deep-dive returns structured data', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'completed',
        'gross_total_cents' => 5000000,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive/payroll");

    $response->assertOk()
        ->assertJsonStructure([
            'monthly_trend',
            'overtime_trend',
            'by_department',
            'by_cost_center',
            'totals',
        ]);
});

test('overtime trend sums overtime pay from the calculation log, not a stored column', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'completed', 'period_label' => 'August 2026']);
    $employeeA = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $employeeB = Employee::factory()->create(['tenant_id' => $tenant->id]);

    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employeeA->id,
        'calculation_log' => ['steps' => [
            ['step' => 'basic', 'basic_after_unpaid_leave_cents' => 500000],
            ['step' => 'overtime', 'overtime_minutes' => 120, 'overtime_amount_cents' => 15000],
        ]],
    ]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employeeB->id,
        'calculation_log' => ['steps' => [
            ['step' => 'overtime', 'overtime_minutes' => 60, 'overtime_amount_cents' => 7500],
        ]],
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive/payroll");

    $response->assertOk();
    $trend = collect($response->json('overtime_trend'))->keyBy('period');
    expect($trend->get('August 2026')['overtime_cents'])->toBe(22500);
    expect($trend->get('August 2026')['overtime_minutes'])->toBe(180);
});

test('overtime trend excludes draft payroll runs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'draft', 'period_label' => 'September 2026']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'calculation_log' => ['steps' => [
            ['step' => 'overtime', 'overtime_minutes' => 60, 'overtime_amount_cents' => 7500],
        ]],
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive/payroll");

    $response->assertOk();
    expect(collect($response->json('overtime_trend'))->pluck('period'))->not->toContain('September 2026');
});

test('payroll by cost center sums gross pay per center and buckets unassigned employees', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $engineering = CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);

    $withCostCenter = Employee::factory()->create(['tenant_id' => $tenant->id, 'cost_center_id' => $engineering->id]);
    $withoutCostCenter = Employee::factory()->create(['tenant_id' => $tenant->id, 'cost_center_id' => null]);

    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $withCostCenter->id,
        'gross_cents' => 300000,
    ]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $withoutCostCenter->id,
        'gross_cents' => 100000,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive/payroll");

    $response->assertOk();
    $byCostCenter = collect($response->json('by_cost_center'))->keyBy('cost_center');
    expect($byCostCenter->get('Engineering')['total_gross_cents'])->toBe(300000);
    expect($byCostCenter->get('Engineering')['employee_count'])->toBe(1);
    // Not silently dropped — a tenant that hasn't finished assigning cost
    // centers still sees that payroll accounted for, just unlabelled.
    expect($byCostCenter->get('Unassigned')['total_gross_cents'])->toBe(100000);
});

// ── Workforce Deep-dive ──

test('workforce deep-dive returns demographics', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'gender' => 'male',
    ]);
    Employee::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'gender' => 'female',
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive/workforce");

    $response->assertOk()
        ->assertJsonStructure([
            'headcount_trend',
            'by_department',
            'by_gender',
            'by_tenure',
        ]);

    $genders = collect($response->json('by_gender'));
    expect($genders->sum('count'))->toBe(5);
});

// ── Department Analytics ──

test('tenant admin can compare departments', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
    Employee::factory()->count(4)->create([
        'tenant_id' => $tenant->id,
        'department_id' => $dept->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/analytics/departments");

    $response->assertOk()
        ->assertJsonStructure(['departments']);
    expect($response->json('departments.0.headcount'))->toBe(4);
});

test('department detail returns employee breakdown', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'department_id' => $dept->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/analytics/departments/{$dept->public_id}");

    $response->assertOk()
        ->assertJsonPath('headcount', 2);
});

// ── Branch Analytics ──

test('tenant admin can compare branches', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Addis Ababa']);
    Employee::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/analytics/branches");

    $response->assertOk()
        ->assertJsonStructure(['branches']);
});

test('branch detail returns department breakdown', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Department::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/analytics/branches/{$branch->public_id}");

    $response->assertOk();
    expect($response->json('departments'))->toHaveCount(2);
});

// ── Auth ──

test('executive dashboard requires auth', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/dashboard/executive')
        ->assertUnauthorized();
});

test('analytics requires auth', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/analytics/departments')
        ->assertUnauthorized();
});

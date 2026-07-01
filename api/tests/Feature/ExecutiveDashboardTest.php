<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
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
            'by_department',
            'totals',
        ]);
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

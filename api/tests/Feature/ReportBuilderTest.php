<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\SavedReport;

// ── Report Sources ──

test('hr admin can list report sources', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/sources");

    $response->assertOk()
        ->assertJsonStructure(['sources' => ['employees', 'attendance', 'leave', 'payroll']]);
});

// ── Report Export ──

test('hr admin can export report as csv', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/export", [
        'source' => 'employees',
        'columns' => ['name', 'email'],
        'format' => 'csv',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->getContent())->toContain('name,email');
});

test('hr admin can export report as pdf', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/export", [
        'source' => 'employees',
        'columns' => ['name', 'email'],
        'format' => 'pdf',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

// ── Report Generation ──

test('hr admin can generate employee report', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(5)->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'employees',
        'columns' => ['name', 'email', 'status'],
    ]);

    $response->assertOk()
        ->assertJsonPath('source', 'employees')
        ->assertJsonPath('total', 5);
    expect($response->json('data.0'))->toHaveKeys(['name', 'email', 'status']);
    expect($response->json('data.0'))->not->toHaveKey('salary_cents');
});

test('employee report resolves the position column for employees who have one', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $position = Position::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Senior Accountant',
    ]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'position_id' => $position->id,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'employees',
        'columns' => ['name', 'position'],
    ]);

    // Regression guard. The eager load named a `positions.name` column that does
    // not exist (it is `title`), so this 500'd against a real driver. Every
    // pre-existing test created employees with no position_id, and Laravel skips
    // an eager load entirely when no parent row has a foreign key -- so the broken
    // select never ran and the whole suite stayed green. Assert the value, not
    // just the status: selecting the wrong column also silently blanked it.
    $response->assertOk();
    expect($response->json('data.0.position'))->toBe('Senior Accountant');
});

test('report generation supports sorting', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Zara']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe']);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'employees',
        'columns' => ['name', 'email'],
        'sort_by' => 'name',
        'sort_dir' => 'asc',
    ]);

    $response->assertOk();
    expect($response->json('data.0.name'))->toBe('Abebe');
});

test('report generation supports group by', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'gender' => 'female']);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'employees',
        'group_by' => 'gender',
    ]);

    $response->assertOk();
    expect($response->json('summary.grouped_by'))->toBe('gender');
    expect($response->json('summary.groups.male'))->toBe(3);
    expect($response->json('summary.groups.female'))->toBe(2);
});

test('grouping a payroll report sums cents columns per group, not just counts', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'August 2026']);
    $employeeA = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $employeeB = Employee::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employeeA->id,
        'income_tax_cents' => 15000,
        'employee_pension_cents' => 5000,
    ]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employeeB->id,
        'income_tax_cents' => 25000,
        'employee_pension_cents' => 7000,
    ]);

    // The statutory need: total income tax withheld and total pension
    // contributed for the month, ready for the ERCA/pension-agency
    // remittance — a per-row list alone doesn't answer that.
    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'payroll',
        'columns' => ['employee_name', 'period', 'income_tax_cents', 'employee_pension_cents'],
        'group_by' => 'period',
    ]);

    $response->assertOk();
    expect($response->json('summary.group_sums.August 2026.income_tax_cents'))->toBe(40000);
    expect($response->json('summary.group_sums.August 2026.employee_pension_cents'))->toBe(12000);
});

// ── Save & Load Reports ──

test('hr admin can save report template', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/save", [
        'name' => 'Monthly Employee Report',
        'config' => [
            'source' => 'employees',
            'columns' => ['name', 'email', 'department'],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Monthly Employee Report')
        ->assertJsonMissingPath('id');
});

test('hr admin can list saved reports', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    SavedReport::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/saved");

    $response->assertOk();
    expect($response->json('reports'))->toHaveCount(3);
});

test('hr admin can delete saved report', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $report = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/saved/{$report->public_id}")
        ->assertNoContent();
});

// ── Scheduled Reports ──

test('hr admin can schedule a report', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $report = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/schedule", [
        'saved_report_public_id' => $report->public_id,
        'frequency' => 'monthly',
        'recipients' => ['hr@example.com', 'admin@example.com'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('frequency', 'monthly')
        ->assertJsonPath('recipients.0', 'hr@example.com');
    expect($response->json('next_run_at'))->not->toBeNull();
});

test('hr admin can list scheduled reports', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/scheduled");

    $response->assertOk()
        ->assertJsonStructure(['schedules']);
});

// ── Authorization ──

test('employee cannot generate reports', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'employees',
    ])->assertForbidden();
});

test('report save is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/save", [
        'name' => 'Test Report',
        'config' => ['source' => 'employees'],
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'report.saved',
    ]);
});

// ── Auth ──

test('reports require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/reports/sources')
        ->assertUnauthorized();
});

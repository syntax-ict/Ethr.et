<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

// ── PayrollEngine end-to-end ──

test('payroll engine processes employees correctly', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 1000000, // 10,000 ETB
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    expect($run->status)->toBe('completed');
    expect($run->employee_count)->toBe(1);
    expect($run->gross_total_cents)->toBeGreaterThan(0);
    expect($run->net_total_cents)->toBeGreaterThan(0);
    expect($run->net_total_cents)->toBeLessThan($run->gross_total_cents);

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();
    expect($entry)->not->toBeNull();
    expect($entry->basic_salary_cents)->toBe(1000000);
    expect($entry->income_tax_cents)->toBeGreaterThan(0);
    expect($entry->employee_pension_cents)->toBe(70000); // 7% of 10,000 ETB
    expect($entry->employer_pension_cents)->toBe(110000); // 11% of 10,000 ETB
});

test('payroll engine calculates net = gross - tax - pension - loans', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000, // 5,000 ETB
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    $expectedNet = $entry->gross_cents
        - $entry->income_tax_cents
        - $entry->employee_pension_cents
        - $entry->other_deductions_cents;

    expect($entry->net_cents)->toBe($expectedNet);
});

test('payroll prorates mid-month hire', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 1000000,
        'hire_date' => '2026-06-16',
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    expect($entry->basic_salary_cents)->toBeLessThan(1000000);
    expect($entry->basic_salary_cents)->toBeGreaterThan(0);
});

test('payroll deducts active loan', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 800000,
    ]);

    $loan = EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 240000,
        'remaining_cents' => 240000,
        'monthly_deduction_cents' => 20000,
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();

    expect($entry->other_deductions_cents)->toBe(20000);

    $loan->refresh();
    expect($loan->remaining_cents)->toBe(220000);
});

test('payroll skips employees with zero salary', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 0,
    ]);

    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;

    expect($run->employee_count)->toBe(0);
});

// ── PayrollController API ──

test('finance admin can process payroll via api', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'run-2026-06-alpha',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('employee_count', 1)
        ->assertJsonPath('was_duplicate', false)
        ->assertJsonMissingPath('id');
});

test('employee cannot process payroll', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'run-2026-06-forbidden',
    ])->assertForbidden();
});

test('processing payroll requires an idempotency key', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ])->assertStatus(422);
});

test('replaying the same idempotency key does not double-process payroll', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);

    $payload = [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'run-2026-06-replay',
    ];

    $first = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", $payload);
    $first->assertStatus(202)->assertJsonPath('was_duplicate', false);

    $second = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", $payload);
    $second->assertStatus(200)
        ->assertJsonPath('was_duplicate', true)
        ->assertJsonPath('public_id', $first->json('public_id'));

    expect(PayrollRun::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(PayrollEntry::where('tenant_id', $tenant->id)->count())->toBe(1);
    $this->assertDatabaseCount('audit_log', 1);
});

test('different idempotency keys allow separate payroll runs for the same period', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'run-2026-06-first',
    ])->assertStatus(202);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'run-2026-06-second',
    ])->assertStatus(202);

    expect(PayrollRun::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('finance admin can list payroll runs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    PayrollRun::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('finance admin can view payroll run detail', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $run->public_id);
});

// ── Approval ──

test('tenant admin can approve completed payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'completed',
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve");

    $response->assertOk()
        ->assertJsonPath('status', 'approved');
});

test('cannot approve draft payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'draft',
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422);
});

// ── Void ──

test('tenant admin can void a completed payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'completed',
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", [
        'reason' => 'Incorrect tax bracket applied',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'voided')
        ->assertJsonPath('void_reason', 'Incorrect tax bracket applied');

    $run->refresh();
    expect($run->status)->toBe('voided');
    expect($run->voided_at)->not->toBeNull();
    expect($run->voided_by)->not->toBeNull();

    $this->assertDatabaseHas('audit_log', ['action' => 'payroll.voided']);
});

test('tenant admin can void an approved payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'approved',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", [
        'reason' => 'Employee terminated before period end',
    ])->assertOk()->assertJsonPath('status', 'voided');
});

test('finance admin cannot void a payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'completed',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", [
        'reason' => 'Not allowed',
    ])->assertForbidden();
});

test('cannot void a draft payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'draft',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", [
        'reason' => 'Attempted early void',
    ])->assertStatus(422);
});

test('cannot void an already-voided payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'voided',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", [
        'reason' => 'Double void attempt',
    ])->assertStatus(422);
});

test('void requires a reason', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'completed',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", [])
        ->assertStatus(422);
});

// ── Reprocess ──

test('tenant admin can reprocess a voided payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 700000,
    ]);

    $voidedRun = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'voided',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voidedRun->public_id}/reprocess", [
        'idempotency_key' => 'reprocess-key-1',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('employee_count', 1)
        ->assertJsonPath('reprocessed_from_public_id', $voidedRun->public_id)
        ->assertJsonPath('was_duplicate', false);

    expect(PayrollRun::where('tenant_id', $tenant->id)->count())->toBe(2);

    $newRun = PayrollRun::where('public_id', $response->json('public_id'))->first();
    expect($newRun->reprocessed_from_id)->toBe($voidedRun->id);
    expect($newRun->period_start->toDateString())->toBe('2026-06-01');

    $this->assertDatabaseHas('audit_log', ['action' => 'payroll.reprocessed']);
});

test('finance admin cannot reprocess a payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $voidedRun = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'voided',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voidedRun->public_id}/reprocess", [
        'idempotency_key' => 'reprocess-key-2',
    ])->assertForbidden();
});

test('cannot reprocess a non-voided payroll run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'completed',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/reprocess", [
        'idempotency_key' => 'reprocess-key-3',
    ])->assertStatus(422);
});

test('reprocess requires an idempotency key', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $voidedRun = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'voided',
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voidedRun->public_id}/reprocess", [])
        ->assertStatus(422);
});

test('replaying the same reprocess idempotency key does not create a second run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);

    $voidedRun = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'voided',
    ]);

    $payload = ['idempotency_key' => 'reprocess-replay-key'];

    $first = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voidedRun->public_id}/reprocess", $payload);
    $first->assertStatus(201)->assertJsonPath('was_duplicate', false);

    $second = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$voidedRun->public_id}/reprocess", $payload);
    $second->assertStatus(200)
        ->assertJsonPath('was_duplicate', true)
        ->assertJsonPath('public_id', $first->json('public_id'));

    // Only the reprocessed run should exist alongside the original voided one.
    expect(PayrollRun::where('tenant_id', $tenant->id)->count())->toBe(2);
});

// ── Payslips ──

test('employee can view own payslips', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/my");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('employee cannot view other employees payslips', function () {
    $tenant = createTenant();
    $employee1 = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $employee2 = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee1->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/{$employee2->public_id}")
        ->assertForbidden();
});

test('finance admin can download payslip pdf', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/{$entry->public_id}/pdf");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('payslip pdf for a voided run still downloads successfully', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'voided']);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/{$entry->public_id}/pdf");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('payslip view renders a VOIDED watermark only for voided runs', function () {
    $data = [
        'tenant_name' => 'Acme Corp',
        'period' => 'June 2026',
        'employee_name' => 'Abebe Kebede',
        'employee_code' => 'EMP001',
        'department' => 'Engineering',
        'position' => 'Developer',
        'basic_salary_cents' => 1000000,
        'allowances' => [],
        'gross_cents' => 1000000,
        'income_tax_cents' => 100000,
        'employee_pension_cents' => 70000,
        'employer_pension_cents' => 110000,
        'other_deductions_cents' => 0,
        'deductions' => [],
        'net_cents' => 830000,
        'generated_at' => now()->format('d/m/Y H:i'),
        'is_voided' => true,
    ];

    test()->view('payslip', $data)->assertSee('VOIDED');
    test()->view('payslip', [...$data, 'is_voided' => false])->assertDontSee('VOIDED');
});

// ── Bank Export ──

test('finance admin can export bank file', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'net_cents' => 450000,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/export/bank");

    $response->assertOk()
        ->assertJsonStructure(['period', 'total_entries', 'total_amount_cents', 'rows']);
    expect($response->json('total_entries'))->toBe(1);
    expect($response->json('total_amount_cents'))->toBe(450000);
});

// ── Audit Logging ──

test('payroll processing is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'run-2026-06-audit',
    ])->assertStatus(202);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'payroll.processed',
    ]);
});

// ── Tenant Isolation ──

test('payroll runs are isolated per tenant', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    PayrollRun::factory()->count(2)->create(['tenant_id' => $tenant1->id]);
    PayrollRun::factory()->count(4)->create(['tenant_id' => $tenant2->id]);

    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant1);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/payroll/runs');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Authentication ──

test('payroll requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/payroll/runs')
        ->assertUnauthorized();
});

<?php

declare(strict_types=1);

use App\Enums\CostSharingStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;

function costSharingUrl(string $subdomain, string $path = ''): string
{
    return "http://{$subdomain}.ethr.test/api/v1/payroll/cost-sharing{$path}";
}

test('a finance admin can record an obligation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->postJson(costSharingUrl($tenant->subdomain), [
        'employee_public_id' => $employee->public_id,
        'total_obligation_cents' => 5000000,
        'deduction_rate_percent' => 10,
        'started_on' => '2026-01-01',
    ]);

    $response->assertCreated();
    expect($response->json('outstanding_cents'))->toBe(5000000);
    expect($response->json('repaid_cents'))->toBe(0);
    expect($response->json('status'))->toBe('active');

    // Convention #4: never expose the numeric PK.
    $response->assertJsonMissingPath('id');
});

test('recording an obligation is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson(costSharingUrl($tenant->subdomain), [
        'employee_public_id' => $employee->public_id,
        'total_obligation_cents' => 5000000,
        'deduction_rate_percent' => 10,
        'started_on' => '2026-01-01',
    ])->assertCreated();

    expect(AuditLog::where('action', 'cost_sharing.created')->exists())->toBeTrue();
});

test('a second active obligation for the same employee is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $this->postJson(costSharingUrl($tenant->subdomain), [
        'employee_public_id' => $employee->public_id,
        'total_obligation_cents' => 5000000,
        'deduction_rate_percent' => 10,
        'started_on' => '2026-01-01',
    ])->assertStatus(422);
});

test('a completed obligation does not block recording a new one', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    EmployeeCostSharing::factory()->completed()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $this->postJson(costSharingUrl($tenant->subdomain), [
        'employee_public_id' => $employee->public_id,
        'total_obligation_cents' => 5000000,
        'deduction_rate_percent' => 10,
        'started_on' => '2026-01-01',
    ])->assertCreated();
});

test('the deduction rate is required and bounded', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'employee_public_id' => $employee->public_id,
        'total_obligation_cents' => 5000000,
        'started_on' => '2026-01-01',
    ];

    $this->postJson(costSharingUrl($tenant->subdomain), $payload)
        ->assertStatus(422);

    // 1000 is the classic "meant 10.00" typo, and would withhold ten times pay.
    $this->postJson(costSharingUrl($tenant->subdomain), [...$payload, 'deduction_rate_percent' => 1000])
        ->assertStatus(422);

    $this->postJson(costSharingUrl($tenant->subdomain), [...$payload, 'deduction_rate_percent' => 0])
        ->assertStatus(422);
});

test('the balance cannot be edited by hand', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $obligation = EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'outstanding_cents' => 5000000,
    ]);

    $this->putJson(costSharingUrl($tenant->subdomain, "/{$obligation->public_id}"), [
        'outstanding_cents' => 1,
        'total_obligation_cents' => 1,
    ])->assertOk();

    // Payroll owns the balance; an unvalidated key must be ignored, not applied.
    $fresh = $obligation->fresh();
    expect($fresh->outstanding_cents)->toBe(5000000);
    expect($fresh->total_obligation_cents)->toBe($obligation->total_obligation_cents);
});

test('an illegal status transition is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $obligation = EmployeeCostSharing::factory()->completed()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    // Reopening a settled obligation would leave payroll deducting 0 forever
    // against something that reads active.
    $this->putJson(costSharingUrl($tenant->subdomain, "/{$obligation->public_id}"), [
        'status' => 'active',
    ])->assertStatus(422);
});

test('an active obligation can be suspended and resumed', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $obligation = EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $this->putJson(costSharingUrl($tenant->subdomain, "/{$obligation->public_id}"), [
        'status' => 'suspended',
    ])->assertOk();

    expect($obligation->fresh()->status)->toBe(CostSharingStatus::SUSPENDED);

    $this->putJson(costSharingUrl($tenant->subdomain, "/{$obligation->public_id}"), [
        'status' => 'active',
    ])->assertOk();

    expect($obligation->fresh()->status)->toBe(CostSharingStatus::ACTIVE);
});

test('an employee cannot record or view obligations', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $this->getJson(costSharingUrl($tenant->subdomain))->assertForbidden();

    $this->postJson(costSharingUrl($tenant->subdomain), [
        'employee_public_id' => $employee->public_id,
        'total_obligation_cents' => 5000000,
        'deduction_rate_percent' => 10,
        'started_on' => '2026-01-01',
    ])->assertForbidden();
});

test('obligations require authentication', function () {
    $tenant = createTenant();

    $this->getJson(costSharingUrl($tenant->subdomain))->assertUnauthorized();
});

test('obligations are isolated per tenant', function () {
    $other = createTenant();
    $otherEmployee = Employee::factory()->create(['tenant_id' => $other->id]);
    $otherObligation = EmployeeCostSharing::factory()->create([
        'tenant_id' => $other->id,
        'employee_id' => $otherEmployee->id,
    ]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $this->getJson(costSharingUrl($tenant->subdomain))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson(costSharingUrl($tenant->subdomain, "/{$otherObligation->public_id}"))
        ->assertNotFound();
});

test('filtering by an unknown employee returns nothing rather than everything', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $this->getJson(costSharingUrl($tenant->subdomain).'?filter[employee_public_id]=01HXXXXXXXXXXXXXXXXXXXXXXX')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

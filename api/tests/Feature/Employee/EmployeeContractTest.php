<?php

declare(strict_types=1);

use App\Enums\ContractStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Tenant;
use App\Services\CurrentTenant;

function contractsUrl(Employee $employee): string
{
    return "/api/v1/employees/{$employee->public_id}/contracts";
}

function createContract(Employee $employee, array $overrides = []): EmployeeContract
{
    $response = test()->postJson(contractsUrl($employee), [
        'contract_type' => 'fixed_term',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        ...$overrides,
    ])->assertCreated();

    return EmployeeContract::where('public_id', $response->json('public_id'))->firstOrFail();
}

describe('creating contracts', function () {
    it('creates a fixed-term contract', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson(contractsUrl($employee), [
            'contract_type' => 'fixed_term',
            'reference_number' => 'CT/2026/001',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'salary_cents' => 1_500_000,
        ]);

        $response->assertCreated();
        expect($response->json('status'))->toBe('active');
        expect($response->json('contract_type'))->toBe('fixed_term');
        expect($response->json('reference_number'))->toBe('CT/2026/001');
        $response->assertJsonMissingPath('id');
    });

    it('requires an end date for a non-permanent contract', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson(contractsUrl($employee), [
            'contract_type' => 'fixed_term',
            'start_date' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    });

    it('does not require an end date for a permanent contract', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson(contractsUrl($employee), [
            'contract_type' => 'permanent',
            'start_date' => '2026-01-01',
        ]);

        $response->assertCreated();
        expect($response->json('end_date'))->toBeNull();
    });

    it('forbids an employee without the update permission', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson(contractsUrl($employee), [
            'contract_type' => 'fixed_term',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->assertForbidden();
    });
});

describe('renewing contracts', function () {
    it('creates a successor contract and marks the original renewed', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $original = createContract($employee, ['salary_cents' => 1_000_000]);

        $response = $this->postJson(contractsUrl($employee)."/{$original->public_id}/renew", [
            'contract_type' => 'permanent',
            'start_date' => '2027-01-01',
        ]);

        $response->assertCreated();
        expect($response->json('contract_type'))->toBe('permanent');
        expect($response->json('status'))->toBe('active');
        expect($response->json('salary_cents'))->toBe(1_000_000); // carried over

        $original->refresh();
        expect($original->status)->toBe(ContractStatus::RENEWED);
        expect($original->ended_at)->not->toBeNull();
    });

    it('cannot renew a contract that is not active', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $original = createContract($employee);

        $this->postJson(contractsUrl($employee)."/{$original->public_id}/renew", [
            'contract_type' => 'fixed_term',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ])->assertCreated();

        // Renewing the now-renewed original a second time must fail.
        $this->postJson(contractsUrl($employee)."/{$original->public_id}/renew", [
            'contract_type' => 'fixed_term',
            'start_date' => '2028-01-01',
            'end_date' => '2028-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('ending contracts', function () {
    it('marks a contract expired', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $contract = createContract($employee);

        $response = $this->postJson(contractsUrl($employee)."/{$contract->public_id}/end", [
            'status' => 'expired',
            'end_notes' => 'Not renewed; role no longer required.',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('expired');
        expect($response->json('ended_at'))->not->toBeNull();
    });

    it('rejects an invalid end outcome', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $contract = createContract($employee);

        $this->postJson(contractsUrl($employee)."/{$contract->public_id}/end", [
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });

    it('cannot end an already-ended contract', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $contract = createContract($employee);

        $this->postJson(contractsUrl($employee)."/{$contract->public_id}/end", [
            'status' => 'terminated_early',
        ])->assertOk();

        $this->postJson(contractsUrl($employee)."/{$contract->public_id}/end", [
            'status' => 'expired',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('expiring watchlist', function () {
    it('lists only active contracts ending within the window', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Soon Expiring']);

        // Ends in 10 days — inside the default 30-day window.
        createContract($employee, [
            'start_date' => now()->subMonths(6)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        // Ends in 90 days — outside the default window.
        $farEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        createContract($farEmployee, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(90)->toDateString(),
        ]);

        // Permanent — no end date, never appears.
        $permEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        createContract($permEmployee, ['contract_type' => 'permanent', 'end_date' => null]);

        $response = $this->getJson('/api/v1/employees/contracts/expiring')->assertOk();

        $publicIds = collect($response->json('data'))->pluck('public_id');
        expect($publicIds)->toHaveCount(1);
        expect($response->json('data.0.employee_name'))->toBe('Soon Expiring');
        expect($response->json('data.0.days_until_expiry'))->toBe(10);
    });

    it('excludes a renewed contract even if its old end date is soon', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $contract = createContract($employee, [
            'start_date' => now()->subMonths(11)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->postJson(contractsUrl($employee)."/{$contract->public_id}/renew", [
            'contract_type' => 'permanent',
            'start_date' => now()->addDays(6)->toDateString(),
        ])->assertCreated();

        $response = $this->getJson('/api/v1/employees/contracts/expiring')->assertOk();

        expect($response->json('data'))->toBeEmpty();
    });
});

describe('tenant isolation', function () {
    it('does not resolve a contract belonging to another tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $otherTenant = Tenant::factory()->create();
        $otherEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($otherTenant);
        $foreignContract = EmployeeContract::factory()->for($otherEmployee)->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($tenant);

        $this->postJson(contractsUrl($employee)."/{$foreignContract->public_id}/end", [
            'status' => 'expired',
        ])->assertNotFound();
    });
});

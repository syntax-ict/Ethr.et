<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;

describe('permission gating', function () {
    it('denies a plain employee', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/dashboard/executive')->assertForbidden();
    });

    it('grants hr_admin the unrestricted (executive) view', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->getJson('/api/v1/dashboard/executive')->assertOk();
    });

    it('grants finance_admin the unrestricted (executive) view', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        $this->getJson('/api/v1/dashboard/executive')->assertOk();
    });

    it('grants supervisor the regional view when they have a branch', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $employee->id], $tenant);

        $this->getJson('/api/v1/dashboard/executive')->assertOk();
    });

    it('denies a supervisor with no branch assigned', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $this->getJson('/api/v1/dashboard/executive')->assertForbidden();
    });
});

describe('branch scoping', function () {
    it('scopes headcount to the requested branch for an executive-view holder', function () {
        $tenant = createTenant();
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);

        Employee::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        Employee::factory()->count(5)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $all = $this->getJson('/api/v1/dashboard/executive')->assertOk();
        expect($all->json('headcount.active'))->toBe(8);

        $scoped = $this->getJson('/api/v1/dashboard/executive?branch='.$branchA->public_id)->assertOk();
        expect($scoped->json('headcount.active'))->toBe(3);
    });

    it('always forces a regional-view holder onto their own branch, ignoring any requested branch', function () {
        $tenant = createTenant();
        $ownBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $callerEmployee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $ownBranch->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        Employee::factory()->count(4)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $ownBranch->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        Employee::factory()->count(9)->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $otherBranch->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);

        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $callerEmployee->id], $tenant);

        // Own branch has 5 active employees (4 + the caller themselves).
        $response = $this->getJson('/api/v1/dashboard/executive?branch='.$otherBranch->public_id)
            ->assertOk();

        expect($response->json('headcount.active'))->toBe(5);
    });

    it('rejects an unknown branch public_id for an executive-view holder', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->getJson('/api/v1/dashboard/executive?branch=01HNOTREAL0000000000000000')
            ->assertNotFound();
    });

    it('does not leak another tenant\'s branch data through the branch filter', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        // Built without createTenant(), which would rebind the CurrentTenant
        // singleton back onto itself and defeat the isolation this asserts.
        $otherTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->getJson('/api/v1/dashboard/executive?branch='.$foreignBranch->public_id)
            ->assertNotFound();
    });
});

function makeComplianceDocument(int $tenantId, int $employeeId, $expiry): EmployeeDocument
{
    return EmployeeDocument::create([
        'tenant_id' => $tenantId,
        'employee_id' => $employeeId,
        'type' => 'contract',
        'title' => 'Contract',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'expiry_date' => $expiry,
    ]);
}

describe('compliance snapshot', function () {
    it('counts documents expiring within 30 days', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        makeComplianceDocument($tenant->id, $employee->id, now()->addDays(10));
        makeComplianceDocument($tenant->id, $employee->id, now()->addDays(90)); // outside the 30-day window

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->getJson('/api/v1/dashboard/executive/compliance')->assertOk();

        expect($response->json('expiring_documents.count'))->toBe(1);
    });

    it('counts employees past their probation end date still marked probation', function () {
        $tenant = createTenant();
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::PROBATION,
            'probation_end_date' => now()->subDays(5),
        ]);
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::PROBATION,
            'probation_end_date' => now()->addDays(30), // not overdue
        ]);

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->getJson('/api/v1/dashboard/executive/compliance')->assertOk();

        expect($response->json('probation_overdue.count'))->toBe(1);
    });
});

describe('forecast', function () {
    it('returns a projected series shaped for the frontend', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->getJson('/api/v1/dashboard/executive/forecast')->assertOk();

        expect($response->json())->toHaveKeys(['headcount', 'payroll_gross']);
        expect($response->json('headcount.history'))->toHaveCount(12);
    });
});

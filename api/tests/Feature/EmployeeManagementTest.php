<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\CurrentTenant;

// ──────────────────────────── Employee CRUD ────────────────────────────

describe('employee CRUD', function () {
    it('lists employees for supervisor', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/employees');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3);
    });

    it('creates an employee as hr_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $position = Position::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'Abebe Kebede',
            'name_am' => 'አበበ ከበደ',
            'email' => 'abebe@example.com',
            'phone' => '+251911123456',
            'employee_code' => 'EMP-0001',
            'gender' => 'male',
            'date_of_birth' => '1990-03-15',
            'nationality' => 'Ethiopian',
            'marital_status' => 'married',
            'hire_date' => '2024-01-15',
            'salary_cents' => 1500000,
            'department_id' => $dept->public_id,
            'branch_id' => $branch->public_id,
            'position_id' => $position->public_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Abebe Kebede')
            ->assertJsonPath('name_am', 'አበበ ከበደ')
            ->assertJsonPath('employee_code', 'EMP-0001')
            ->assertJsonPath('status', 'hired');

        expect($response->json())->not->toHaveKey('id');
        expect($response->json())->toHaveKey('public_id');

        $this->assertDatabaseHas('employees', [
            'name' => 'Abebe Kebede',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('canonicalizes a phone typed in local 09... form on create', function () {
        // This is the primary, highest-traffic path for entering an employee's
        // phone number — unlike import/migration/SCIM/SSO/profile-self-service,
        // it stored whatever shape the form submitted until this fix.
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'Local Format',
            'phone' => '0911123456',
            'hire_date' => '2026-01-01',
        ]);

        $response->assertCreated()->assertJsonPath('phone', '+251911123456');
        $this->assertDatabaseHas('employees', [
            'name' => 'Local Format',
            'phone' => '+251911123456',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('canonicalizes a phone typed in local 09... form on update', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->putJson("/api/v1/employees/{$employee->public_id}", [
            'phone' => '0922334455',
        ]);

        $response->assertOk()->assertJsonPath('phone', '+251922334455');
    });

    it('shows a single employee with relationships', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'department_id' => $dept->id,
            'branch_id' => $branch->id,
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}");

        $response->assertOk()
            ->assertJsonPath('public_id', $employee->public_id)
            ->assertJsonPath('name', $employee->name)
            ->assertJsonStructure([
                'public_id', 'name', 'email', 'status',
                'department' => ['public_id', 'name'],
                'branch' => ['public_id', 'name'],
            ]);
    });

    it('updates an employee as hr_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->putJson("/api/v1/employees/{$employee->public_id}", [
            'name' => 'Updated Name',
            'phone' => '+251922222222',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Updated Name')
            ->assertJsonPath('phone', '+251922222222');
    });

    it('soft deletes an employee as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}")
            ->assertNoContent();

        $this->assertSoftDeleted('employees', ['public_id' => $employee->public_id]);
    });

    it('validates required fields on create', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'hire_date']);
    });

    it('enforces unique employee_code per tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-UNIQUE']);

        $this->postJson('/api/v1/employees', [
            'name' => 'Duplicate Code',
            'hire_date' => '2024-01-01',
            'employee_code' => 'EMP-UNIQUE',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_code']);
    });

    it('never exposes numeric id', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}");

        $response->assertOk()
            ->assertJsonMissingPath('id');
    });
});

// ──────────────────────────── Search & Filter ────────────────────────────

describe('employee search and filter', function () {
    it('searches employees by name', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tigist Hailu']);

        $response = $this->getJson('/api/v1/employees?search=Abebe');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.name'))->toBe('Abebe Kebede');
    });

    it('searches employees by employee code', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1234']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-5678']);

        $response = $this->getJson('/api/v1/employees?search=1234');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
    });

    it('filters employees by status', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::PROBATION, 'probation_end_date' => now()->addMonths(3)]);

        $response = $this->getJson('/api/v1/employees?filter[status]=probation');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.status'))->toBe('probation');
    });

    it('filters employees by department', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
        $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);

        Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

        $response = $this->getJson("/api/v1/employees?filter[department_id]={$deptA->public_id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('sorts employees by hire_date descending', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Hire', 'hire_date' => '2020-01-01']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'New Hire', 'hire_date' => '2024-06-01']);

        $response = $this->getJson('/api/v1/employees?sort=-hire_date');

        $response->assertOk();
        expect($response->json('data.0.name'))->toBe('New Hire');
        expect($response->json('data.1.name'))->toBe('Old Hire');
    });

    it('returns employee stats', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Addis']);

        Employee::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
            'department_id' => $dept->id,
            'branch_id' => $branch->id,
        ]);
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::PROBATION,
            'probation_end_date' => now()->addMonths(3),
            'department_id' => $dept->id,
            'branch_id' => $branch->id,
        ]);

        $response = $this->getJson('/api/v1/employees/stats');

        $response->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('by_status.confirmed', 3)
            ->assertJsonPath('by_status.probation', 1)
            ->assertJsonPath('by_department.Engineering', 4)
            ->assertJsonPath('by_branch.Addis', 4);
    });
});

// ──────────────────────────── Lifecycle Transitions ────────────────────────────

describe('employee lifecycle transitions', function () {
    it('transitions hired to probation', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->hired()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'probation',
            'reason' => 'Starting probation period',
            'effective_date' => '2024-06-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('from_status', 'hired')
            ->assertJsonPath('to_status', 'probation');

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::PROBATION);

        $this->assertDatabaseHas('employee_transitions', [
            'employee_id' => $employee->id,
            'from_status' => 'hired',
            'to_status' => 'probation',
        ]);
    });

    it('transitions probation to confirmed', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->probation()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'confirmed',
            'reason' => 'Completed probation successfully',
            'effective_date' => '2024-09-01',
        ]);

        $response->assertCreated();

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::CONFIRMED);
        expect($employee->confirmation_date->format('Y-m-d'))->toBe('2024-09-01');
    });

    it('rejects invalid transition', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->hired()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'retired',
            'reason' => 'Invalid',
            'effective_date' => '2024-06-01',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('title', 'Invalid Status Transition');

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::HIRED);
    });

    it('sets termination_date on terminal transitions', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'terminated',
            'reason' => 'End of contract',
            'effective_date' => '2024-12-31',
        ])->assertCreated();

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::TERMINATED);
        expect($employee->termination_date->format('Y-m-d'))->toBe('2024-12-31');
    });

    it('lists transition history', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->hired()->create(['tenant_id' => $tenant->id]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'probation',
            'effective_date' => '2024-06-01',
        ]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'confirmed',
            'effective_date' => '2024-09-01',
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/transitions");

        $response->assertOk();
        expect($response->json())->toHaveCount(2);
    });

    it('creates audit log on transition', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->hired()->create(['tenant_id' => $tenant->id]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'probation',
            'effective_date' => '2024-06-01',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.transitioned',
            'tenant_id' => $tenant->id,
        ]);
    });
});

// ──────────────────────────── Emergency Contacts ────────────────────────────

describe('employee emergency contacts', function () {
    it('lists emergency contacts', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->emergencyContacts()->createMany([
            ['tenant_id' => $tenant->id, 'name' => 'Contact 1', 'relationship' => 'spouse', 'phone' => '+251911111111', 'priority' => 1],
            ['tenant_id' => $tenant->id, 'name' => 'Contact 2', 'relationship' => 'parent', 'phone' => '+251922222222', 'priority' => 2],
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/emergency-contacts");

        $response->assertOk();
        expect($response->json())->toHaveCount(2);
        expect($response->json('0.name'))->toBe('Contact 1');
    });

    it('creates an emergency contact', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/emergency-contacts", [
            'name' => 'Almaz Hailu',
            'relationship' => 'spouse',
            'phone' => '+251911999888',
            'email' => 'almaz@example.com',
            'priority' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Almaz Hailu')
            ->assertJsonPath('relationship', 'spouse');
    });

    it('canonicalizes an emergency contact phone typed in local 09... form', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/emergency-contacts", [
            'name' => 'Local Format Contact',
            'relationship' => 'spouse',
            'phone' => '0911999888',
        ]);

        $response->assertCreated()
            ->assertJsonPath('phone', '+251911999888');
    });

    it('deletes an emergency contact', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $contact = $employee->emergencyContacts()->create([
            'tenant_id' => $tenant->id,
            'name' => 'To Delete',
            'relationship' => 'friend',
            'phone' => '+251900000000',
        ]);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}/emergency-contacts/{$contact->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('employee_emergency_contacts', ['id' => $contact->id]);
    });
});

// ──────────────────────────── Bank Details ────────────────────────────

describe('employee bank details', function () {
    it('lists bank details for hr_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->bankDetails()->create([
            'tenant_id' => $tenant->id,
            'bank_name' => 'CBE',
            'branch_name' => 'Bole',
            'account_number' => '1000123456789',
            'is_primary' => true,
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/bank-details");

        $response->assertOk();
        expect($response->json())->toHaveCount(1);
        expect($response->json('0.bank_name'))->toBe('CBE');
        expect($response->json('0.account_number_masked'))->toBe('*********6789');
        expect($response->json('0'))->not->toHaveKey('account_number');
    });

    it('creates a bank detail', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/bank-details", [
            'bank_name' => 'Dashen Bank',
            'branch_name' => 'Megenagna',
            'account_number' => '2000987654321',
            'is_primary' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('bank_name', 'Dashen Bank')
            ->assertJsonPath('is_primary', true);
    });

    it('denies bank detail access to supervisor', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson("/api/v1/employees/{$employee->public_id}/bank-details")
            ->assertForbidden();
    });

    it('creates audit log for bank detail changes', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/bank-details", [
            'bank_name' => 'Awash',
            'account_number' => '3000111222333',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.bank_detail.created',
            'tenant_id' => $tenant->id,
        ]);
    });
});

// ──────────────────────────── Education ────────────────────────────

describe('employee education', function () {
    it('lists education records', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->education()->create([
            'tenant_id' => $tenant->id,
            'institution' => 'Addis Ababa University',
            'degree' => 'BSc',
            'field_of_study' => 'Computer Science',
            'start_date' => '2015-09-01',
            'end_date' => '2019-07-01',
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/education");

        $response->assertOk();
        expect($response->json())->toHaveCount(1);
        expect($response->json('0.institution'))->toBe('Addis Ababa University');
    });

    it('creates an education record', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/education", [
            'institution' => 'Jimma University',
            'degree' => 'MSc',
            'field_of_study' => 'Software Engineering',
            'start_date' => '2020-01-01',
            'end_date' => '2022-06-30',
            'grade' => '3.8',
        ]);

        $response->assertCreated()
            ->assertJsonPath('institution', 'Jimma University')
            ->assertJsonPath('degree', 'MSc');
    });

    it('deletes an education record', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $edu = $employee->education()->create([
            'tenant_id' => $tenant->id,
            'institution' => 'To Delete',
            'degree' => 'PhD',
        ]);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}/education/{$edu->public_id}")
            ->assertNoContent();
    });
});

// ──────────────────────────── Tenant Isolation ────────────────────────────

describe('employee tenant isolation', function () {
    it('only returns employees for the current tenant', function () {
        $tenantA = createTenant();
        Employee::factory()->count(2)->create(['tenant_id' => $tenantA->id]);

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB);
        Employee::factory()->count(3)->create(['tenant_id' => $tenantB->id]);
        app(CurrentTenant::class)->set($tenantA);

        actingAsUser(['role' => UserRole::SUPERVISOR], $tenantA);

        $response = $this->getJson('/api/v1/employees');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('returns 404 for an employee from another tenant', function () {
        $tenantA = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenantA);

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB);
        $employee = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        app(CurrentTenant::class)->set($tenantA);

        $this->getJson("/api/v1/employees/{$employee->public_id}")
            ->assertNotFound();
    });
});

// ──────────────────────────── Authorization ────────────────────────────

describe('employee authorization', function () {
    it('denies employee from listing employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/employees')->assertForbidden();
    });

    it('denies supervisor from creating employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Blocked',
            'hire_date' => '2024-01-01',
        ])->assertForbidden();
    });

    it('denies hr_admin from deleting employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}")
            ->assertForbidden();
    });

    it('denies supervisor from transitioning employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->hired()->create(['tenant_id' => $tenant->id]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'probation',
            'effective_date' => '2024-06-01',
        ])->assertForbidden();
    });

    it('requires authentication for all employee endpoints', function () {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $this->postJson('/api/v1/employees', ['name' => 'Test'])->assertUnauthorized();
        $this->getJson('/api/v1/employees/stats')->assertUnauthorized();
    });
});

// ──────────────────────────── Audit Logging ────────────────────────────

describe('employee audit logging', function () {
    it('logs employee creation', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Audit Test Employee',
            'hire_date' => '2024-01-01',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.created',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('logs employee update with before/after', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Original Name',
        ]);

        $this->putJson("/api/v1/employees/{$employee->public_id}", [
            'name' => 'Changed Name',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.updated',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('logs employee deletion', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}");

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.deleted',
            'tenant_id' => $tenant->id,
        ]);
    });
});

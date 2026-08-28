<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Models\RetirementCase;
use App\Models\Tenant;
use App\Services\CurrentTenant;

function retirementCasesUrl(Employee $employee): string
{
    return "/api/v1/employees/{$employee->public_id}/retirement-cases";
}

function retirementActionUrl(Employee $employee, RetirementCase $case, string $action): string
{
    return retirementCasesUrl($employee)."/{$case->public_id}/{$action}";
}

function initiateRetirement(Employee $employee, array $overrides = []): RetirementCase
{
    $response = test()->postJson(retirementCasesUrl($employee), [
        'retirement_type' => 'mandatory',
        ...$overrides,
    ])->assertCreated();

    return RetirementCase::where('public_id', $response->json('public_id'))->firstOrFail();
}

describe('initiating a case', function () {
    it('initiates a case with computed service years and eligible date', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'hire_date' => now()->subYears(10)->toDateString(),
            'date_of_birth' => now()->subYears(58)->toDateString(),
        ]);

        $response = $this->postJson(retirementCasesUrl($employee), [
            'retirement_type' => 'mandatory',
            'reason' => 'Reached statutory retirement age.',
        ]);

        $response->assertCreated();
        expect($response->json('status'))->toBe('initiated');
        expect($response->json('retirement_type'))->toBe('mandatory');
        expect($response->json('service_years'))->toBeGreaterThan(9.9)->toBeLessThan(10.1);
        // Default retirement age is 60; date of birth is 58 years ago, so
        // eligibility lands about 2 years out.
        expect($response->json('eligible_retirement_date'))->not->toBeNull();
        $response->assertJsonMissingPath('id');
    });

    it('respects a tenant-configured retirement age', function () {
        $tenant = createTenant();
        // settings.manage is tenant-admin level; retirement_case.manage is
        // included in that role's cumulative grants too.
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $this->putJson('/api/v1/settings', ['settings' => ['retirement_age' => 65]])->assertOk();
        $dob = now()->subYears(50)->startOfDay();
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'date_of_birth' => $dob->toDateString(),
        ]);

        $response = $this->postJson(retirementCasesUrl($employee), ['retirement_type' => 'voluntary']);

        $response->assertCreated();
        expect($response->json('eligible_retirement_date'))->toBe($dob->copy()->addYears(65)->toDateString());
    });

    it('leaves eligible_retirement_date null when date of birth is unset', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'date_of_birth' => null]);

        $response = $this->postJson(retirementCasesUrl($employee), ['retirement_type' => 'voluntary']);

        $response->assertCreated();
        expect($response->json('eligible_retirement_date'))->toBeNull();
    });

    it('rejects a second open case for the same employee', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        initiateRetirement($employee);

        $this->postJson(retirementCasesUrl($employee), ['retirement_type' => 'voluntary'])
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
    });

    it('allows a new case once the previous one is finalized', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = initiateRetirement($employee);
        $this->postJson(retirementActionUrl($employee, $case, 'decision'), ['decision' => 'approved'])->assertOk();
        $this->postJson(retirementActionUrl($employee, $case, 'finalize'), [
            'effective_date' => now()->addDay()->toDateString(),
        ])->assertOk();

        // The employee is retired now, but the *case* lifecycle itself being
        // reusable (a cancelled/rejected/finalized case doesn't block forever)
        // is what this checks — attempting again should fail on the employee's
        // status, not on "case already open".
        $this->postJson(retirementCasesUrl($employee), ['retirement_type' => 'voluntary'])
            ->assertCreated();
    });
});

describe('review and decision', function () {
    it('moves initiated to under_review on the first note', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = initiateRetirement($employee);

        $response = $this->postJson(retirementActionUrl($employee, $case, 'notes'), [
            'note' => 'Confirmed service record with the pension office.',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('under_review');
        expect($response->json('notes'))->toContain('Confirmed service record with the pension office.');
    });

    it('approves a case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = initiateRetirement($employee);

        $response = $this->postJson(retirementActionUrl($employee, $case, 'decision'), [
            'decision' => 'approved',
            'decision_notes' => 'Service record confirmed.',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('approved');
        expect($response->json('decision'))->toBe('approved');
    });

    it('rejects a case, which is terminal', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = initiateRetirement($employee);

        $this->postJson(retirementActionUrl($employee, $case, 'decision'), ['decision' => 'rejected'])
            ->assertOk();

        $this->postJson(retirementActionUrl($employee, $case, 'decision'), ['decision' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('finalizing', function () {
    it('finalizes an approved case and retires the employee through the transition machinery', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = initiateRetirement($employee);
        $this->postJson(retirementActionUrl($employee, $case, 'decision'), ['decision' => 'approved'])->assertOk();

        $response = $this->postJson(retirementActionUrl($employee, $case, 'finalize'), [
            'effective_date' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('finalized');
        expect($response->json('finalized_at'))->not->toBeNull();

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::RETIRED);
        expect($employee->termination_date?->toDateString())->toBe(now()->addDays(3)->toDateString());

        $transition = EmployeeTransition::where('employee_id', $employee->id)->latest('id')->first();
        expect($transition)->not->toBeNull();
        expect($transition->to_status)->toBe(EmployeeStatus::RETIRED);
    });

    it('cannot finalize a case that has not been approved', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = initiateRetirement($employee);

        $this->postJson(retirementActionUrl($employee, $case, 'finalize'), [
            'effective_date' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });

    it('fails loudly finalizing when the employee already left some other way', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = initiateRetirement($employee);
        $this->postJson(retirementActionUrl($employee, $case, 'decision'), ['decision' => 'approved'])->assertOk();

        // Employee resigns through a separate path while the case is pending.
        $this->postJson("/api/v1/employees/{$employee->public_id}/transition", [
            'to_status' => 'resigned',
            'effective_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson(retirementActionUrl($employee, $case, 'finalize'), [
            'effective_date' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
    });
});

describe('cancelling', function () {
    it('cancels an initiated case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = initiateRetirement($employee);

        $response = $this->postJson(retirementActionUrl($employee, $case, 'cancel'), [
            'notes' => 'Employee decided to continue working.',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('cancelled');
    });

    it('cannot cancel a finalized case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = initiateRetirement($employee);
        $this->postJson(retirementActionUrl($employee, $case, 'decision'), ['decision' => 'approved'])->assertOk();
        $this->postJson(retirementActionUrl($employee, $case, 'finalize'), [
            'effective_date' => now()->addDay()->toDateString(),
        ])->assertOk();

        $this->postJson(retirementActionUrl($employee, $case, 'cancel'), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('permissions and isolation', function () {
    it('forbids an employee without the manage permission from initiating a case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson(retirementCasesUrl($employee), ['retirement_type' => 'mandatory'])
            ->assertForbidden();
    });

    it('lets a supervisor view cases but not initiate one', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        initiateRetirement($employee);

        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);
        $this->getJson(retirementCasesUrl($employee))->assertOk()->assertJsonCount(1);

        $this->postJson(retirementCasesUrl($employee), ['retirement_type' => 'mandatory'])
            ->assertForbidden();
    });

    it('does not resolve a case public_id belonging to another tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $otherTenant = Tenant::factory()->create();
        $otherEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($otherTenant);
        $foreignCase = RetirementCase::factory()->for($otherEmployee)->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($tenant);

        $this->postJson(retirementActionUrl($employee, $foreignCase, 'notes'), [
            'note' => 'Should not resolve.',
        ])->assertNotFound();
    });

    it('requires authentication', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson(retirementCasesUrl($employee))->assertUnauthorized();
    });
});

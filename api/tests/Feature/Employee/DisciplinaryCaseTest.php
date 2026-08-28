<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Models\Tenant;
use App\Services\CurrentTenant;

function casesUrl(Employee $employee): string
{
    return "/api/v1/employees/{$employee->public_id}/disciplinary-cases";
}

function caseActionUrl(Employee $employee, DisciplinaryCase $case, string $action): string
{
    return casesUrl($employee)."/{$case->public_id}/{$action}";
}

function openCase(Employee $employee, array $overrides = []): DisciplinaryCase
{
    $response = test()->postJson(casesUrl($employee), [
        'category' => 'misconduct',
        'description' => 'Repeated failure to follow the cash-handling procedure.',
        'incident_date' => '2026-08-01',
        ...$overrides,
    ])->assertCreated();

    return DisciplinaryCase::where('public_id', $response->json('public_id'))->firstOrFail();
}

describe('opening and investigating a case', function () {
    it('opens a case in reported status', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson(casesUrl($employee), [
            'category' => 'absenteeism',
            'description' => 'Absent without leave for three consecutive days.',
            'incident_date' => '2026-08-01',
            'reference_number' => 'DC/2026/001',
        ]);

        $response->assertCreated();
        expect($response->json('status'))->toBe('reported');
        expect($response->json('category'))->toBe('absenteeism');
        expect($response->json('reference_number'))->toBe('DC/2026/001');
        expect($response->json('investigation_notes'))->toBe([]);
        $response->assertJsonMissingPath('id');
    });

    it('moves reported to investigating on the first note, and stays there after later notes', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);

        $first = $this->postJson(caseActionUrl($employee, $case, 'notes'), [
            'note' => 'Interviewed the shift supervisor.',
        ])->assertOk();
        expect($first->json('status'))->toBe('investigating');
        expect($first->json('investigation_notes'))->toHaveCount(1);
        expect($first->json('investigation_notes.0.note'))->toBe('Interviewed the shift supervisor.');

        $second = $this->postJson(caseActionUrl($employee, $case, 'notes'), [
            'note' => 'Reviewed CCTV footage.',
        ])->assertOk();
        expect($second->json('status'))->toBe('investigating');
        expect($second->json('investigation_notes'))->toHaveCount(2);
    });

    it('rejects notes on an already-closed case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);

        $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'not_guilty',
        ])->assertOk();
        $this->postJson(caseActionUrl($employee, $case, 'close'), [])->assertOk();

        $this->postJson(caseActionUrl($employee, $case, 'notes'), [
            'note' => 'Too late.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('deciding a case', function () {
    it('rejects a sanction on a not-guilty decision', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);

        $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'not_guilty',
            'sanction_type' => 'written_warning',
        ])->assertUnprocessable()->assertJsonValidationErrors(['sanction_type']);
    });

    it('records a guilty decision with a sanction and moves the case to decided', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);

        $response = $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'guilty',
            'decision_notes' => 'Confirmed by CCTV footage and two witnesses.',
            'sanction_type' => 'written_warning',
            'sanction_details' => 'First formal warning on file.',
            'sanction_effective_date' => '2026-08-10',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('decided');
        expect($response->json('decision'))->toBe('guilty');
        expect($response->json('sanction_type'))->toBe('written_warning');
    });

    it('cannot record a second decision on an already-decided case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);

        $this->postJson(caseActionUrl($employee, $case, 'decision'), ['decision' => 'guilty'])->assertOk();

        $this->postJson(caseActionUrl($employee, $case, 'decision'), ['decision' => 'not_guilty'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('closing without an appeal', function () {
    it('closes a decided case directly', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), ['decision' => 'not_guilty'])->assertOk();

        $response = $this->postJson(caseActionUrl($employee, $case, 'close'), [
            'notes' => 'No appeal window requested.',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('closed');
        expect($response->json('closed_at'))->not->toBeNull();
    });

    it('terminates the employee when a termination sanction closes without appeal', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'guilty',
            'sanction_type' => 'termination',
            'sanction_effective_date' => '2026-09-01',
        ])->assertOk();

        $this->postJson(caseActionUrl($employee, $case, 'close'), [])->assertOk();

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::TERMINATED);
        expect($employee->termination_date?->toDateString())->toBe('2026-09-01');

        $transition = EmployeeTransition::where('employee_id', $employee->id)->latest('id')->first();
        expect($transition)->not->toBeNull();
        expect($transition->to_status)->toBe(EmployeeStatus::TERMINATED);
    });

    it('does not fail closing when the employee already left before the sanction landed', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::RESIGNED,
        ]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'guilty',
            'sanction_type' => 'termination',
            'sanction_effective_date' => '2026-09-01',
        ])->assertOk();

        // RESIGNED has no allowed transition to TERMINATED — closing must
        // still succeed, just without a status change nobody can apply.
        $this->postJson(caseActionUrl($employee, $case, 'close'), [])->assertOk();

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::RESIGNED);
    });

    it('requires resolving a pending appeal before the case can close', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), ['decision' => 'guilty'])->assertOk();
        $this->postJson(caseActionUrl($employee, $case, 'appeal'), ['grounds' => 'New evidence.'])->assertOk();

        $this->postJson(caseActionUrl($employee, $case, 'close'), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });
});

describe('appeals', function () {
    it('rejects an appeal before a decision exists', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);

        $this->postJson(caseActionUrl($employee, $case, 'appeal'), ['grounds' => 'Too early.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });

    it('upholding an appeal reverses the sanction and closes the case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'guilty',
            'sanction_type' => 'termination',
            'sanction_effective_date' => '2026-09-01',
        ])->assertOk();
        $this->postJson(caseActionUrl($employee, $case, 'appeal'), [
            'grounds' => 'The identification was mistaken.',
        ])->assertOk();

        $response = $this->postJson(caseActionUrl($employee, $case, 'appeal-decision'), [
            'outcome' => 'upheld',
            'decision_notes' => 'New witness cleared the employee.',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('closed');
        expect($response->json('appeal_status'))->toBe('upheld');
        expect($response->json('sanction_type'))->toBeNull();

        // The sanction never got a chance to apply — the employee is untouched.
        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::CONFIRMED);
    });

    it('denying an appeal leaves the sanction standing and closes the case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EmployeeStatus::CONFIRMED,
        ]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'guilty',
            'sanction_type' => 'termination',
            'sanction_effective_date' => '2026-09-01',
        ])->assertOk();
        $this->postJson(caseActionUrl($employee, $case, 'appeal'), ['grounds' => 'Appeal grounds.'])->assertOk();

        $response = $this->postJson(caseActionUrl($employee, $case, 'appeal-decision'), [
            'outcome' => 'denied',
        ]);

        $response->assertOk();
        expect($response->json('status'))->toBe('closed');
        expect($response->json('appeal_status'))->toBe('denied');
        expect($response->json('sanction_type'))->toBe('termination');

        $employee->refresh();
        expect($employee->status)->toBe(EmployeeStatus::TERMINATED);
    });

    it('rejects resolving an appeal that is not pending', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $case = openCase($employee);
        $this->postJson(caseActionUrl($employee, $case, 'decision'), ['decision' => 'guilty'])->assertOk();
        $this->postJson(caseActionUrl($employee, $case, 'appeal'), ['grounds' => 'Grounds.'])->assertOk();
        $this->postJson(caseActionUrl($employee, $case, 'appeal-decision'), ['outcome' => 'denied'])->assertOk();

        $this->postJson(caseActionUrl($employee, $case, 'appeal-decision'), ['outcome' => 'upheld'])
            ->assertUnprocessable()->assertJsonValidationErrors(['outcome']);
    });
});

describe('attribution names', function () {
    it('resolves note and decision attribution through the acting user\'s employee record', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $hrEmployee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Marta Bekele']);
        actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $tenant);
        $case = openCase($employee);

        $noted = $this->postJson(caseActionUrl($employee, $case, 'notes'), [
            'note' => 'Interviewed the shift supervisor.',
        ])->assertOk();
        expect($noted->json('investigation_notes.0.by_name'))->toBe('Marta Bekele');

        $decided = $this->postJson(caseActionUrl($employee, $case, 'decision'), [
            'decision' => 'guilty',
        ])->assertOk();
        expect($decided->json('decided_by'))->toBe('Marta Bekele');

        $listed = $this->getJson(casesUrl($employee))->assertOk();
        expect($listed->json('0.decided_by'))->toBe('Marta Bekele');
    });

    it('falls back to the acting user\'s email when they have no linked employee', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $hrUser = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $case = openCase($employee);

        $noted = $this->postJson(caseActionUrl($employee, $case, 'notes'), [
            'note' => 'No employee record for this HR login.',
        ])->assertOk();
        expect($noted->json('investigation_notes.0.by_name'))->toBe($hrUser->email);
    });
});

describe('permissions and isolation', function () {
    it('forbids an employee without the manage permission from opening a case', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson(casesUrl($employee), [
            'category' => 'misconduct',
            'description' => 'x',
            'incident_date' => '2026-08-01',
        ])->assertForbidden();
    });

    it('lets a supervisor view cases but not open one', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        openCase($employee);

        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);
        $this->getJson(casesUrl($employee))->assertOk()->assertJsonCount(1);

        $this->postJson(casesUrl($employee), [
            'category' => 'misconduct',
            'description' => 'x',
            'incident_date' => '2026-08-01',
        ])->assertForbidden();
    });

    it('does not resolve a case public_id belonging to another tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $otherTenant = Tenant::factory()->create();
        $otherEmployee = Employee::factory()->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($otherTenant);
        $foreignCase = DisciplinaryCase::factory()->for($otherEmployee)->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($tenant);

        $this->postJson(caseActionUrl($employee, $foreignCase, 'notes'), [
            'note' => 'Should not resolve.',
        ])->assertNotFound();
    });

    it('requires authentication', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->getJson(casesUrl($employee))->assertUnauthorized();
    });
});

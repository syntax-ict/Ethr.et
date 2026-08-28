<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeSalaryStep;

function stepScaleActionUrl(Employee $employee): string
{
    return "/api/v1/employees/{$employee->public_id}/personnel-actions";
}

describe('salary-step scale integration with personnel actions', function () {
    it('auto-fills the salary from the grade scale when the step is defined and no salary is given', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 3_000_000,
        ]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 3, 'salary_cents' => 1_800_000]);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'grade_id' => $grade->id,
            'salary_cents' => 1_500_000,
            'salary_step' => 2,
        ]);

        $response = $this->postJson(stepScaleActionUrl($employee), [
            'type' => 'salary_step_increment',
            'effective_date' => '2026-08-01',
            'salary_step' => 3,
        ]);

        $response->assertCreated();
        // Salary came from the scale, not from the request.
        expect($response->json('changes.salary'))->toBe(['from' => 1_500_000, 'to' => 1_800_000]);

        $employee->refresh();
        expect($employee->salary_cents)->toBe(1_800_000);
        expect($employee->salary_step)->toBe(3);
    });

    it('rejects a manual salary that contradicts the grade scale for that step', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 3_000_000,
        ]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 3, 'salary_cents' => 1_800_000]);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'grade_id' => $grade->id,
            'salary_step' => 2,
        ]);

        $this->postJson(stepScaleActionUrl($employee), [
            'type' => 'salary_step_increment',
            'effective_date' => '2026-08-01',
            'salary_step' => 3,
            'new_salary_cents' => 1_900_000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_salary_cents']);
    });

    it('accepts a manual salary that matches the grade scale', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 3_000_000,
        ]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 3, 'salary_cents' => 1_800_000]);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'grade_id' => $grade->id,
            'salary_step' => 2,
        ]);

        $this->postJson(stepScaleActionUrl($employee), [
            'type' => 'salary_step_increment',
            'effective_date' => '2026-08-01',
            'salary_step' => 3,
            'new_salary_cents' => 1_800_000,
        ])->assertCreated();
    });

    it('resolves the step against the grade the action itself moves the employee to', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $oldGrade = Grade::factory()->create(['tenant_id' => $tenant->id, 'name' => 'G6', 'max_salary_cents' => 3_000_000]);
        $newGrade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'G7',
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 3_000_000,
        ]);
        // Step 1 on the *new* grade pays 2,000,000.
        GradeSalaryStep::factory()->for($newGrade)->create(['step' => 1, 'salary_cents' => 2_000_000]);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'grade_id' => $oldGrade->id,
            'salary_cents' => 1_500_000,
            'salary_step' => 1,
        ]);

        $response = $this->postJson(stepScaleActionUrl($employee), [
            'type' => 'promotion',
            'effective_date' => '2026-08-01',
            'grade_public_id' => $newGrade->public_id,
            'salary_step' => 1,
        ]);

        $response->assertCreated();
        // Salary auto-filled from the *new* grade's scale, even though the
        // step number is unchanged (the employee was already step 1).
        expect($response->json('changes.salary'))->toBe(['from' => 1_500_000, 'to' => 2_000_000]);
    });

    it('falls back to the typed salary when the grade has no scale defined', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        // Grade with no salary-step scale rows at all.
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 3_000_000,
        ]);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'grade_id' => $grade->id,
            'salary_cents' => 1_500_000,
            'salary_step' => 1,
        ]);

        $response = $this->postJson(stepScaleActionUrl($employee), [
            'type' => 'salary_step_increment',
            'effective_date' => '2026-08-01',
            'salary_step' => 2,
            'new_salary_cents' => 1_700_000,
        ]);

        $response->assertCreated();
        expect($response->json('changes.salary'))->toBe(['from' => 1_500_000, 'to' => 1_700_000]);
        expect($response->json('changes.salary_step'))->toBe(['from' => 1, 'to' => 2]);
    });
});

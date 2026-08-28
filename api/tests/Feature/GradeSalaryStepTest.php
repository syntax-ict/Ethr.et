<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Grade;
use App\Models\GradeSalaryStep;
use App\Models\Tenant;
use App\Services\CurrentTenant;

function salaryStepsUrl(Grade $grade): string
{
    return "/api/v1/organization/grades/{$grade->public_id}/salary-steps";
}

describe('grade salary steps CRUD', function () {
    it('adds a step within the grade salary range', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 2_000_000,
        ]);

        $response = $this->postJson(salaryStepsUrl($grade), [
            'step' => 1,
            'salary_cents' => 1_000_000,
        ]);

        $response->assertCreated();
        expect($response->json('step'))->toBe(1);
        expect($response->json('salary_cents'))->toBe(1_000_000);
        $response->assertJsonMissingPath('id');
    });

    it('rejects a step salary outside the grade range', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 2_000_000,
        ]);

        $this->postJson(salaryStepsUrl($grade), [
            'step' => 1,
            'salary_cents' => 2_500_000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['salary_cents']);
    });

    it('rejects a step that pays less than the step below it', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 2_000_000,
        ]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 1, 'salary_cents' => 1_200_000]);

        $this->postJson(salaryStepsUrl($grade), [
            'step' => 2,
            'salary_cents' => 1_100_000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['salary_cents']);
    });

    it('rejects a step that pays more than the step above it', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 2_000_000,
        ]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 3, 'salary_cents' => 1_500_000]);

        $this->postJson(salaryStepsUrl($grade), [
            'step' => 2,
            'salary_cents' => 1_600_000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['salary_cents']);
    });

    it('rejects a duplicate step number for the same grade', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 2_000_000,
        ]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 1, 'salary_cents' => 1_000_000]);

        $this->postJson(salaryStepsUrl($grade), [
            'step' => 1,
            'salary_cents' => 1_100_000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['step']);
    });

    it('lists steps ordered by step number', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'max_salary_cents' => 3_000_000]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 2, 'salary_cents' => 1_200_000]);
        GradeSalaryStep::factory()->for($grade)->create(['step' => 1, 'salary_cents' => 1_000_000]);

        $response = $this->getJson(salaryStepsUrl($grade))->assertOk();

        // Resources are unwrapped app-wide (JsonResource::withoutWrapping), so
        // the list is a bare array, ordered by step.
        expect($response->json('0.step'))->toBe(1);
        expect($response->json('1.step'))->toBe(2);
    });

    it('updates a step, excluding itself from the monotonic check', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create([
            'tenant_id' => $tenant->id,
            'min_salary_cents' => 1_000_000,
            'max_salary_cents' => 2_000_000,
        ]);
        $step = GradeSalaryStep::factory()->for($grade)->create(['step' => 1, 'salary_cents' => 1_000_000]);

        $response = $this->putJson(salaryStepsUrl($grade)."/{$step->public_id}", [
            'step' => 1,
            'salary_cents' => 1_050_000,
        ]);

        $response->assertOk();
        expect($response->json('salary_cents'))->toBe(1_050_000);
    });

    it('deletes a step as tenant_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'max_salary_cents' => 3_000_000]);
        $step = GradeSalaryStep::factory()->for($grade)->create();

        $this->deleteJson(salaryStepsUrl($grade)."/{$step->public_id}")
            ->assertNoContent();
    });

    it('forbids an employee from creating a step', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'max_salary_cents' => 3_000_000]);

        $this->postJson(salaryStepsUrl($grade), [
            'step' => 1,
            'salary_cents' => 1_000_000,
        ])->assertForbidden();
    });

    it('does not resolve a step belonging to another grade', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'max_salary_cents' => 3_000_000]);
        $otherGrade = Grade::factory()->create(['tenant_id' => $tenant->id, 'max_salary_cents' => 3_000_000]);
        $foreignStep = GradeSalaryStep::factory()->for($otherGrade)->create();

        $this->putJson(salaryStepsUrl($grade)."/{$foreignStep->public_id}", [
            'step' => 1,
            'salary_cents' => 1_000_000,
        ])->assertNotFound();
    });

    it('does not resolve a step belonging to another tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $grade = Grade::factory()->create(['tenant_id' => $tenant->id, 'max_salary_cents' => 3_000_000]);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant);
        $otherGrade = Grade::factory()->create(['tenant_id' => $otherTenant->id, 'max_salary_cents' => 3_000_000]);
        $foreignStep = GradeSalaryStep::factory()->for($otherGrade)->create();
        app(CurrentTenant::class)->set($tenant);

        $this->putJson(salaryStepsUrl($grade)."/{$foreignStep->public_id}", [
            'step' => 1,
            'salary_cents' => 1_000_000,
        ])->assertNotFound();
    });
});

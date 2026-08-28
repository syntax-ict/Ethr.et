<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;

function personnelActionsUrl(Employee $employee): string
{
    return "/api/v1/employees/{$employee->public_id}/personnel-actions";
}

describe('recorded_by attribution', function () {
    it('resolves recorded_by through the acting user\'s employee record', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
        $hrEmployee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Marta Bekele']);
        actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $tenant);

        $response = $this->postJson(personnelActionsUrl($employee), [
            'type' => 'promotion',
            'effective_date' => '2026-08-01',
            'new_salary_cents' => 2_000_00,
        ]);

        $response->assertCreated();
        expect($response->json('recorded_by'))->toBe('Marta Bekele');

        $listed = $this->getJson(personnelActionsUrl($employee))->assertOk();
        expect($listed->json('0.recorded_by'))->toBe('Marta Bekele');
    });

    it('falls back to the acting user\'s email when they have no linked employee', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
        $hrUser = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson(personnelActionsUrl($employee), [
            'type' => 'promotion',
            'effective_date' => '2026-08-01',
            'new_salary_cents' => 2_000_00,
        ]);

        $response->assertCreated();
        expect($response->json('recorded_by'))->toBe($hrUser->email);
    });
});

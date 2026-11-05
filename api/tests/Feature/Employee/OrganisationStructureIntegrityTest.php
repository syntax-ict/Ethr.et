<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\Team;

/*
 * A-Z review 2026-11-03: a team, grade or cost centre with staff could be
 * deleted (C-09; a cost centre is removed for good, and every employee lost
 * the allocation payroll reporting uses), and two people could supervise
 * each other (C-08).
 */

dataset('staffed units', [
    'team' => [fn (int $tenantId) => Team::factory()->create(['tenant_id' => $tenantId]), 'team_id', 'organization/teams'],
    'grade' => [fn (int $tenantId) => Grade::factory()->create(['tenant_id' => $tenantId]), 'grade_id', 'organization/grades'],
    'cost centre' => [fn (int $tenantId) => CostCenter::factory()->create(['tenant_id' => $tenantId]), 'cost_center_id', 'organization/cost-centers'],
]);

it('refuses to delete a unit current employees belong to', function (Closure $make, string $column, string $path) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);
    $unit = $make($tenant->id);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, $column => $unit->id, 'status' => EmployeeStatus::CONFIRMED->value,
    ]);

    $this->deleteJson("/api/v1/{$path}/{$unit->public_id}")
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://ethr.et/errors/org-unit-in-use');

    expect($employee->fresh()->{$column})->toBe($unit->id);
})->with('staffed units');

it('still deletes a unit nobody belongs to', function (Closure $make, string $column, string $path) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);
    $unit = $make($tenant->id);

    $this->deleteJson("/api/v1/{$path}/{$unit->public_id}")->assertNoContent();
})->with('staffed units');

it('refuses a supervisor the employee already supervises, directly or further down', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $top = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $middle = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $top->id]);
    $bottom = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $middle->id]);

    $this->putJson("/api/v1/employees/{$top->public_id}", ['supervisor_id' => $middle->public_id])
        ->assertUnprocessable()->assertJsonValidationErrors('supervisor_id');
    $this->putJson("/api/v1/employees/{$top->public_id}", ['supervisor_id' => $bottom->public_id])
        ->assertUnprocessable()->assertJsonValidationErrors('supervisor_id');

    expect($top->fresh()->supervisor_id)->toBeNull();
});

it('still moves someone under a colleague outside their own line', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $top = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $middle = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $top->id]);
    $peer = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $top->id]);

    $this->putJson("/api/v1/employees/{$middle->public_id}", ['supervisor_id' => $peer->public_id])->assertOk();

    expect($middle->fresh()->supervisor_id)->toBe($peer->id);
});

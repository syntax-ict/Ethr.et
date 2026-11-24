<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\User;

// At deploy no job title has a role, so the plain employee logins are the ones
// that would follow a title anyway. Every other account stays as it was set.

function followsPositionMigration(): object
{
    return require database_path('migrations/2026_11_24_000002_add_role_follows_position_to_users.php');
}

it('marks plain employee logins as following and leaves every other account alone', function () {
    $tenant = createTenant();
    $employee = fn () => Employee::factory()->create(['tenant_id' => $tenant->id])->id;
    $custom = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Custom', 'org_scope' => 'tenant']);

    $plain = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE, 'employee_id' => $employee()]);
    $noEmployee = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
    $withCustom = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE, 'employee_id' => $employee(), 'custom_role_id' => $custom->id]);
    $supervisor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::SUPERVISOR, 'employee_id' => $employee()]);
    $deleted = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE, 'employee_id' => $employee()]);
    $deleted->delete();

    followsPositionMigration()->down();
    followsPositionMigration()->up();

    $flag = fn (int $id) => (bool) DB::table('users')->where('id', $id)->value('role_follows_position');

    expect($flag($plain->id))->toBeTrue()
        ->and($flag($noEmployee->id))->toBeFalse()
        ->and($flag($withCustom->id))->toBeFalse()
        ->and($flag($supervisor->id))->toBeFalse()
        ->and($flag($deleted->id))->toBeFalse();
});

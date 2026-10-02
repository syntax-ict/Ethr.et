<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;

/*
 * Audit N11: `POST /employees/bulk-update` validated `department_id`,
 * `branch_id` and `employee_ids.*` with unscoped `exists` rules, then resolved
 * them through the tenant-scoped models — so another tenant's id passed
 * validation and was silently ignored: 200, the field (or the employee) dropped
 * without a word. Now 422, with the message a nonexistent id gets, so the
 * response cannot confirm an id exists in some other tenant.
 */

const BULK_NO_SUCH_ID = '01JNOSUCHPUBLICIDXXXXXXXXX';

dataset('bulk relation fields', [
    'department_id' => ['department_id', Department::class],
    'branch_id' => ['branch_id', Branch::class],
]);

test('bulk update refuses a department or branch of another tenant', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = $modelClass::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$employee->public_id],
        $field => $foreign->public_id,
        'status' => 'probation',
    ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

    // The old code applied the status and dropped the field.
    expect($employee->fresh()->status->value)->not->toBe('probation');
})->with('bulk relation fields');

test('another tenant\'s department or branch gets the nonexistent-id message', function (string $field, string $modelClass) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = $modelClass::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

    $missing = $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$employee->public_id],
        $field => BULK_NO_SUCH_ID,
    ])->assertUnprocessable()->json("errors.{$field}");

    $crossTenant = $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$employee->public_id],
        $field => $foreign->public_id,
    ])->assertUnprocessable()->json("errors.{$field}");

    expect($crossTenant)->toBe($missing);
})->with('bulk relation fields');

test('bulk update refuses an employee of another tenant, with the nonexistent-id message', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $own = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = Employee::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

    $missing = $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$own->public_id, BULK_NO_SUCH_ID],
        'status' => 'probation',
    ])->assertUnprocessable()->json('errors')['employee_ids.1'];

    $crossTenant = $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$own->public_id, $foreign->public_id],
        'status' => 'probation',
    ])->assertUnprocessable()->json('errors')['employee_ids.1'];

    expect($crossTenant)->toBe($missing)
        ->and($own->fresh()->status->value)->not->toBe('probation');
});

test('bulk update with same-tenant ids still applies', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$employee->public_id],
        'department_id' => $department->public_id,
    ])->assertOk()->assertJsonPath('updated', 1);

    expect($employee->fresh()->department_id)->toBe($department->id);
});

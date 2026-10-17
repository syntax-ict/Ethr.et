<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Events\EmployeeTransitioned;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Models\Permission;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;

/*
 * Capability audit 2026-10-17: `POST /employees/bulk-update` authorised with
 * `employee.update` alone and then ran one `Employee::whereIn()->update()`.
 * So a status change skipped `EmployeeStatus::allowedTransitions()`, wrote no
 * `EmployeeTransition` row, set no `termination_date` and dispatched no
 * `EmployeeTransitioned`; the requester's org scope was never applied; and the
 * audit log recorded a count, not who changed or from what. A status change
 * now goes through `EmployeeTransitionService`, the code the single-employee
 * transition endpoint runs, one employee at a time.
 */

/**
 * A custom role scoped to one department, holding the given abilities, and a
 * login on it whose own employee sits in that department.
 *
 * @param  list<string>  $abilities
 * @return array{0: Department, 1: Department}
 */
function bulkScopedManager(Tenant $tenant, array $abilities): array
{
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);

    $role = CustomRole::create([
        'tenant_id' => $tenant->id,
        'name' => 'Department manager',
        'org_scope' => OrgScope::DEPARTMENT->value,
    ]);
    $role->permissions()->sync(Permission::whereIn('name', $abilities)->pluck('id'));

    actingAsUser([
        'role' => UserRole::EMPLOYEE,
        'custom_role_id' => $role->id,
        'employee_id' => $head->id,
    ], $tenant);

    return [$mine, $theirs];
}

test('a bulk status change refuses a transition the status machine does not allow', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $left = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => EmployeeStatus::TERMINATED,
    ]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$left->public_id],
        'status' => EmployeeStatus::CONFIRMED->value,
    ])->assertOk()
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('failed', 1)
        ->assertJsonPath('results.0.employee_id', $left->public_id)
        ->assertJsonPath('results.0.outcome', 'failed');

    expect($left->fresh()->status)->toBe(EmployeeStatus::TERMINATED)
        ->and(EmployeeTransition::where('employee_id', $left->id)->exists())->toBeFalse();
});

test('a bulk termination writes the transition, sets the termination date and dispatches the event', function () {
    Event::fake([EmployeeTransitioned::class]);

    $tenant = createTenant();
    $actor = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $first = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
    $second = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::PROBATION]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$first->public_id, $second->public_id],
        'status' => EmployeeStatus::TERMINATED->value,
        'effective_date' => '2026-10-31',
        'reason' => 'Branch closed',
    ])->assertOk()
        ->assertJsonPath('updated', 2)
        ->assertJsonPath('failed', 0);

    foreach ([[$first, 'confirmed'], [$second, 'probation']] as [$employee, $from]) {
        $fresh = $employee->fresh();
        expect($fresh->status)->toBe(EmployeeStatus::TERMINATED)
            ->and($fresh->termination_date?->toDateString())->toBe('2026-10-31');

        $transition = EmployeeTransition::where('employee_id', $employee->id)->sole();
        expect($transition->from_status->value)->toBe($from)
            ->and($transition->to_status)->toBe(EmployeeStatus::TERMINATED)
            ->and($transition->reason)->toBe('Branch closed')
            ->and($transition->effective_date->toDateString())->toBe('2026-10-31')
            ->and($transition->approved_by)->toBe($actor->id);
    }

    Event::assertDispatchedTimes(EmployeeTransitioned::class, 2);
    Event::assertDispatched(
        EmployeeTransitioned::class,
        fn (EmployeeTransitioned $event) => $event->employee->is($first),
    );
});

test('a bulk status change with no effective date takes the organisation\'s today', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);

    $this->travelTo(now('UTC')->setDate(2026, 10, 17)->setTime(22, 30));

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$employee->public_id],
        'status' => EmployeeStatus::RESIGNED->value,
    ])->assertOk()->assertJsonPath('updated', 1);

    // 22:30 UTC is 01:30 the next day in Addis Ababa.
    expect($employee->fresh()->termination_date?->toDateString())->toBe('2026-10-18');
});

test('one employee\'s refused transition does not stop the others', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $current = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
    $retired = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::RETIRED]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$current->public_id, $retired->public_id],
        'status' => EmployeeStatus::SUSPENDED->value,
    ])->assertOk()
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('failed', 1)
        ->assertJsonPath('results.0.outcome', 'updated')
        ->assertJsonPath('results.1.employee_id', $retired->public_id)
        ->assertJsonPath('results.1.outcome', 'failed')
        ->assertJsonPath('results.1.error', __('employee.invalid_transition', ['from' => 'retired', 'to' => 'suspended']));

    expect($current->fresh()->status)->toBe(EmployeeStatus::SUSPENDED)
        ->and($retired->fresh()->status)->toBe(EmployeeStatus::RETIRED);
});

test('a refused transition rolls back that employee\'s department move with it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $from = Department::factory()->create(['tenant_id' => $tenant->id]);
    $to = Department::factory()->create(['tenant_id' => $tenant->id]);
    $retired = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => EmployeeStatus::RETIRED,
        'department_id' => $from->id,
    ]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$retired->public_id],
        'department_id' => $to->public_id,
        'status' => EmployeeStatus::CONFIRMED->value,
    ])->assertOk()->assertJsonPath('failed', 1);

    expect($retired->fresh()->department_id)->toBe($from->id);
});

test('an employee already in the requested status is reported unchanged, with no transition', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::SUSPENDED]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$employee->public_id],
        'status' => EmployeeStatus::SUSPENDED->value,
    ])->assertOk()
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('failed', 0)
        ->assertJsonPath('results.0.outcome', 'unchanged');

    expect(EmployeeTransition::where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('a scoped manager cannot bulk update an employee outside their scope', function () {
    $tenant = createTenant();
    [$mine, $theirs] = bulkScopedManager($tenant, ['employee.viewAny', 'employee.update']);
    $target = Department::factory()->create(['tenant_id' => $tenant->id]);

    $inside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
    $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$inside->public_id, $outside->public_id],
        'department_id' => $target->public_id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['employee_ids.1'])
        ->assertJsonMissingValidationErrors(['employee_ids.0']);

    // Refused as a whole: the employee in scope did not move either.
    expect($inside->fresh()->department_id)->toBe($mine->id)
        ->and($outside->fresh()->department_id)->toBe($theirs->id);
});

test('an out-of-scope employee gets the nonexistent-id message', function () {
    $tenant = createTenant();
    [$mine, $theirs] = bulkScopedManager($tenant, ['employee.viewAny', 'employee.update']);

    $inside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
    $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);

    $missing = $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$inside->public_id, '01JNOSUCHPUBLICIDXXXXXXXXX'],
        'department_id' => $mine->public_id,
    ])->assertUnprocessable()->json('errors')['employee_ids.1'];

    $outOfScope = $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$inside->public_id, $outside->public_id],
        'department_id' => $mine->public_id,
    ])->assertUnprocessable()->json('errors')['employee_ids.1'];

    expect($outOfScope)->toBe($missing);
});

test('a scoped manager can bulk update employees inside their scope', function () {
    $tenant = createTenant();
    [$mine] = bulkScopedManager($tenant, ['employee.viewAny', 'employee.update']);
    $target = Department::factory()->create(['tenant_id' => $tenant->id]);

    $inside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$inside->public_id],
        'department_id' => $target->public_id,
    ])->assertOk()->assertJsonPath('updated', 1);

    expect($inside->fresh()->department_id)->toBe($target->id);
});

test('a bulk status change needs employee.transition, as the single transition does', function () {
    $tenant = createTenant();
    [$mine] = bulkScopedManager($tenant, ['employee.viewAny', 'employee.update']);

    $inside = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $mine->id,
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$inside->public_id],
        'status' => EmployeeStatus::TERMINATED->value,
    ])->assertForbidden();

    expect($inside->fresh()->status)->toBe(EmployeeStatus::CONFIRMED);
});

test('each employee changed gets its own audit entry with before and after', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $from = Department::factory()->create(['tenant_id' => $tenant->id]);
    $to = Department::factory()->create(['tenant_id' => $tenant->id]);
    $first = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $from->id,
        'status' => EmployeeStatus::CONFIRMED,
    ]);
    $second = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => null,
        'status' => EmployeeStatus::PROBATION,
    ]);

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => [$first->public_id, $second->public_id],
        'department_id' => $to->public_id,
        'status' => EmployeeStatus::TERMINATED->value,
    ])->assertOk()->assertJsonPath('updated', 2);

    $entry = AuditLog::where('action', 'employee.bulk_updated')
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $first->id)
        ->sole();

    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->payload['before'])->toBe(['department_id' => $from->public_id, 'status' => 'confirmed'])
        ->and($entry->payload['after'])->toBe(['department_id' => $to->public_id, 'status' => 'terminated']);

    $secondEntry = AuditLog::where('action', 'employee.bulk_updated')
        ->where('auditable_id', $second->id)
        ->sole();
    expect($secondEntry->payload['before'])->toBe(['department_id' => null, 'status' => 'probation']);

    // The transition itself is audited as every other transition is.
    expect(AuditLog::where('action', 'employee.transitioned')->count())->toBe(2);
});

test('the single transition endpoint still refuses an illegal transition and records a legal one', function () {
    Event::fake([EmployeeTransitioned::class]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $left = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::TERMINATED]);
    $current = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);

    $this->postJson("/api/v1/employees/{$left->public_id}/transition", [
        'to_status' => 'confirmed',
        'effective_date' => '2026-10-17',
    ])->assertUnprocessable()->assertJsonPath('type', 'https://ethr.et/errors/invalid-transition');

    $this->postJson("/api/v1/employees/{$current->public_id}/transition", [
        'to_status' => 'retired',
        'effective_date' => '2026-10-20',
    ])->assertCreated()->assertJsonPath('to_status', 'retired');

    expect($current->fresh()->termination_date?->toDateString())->toBe('2026-10-20');
    Event::assertDispatchedTimes(EmployeeTransitioned::class, 1);
});

test('a bulk update takes at most 200 employees, so it cannot time out halfway', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $ids = Employee::factory()->count(201)->create(['tenant_id' => $tenant->id])->pluck('public_id')->all();

    $this->postJson('/api/v1/employees/bulk-update', [
        'employee_ids' => $ids,
        'status' => EmployeeStatus::SUSPENDED->value,
    ])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids']);

    expect(Employee::query()->where('status', EmployeeStatus::SUSPENDED->value)->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Support\Carbon;

/**
 * Audit N9. Shift assignments could be made and never seen properly again:
 *
 *   - `schedule()` loaded `shift` but not `rotation`, so a rotation assignment
 *     arrived with neither key and showed as "Unknown Shift";
 *   - the resource had no `public_id` and no assignee, so no page could say
 *     whom an assignment was for, or address one to end it;
 *   - deleting a shift (or rotation) that was still assigned left those
 *     assignments pointing at a row nothing could load;
 *   - the assign requests answered a foreign or unknown id with 404, and the
 *     rotation one validated `rotation_id` with an unscoped `exists:`.
 */
function shiftMgmtUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** @param  array<string, mixed>  $attributes */
function shiftMgmtAssign(Tenant $tenant, Shift|ShiftRotation $target, Employee|Department|Branch $assignee, array $attributes = []): ShiftAssignment
{
    return ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $target instanceof Shift ? $target->id : null,
        'shift_rotation_id' => $target instanceof ShiftRotation ? $target->id : null,
        'anchor_date' => $target instanceof ShiftRotation ? '2026-09-01' : null,
        'assignable_type' => $assignee::class,
        'assignable_id' => $assignee->id,
        ...$attributes,
    ]);
}

beforeEach(function () {
    // 13:00 in Addis Ababa on 2026-10-07.
    Carbon::setTestNow('2026-10-07 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── What the schedule returns ────────────────────────────────────────────────

it('returns a rotation assignment with its rotation, and a fixed one with its shift, both keys always present', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Four on four off']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    shiftMgmtAssign($tenant, $shift, $employee, ['effective_from' => '2026-09-01']);
    shiftMgmtAssign($tenant, $rotation, $employee, ['effective_from' => '2026-09-02']);

    $rows = test()->getJson(shiftMgmtUrl($tenant, '/shifts/schedule'))->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toHaveKeys(['shift', 'rotation'])
        ->and($rows[0]['shift']['name'])->toBe('Morning')
        ->and($rows[0]['rotation'])->toBeNull()
        ->and($rows[1])->toHaveKeys(['shift', 'rotation'])
        ->and($rows[1]['is_rotation'])->toBeTrue()
        ->and($rows[1]['shift'])->toBeNull()
        ->and($rows[1]['rotation']['name'])->toBe('Four on four off');
});

it('says whom each assignment is for, by public_id, without a numeric key', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Nursing']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole']);
    $a = shiftMgmtAssign($tenant, $shift, $employee, ['effective_from' => '2026-09-01']);
    shiftMgmtAssign($tenant, $shift, $department, ['effective_from' => '2026-09-02']);
    shiftMgmtAssign($tenant, $shift, $branch, ['effective_from' => '2026-09-03']);

    $response = test()->getJson(shiftMgmtUrl($tenant, '/shifts/schedule'))->assertOk();
    $rows = $response->json('data');

    expect($rows[0]['public_id'])->toBe($a->public_id)
        ->and($rows[0]['assignee'])->toBe(['type' => 'employee', 'public_id' => $employee->public_id, 'name' => 'Abebe Kebede'])
        ->and($rows[1]['assignee'])->toBe(['type' => 'department', 'public_id' => $department->public_id, 'name' => 'Nursing'])
        ->and($rows[2]['assignee'])->toBe(['type' => 'branch', 'public_id' => $branch->public_id, 'name' => 'Bole'])
        ->and($rows[0])->not->toHaveKey('id')
        ->and($rows[0])->not->toHaveKey('assignable_id')
        ->and($rows[0])->not->toHaveKey('tenant_id');
});

it('still names the shift and the person on an assignment whose shift and employee were since deleted', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Night']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Former Person']);
    shiftMgmtAssign($tenant, $shift, $employee, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);
    shiftMgmtAssign($tenant, $shift, Employee::factory()->create(['tenant_id' => $tenant->id]), [
        'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31',
    ]);
    $shift->delete();
    $employee->delete();

    $rows = test()->getJson(shiftMgmtUrl($tenant, '/shifts/schedule'))->assertOk()->json('data');

    expect($rows[0]['shift']['name'])->toBe('Old Night')
        ->and($rows[0]['assignee']['name'])->toBe('Former Person');
});

it('returns the new assignment with its id and assignee from both assign endpoints', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Hana']);

    test()->postJson(shiftMgmtUrl($tenant, '/shifts/assign'), [
        'shift_public_id' => $shift->public_id,
        'assignable_type' => 'employee',
        'assignable_public_id' => $employee->public_id,
        'effective_from' => '2026-10-10',
    ])->assertCreated()
        ->assertJsonPath('assignee.name', 'Hana')
        ->assertJsonPath('rotation', null)
        ->assertJsonPath('public_id', ShiftAssignment::where('shift_id', $shift->id)->value('public_id'));

    test()->postJson(shiftMgmtUrl($tenant, '/shift-rotations/assign'), [
        'rotation_id' => $rotation->public_id,
        'assignable_type' => 'employee',
        'assignable_id' => $employee->public_id,
        'effective_from' => '2026-10-10',
    ])->assertCreated()
        ->assertJsonPath('assignee.public_id', $employee->public_id)
        ->assertJsonPath('shift', null)
        ->assertJsonPath('rotation.public_id', $rotation->public_id);
});

// ── Deleting a shift or rotation that is still assigned ─────────────────────

it('refuses to delete a shift with a current or upcoming assignment', function (array $dates) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    shiftMgmtAssign($tenant, $shift, Employee::factory()->create(['tenant_id' => $tenant->id]), $dates);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/{$shift->public_id}"))
        ->assertStatus(409)
        ->assertJsonPath('status', 409)
        ->assertJsonPath('detail', fn (string $d) => str_contains($d, '1 assignment is current or upcoming'));

    expect(Shift::find($shift->id))->not->toBeNull();
})->with([
    'open-ended' => [['effective_from' => '2026-01-01', 'effective_to' => null]],
    'ending today' => [['effective_from' => '2026-01-01', 'effective_to' => '2026-10-07']],
    'starting next month' => [['effective_from' => '2026-11-01', 'effective_to' => null]],
]);

it('deletes a shift whose assignments all ended before today', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    shiftMgmtAssign($tenant, $shift, Employee::factory()->create(['tenant_id' => $tenant->id]), [
        'effective_from' => '2026-01-01', 'effective_to' => '2026-10-06',
    ]);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/{$shift->public_id}"))->assertNoContent();

    expect(Shift::find($shift->id))->toBeNull()
        ->and(Shift::withTrashed()->find($shift->id))->not->toBeNull();
});

it('judges "today" in the tenant\'s day, not UTC\'s', function () {
    // 01:00 in Addis on 2026-10-07, still 2026-10-06 in UTC. An assignment
    // whose last day was 2026-10-06 has ended for this tenant.
    Carbon::setTestNow('2026-10-06 22:00:00');
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    shiftMgmtAssign($tenant, $shift, Employee::factory()->create(['tenant_id' => $tenant->id]), [
        'effective_from' => '2026-01-01', 'effective_to' => '2026-10-06',
    ]);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/{$shift->public_id}"))->assertNoContent();
});

it('refuses to delete a shift that is a step in a rotation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ward Rota', 'cycle_days' => 2]);
    ShiftRotationStep::factory()->create([
        'tenant_id' => $tenant->id, 'shift_rotation_id' => $rotation->id, 'day_offset' => 0, 'shift_id' => $shift->id,
    ]);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/{$shift->public_id}"))
        ->assertStatus(409)
        ->assertJsonPath('detail', fn (string $d) => str_contains($d, 'Ward Rota'));
});

it('still refuses a shift delete to a role without shift.delete', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/{$shift->public_id}"))->assertForbidden();
});

it('refuses to delete a rotation with a current assignment, and allows it once that has ended', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);
    $assignment = shiftMgmtAssign($tenant, $rotation, Employee::factory()->create(['tenant_id' => $tenant->id]), [
        'effective_from' => '2026-09-01',
    ]);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shift-rotations/{$rotation->public_id}"))->assertStatus(409);

    $assignment->update(['effective_to' => '2026-10-01']);

    test()->deleteJson(shiftMgmtUrl($tenant, "/shift-rotations/{$rotation->public_id}"))->assertNoContent();
});

// ── Assign requests: foreign and unknown ids are 422s, and indistinguishable ─

it('rejects another tenant\'s rotation with the same 422 as a made-up one', function () {
    $tenant = createTenant(['subdomain' => 'rotown']);
    $other = createTenant(['subdomain' => 'rotother']);
    $foreign = ShiftRotation::factory()->create(['tenant_id' => $other->id]);
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $send = fn (string $rotationId) => test()->postJson(shiftMgmtUrl($tenant, '/shift-rotations/assign'), [
        'rotation_id' => $rotationId,
        'assignable_type' => 'employee',
        'assignable_id' => $employee->public_id,
        'effective_from' => '2026-10-10',
    ])->assertUnprocessable()->assertJsonValidationErrors('rotation_id')->json('errors.rotation_id');

    expect($send($foreign->public_id))->toBe($send('01HZNOTAROTATION0000000000'));
    expect(ShiftAssignment::count())->toBe(0);
});

it('rejects a soft-deleted rotation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);
    $rotation->delete();

    test()->postJson(shiftMgmtUrl($tenant, '/shift-rotations/assign'), [
        'rotation_id' => $rotation->public_id,
        'assignable_type' => 'employee',
        'assignable_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->public_id,
        'effective_from' => '2026-10-10',
    ])->assertUnprocessable()->assertJsonValidationErrors('rotation_id');
});

it('rejects an unknown or foreign assignee on the rotation assign with one 422', function (string $type) {
    $tenant = createTenant(['subdomain' => 'asgown']);
    $other = createTenant(['subdomain' => 'asgother']);
    $foreign = match ($type) {
        'employee' => Employee::factory()->create(['tenant_id' => $other->id]),
        'department' => Department::factory()->create(['tenant_id' => $other->id]),
        'branch' => Branch::factory()->create(['tenant_id' => $other->id]),
    };
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);

    $send = fn (string $id) => test()->postJson(shiftMgmtUrl($tenant, '/shift-rotations/assign'), [
        'rotation_id' => $rotation->public_id,
        'assignable_type' => $type,
        'assignable_id' => $id,
        'effective_from' => '2026-10-10',
    ])->assertUnprocessable()->assertJsonValidationErrors('assignable_id')->json('errors.assignable_id');

    expect($send($foreign->public_id))->toBe($send('01HZNOSUCHASSIGNEE00000000'));
})->with(['employee', 'department', 'branch']);

it('rejects a foreign or unknown shift and assignee on the shift assign with 422s', function () {
    $tenant = createTenant(['subdomain' => 'shown']);
    $other = createTenant(['subdomain' => 'shother']);
    $foreignShift = Shift::factory()->create(['tenant_id' => $other->id]);
    $foreignEmployee = Employee::factory()->create(['tenant_id' => $other->id]);
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $send = fn (string $shiftId, string $employeeId) => test()->postJson(shiftMgmtUrl($tenant, '/shifts/assign'), [
        'shift_public_id' => $shiftId,
        'assignable_type' => 'employee',
        'assignable_public_id' => $employeeId,
        'effective_from' => '2026-10-10',
    ])->assertUnprocessable();

    $foreignShiftErrors = $send($foreignShift->public_id, $employee->public_id)
        ->assertJsonValidationErrors('shift_public_id')->json('errors.shift_public_id');
    $unknownShiftErrors = $send('01HZNOSUCHSHIFT00000000000', $employee->public_id)->json('errors.shift_public_id');
    $foreignEmployeeErrors = $send($shift->public_id, $foreignEmployee->public_id)
        ->assertJsonValidationErrors('assignable_public_id')->json('errors.assignable_public_id');
    $unknownEmployeeErrors = $send($shift->public_id, '01HZNOSUCHEMPLOYEE00000000')->json('errors.assignable_public_id');

    expect($foreignShiftErrors)->toBe($unknownShiftErrors)
        ->and($foreignEmployeeErrors)->toBe($unknownEmployeeErrors)
        ->and(ShiftAssignment::count())->toBe(0);
});

// ── Ending and deleting one assignment ───────────────────────────────────────

it('ends an assignment by setting its last day', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $assignment = shiftMgmtAssign(
        $tenant,
        Shift::factory()->create(['tenant_id' => $tenant->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Selam']),
        ['effective_from' => '2026-09-01'],
    );

    test()->patchJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"), [
        'effective_to' => '2026-10-07',
    ])->assertOk()
        ->assertJsonPath('public_id', $assignment->public_id)
        ->assertJsonPath('effective_to', '2026-10-07')
        ->assertJsonPath('assignee.name', 'Selam');

    expect($assignment->fresh()->effective_to->format('Y-m-d'))->toBe('2026-10-07');
});

it('refuses a last day before the first', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $assignment = shiftMgmtAssign(
        $tenant,
        Shift::factory()->create(['tenant_id' => $tenant->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id]),
        ['effective_from' => '2026-09-01'],
    );

    test()->patchJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"), [
        'effective_to' => '2026-08-31',
    ])->assertUnprocessable()->assertJsonValidationErrors('effective_to');

    test()->patchJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"), [])
        ->assertUnprocessable()->assertJsonValidationErrors('effective_to');

    expect($assignment->fresh()->effective_to)->toBeNull();
});

it('deletes an assignment that has not started yet', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $assignment = shiftMgmtAssign(
        $tenant,
        Shift::factory()->create(['tenant_id' => $tenant->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id]),
        ['effective_from' => '2026-10-08'],
    );

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"))->assertNoContent();

    expect(ShiftAssignment::find($assignment->id))->toBeNull();
});

it('refuses to delete an assignment that has taken effect', function (string $from) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $assignment = shiftMgmtAssign(
        $tenant,
        Shift::factory()->create(['tenant_id' => $tenant->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id]),
        ['effective_from' => $from],
    );

    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"))
        ->assertStatus(409)
        ->assertJsonPath('detail', fn (string $d) => str_contains($d, 'End it instead'));

    expect(ShiftAssignment::find($assignment->id))->not->toBeNull();
})->with(['starting today' => '2026-10-07', 'started last month' => '2026-09-01']);

it('limits ending to shift.update and deleting to shift.delete', function () {
    $tenant = createTenant();
    $assignment = shiftMgmtAssign(
        $tenant,
        Shift::factory()->create(['tenant_id' => $tenant->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id]),
        ['effective_from' => '2026-12-01'],
    );

    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    test()->patchJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"), [
        'effective_to' => '2026-12-31',
    ])->assertForbidden();
    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"))->assertForbidden();

    // An HR admin may end one but holds no shift.delete.
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$assignment->public_id}"))->assertForbidden();

    expect($assignment->fresh()->effective_to)->toBeNull();
});

it('cannot end or delete another tenant\'s assignment', function () {
    $tenant = createTenant(['subdomain' => 'endown']);
    $other = createTenant(['subdomain' => 'endother']);
    $foreign = shiftMgmtAssign(
        $other,
        Shift::factory()->create(['tenant_id' => $other->id]),
        Employee::factory()->create(['tenant_id' => $other->id]),
        ['effective_from' => '2026-12-01'],
    );
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->patchJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$foreign->public_id}"), [
        'effective_to' => '2026-12-31',
    ])->assertNotFound();
    test()->deleteJson(shiftMgmtUrl($tenant, "/shifts/assignments/{$foreign->public_id}"))->assertNotFound();

    $row = ShiftAssignment::withoutGlobalScopes()->find($foreign->id);
    expect($row)->not->toBeNull()
        ->and($row->effective_to)->toBeNull();
});

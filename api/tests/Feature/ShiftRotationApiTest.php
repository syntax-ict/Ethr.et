<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;

/**
 * HTTP surface for roadmap 3.1. Resolution logic itself is covered in
 * ShiftRotationTest; this file covers the contract, validation, authorization
 * and tenant isolation.
 */

/** Local helper so this file does not depend on another test file being loaded. */
function apiRotation(int $tenantId, array $shiftsByOffset, int $cycleDays): ShiftRotation
{
    $rotation = ShiftRotation::factory()->create([
        'tenant_id' => $tenantId,
        'cycle_days' => $cycleDays,
    ]);

    foreach ($shiftsByOffset as $offset => $shift) {
        ShiftRotationStep::factory()->create([
            'tenant_id' => $tenantId,
            'shift_rotation_id' => $rotation->id,
            'day_offset' => $offset,
            'shift_id' => $shift?->id,
        ]);
    }

    return $rotation;
}

it('creates a rotation with its steps', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $morning = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);
    $night = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Night']);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations", [
        'name' => 'Two Week Rotation',
        'cycle_days' => 14,
        'steps' => [
            ['day_offset' => 0, 'shift_id' => $morning->public_id],
            ['day_offset' => 7, 'shift_id' => $night->public_id],
            ['day_offset' => 13, 'shift_id' => null],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('cycle_days', 14)
        ->assertJsonPath('steps.0.shift.name', 'Morning')
        ->assertJsonPath('steps.2.is_rest_day', true);

    expect(ShiftRotationStep::count())->toBe(3);
});

it('never exposes a numeric primary key', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations/{$rotation->public_id}")
        ->assertOk()
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('tenant_id');
});

it('rejects a day offset outside the cycle', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    // Offset 9 in a 7-day cycle is unreachable: it would silently never be used,
    // so it has to be a validation error rather than accepted data.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations", [
        'name' => 'Bad',
        'cycle_days' => 7,
        'steps' => [['day_offset' => 9, 'shift_id' => null]],
    ])->assertUnprocessable()->assertJsonValidationErrors('steps.0.day_offset');
});

it('rejects a duplicated day offset', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations", [
        'name' => 'Bad',
        'cycle_days' => 7,
        'steps' => [
            ['day_offset' => 0, 'shift_id' => null],
            ['day_offset' => 0, 'shift_id' => null],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('steps.1.day_offset');
});

it('replaces steps wholesale on update', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $rotation = apiRotation($tenant->id, [0 => $shift, 1 => null, 2 => null], 3);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations/{$rotation->public_id}", [
        'name' => 'Now Weekly',
        'cycle_days' => 7,
        'steps' => [['day_offset' => 0, 'shift_id' => $shift->public_id]],
    ])->assertOk()->assertJsonPath('cycle_days', 7);

    // Old offsets must be gone rather than merged — a leftover step would keep
    // putting people on shifts the pattern no longer describes.
    expect(ShiftRotationStep::where('shift_rotation_id', $rotation->id)->count())->toBe(1);
});

it('assigns a rotation to an employee', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    $rotation = apiRotation($tenant->id, [0 => $shift, 1 => null], 2);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations/assign", [
        'rotation_id' => $rotation->public_id,
        'assignable_type' => 'employee',
        'assignable_id' => $employee->public_id,
        'effective_from' => '2026-04-01',
    ])->assertCreated()
        ->assertJsonPath('is_rotation', true)
        // anchor_date defaults to effective_from.
        ->assertJsonPath('anchor_date', '2026-04-01');
});

it('previews the resolved pattern over a range', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Day']);
    $rotation = apiRotation($tenant->id, [0 => $shift, 1 => null], 2);

    $response = test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations/{$rotation->public_id}/preview"
        .'?from=2026-04-01&to=2026-04-04&anchor_date=2026-04-01'
    );

    $response->assertOk()
        ->assertJsonPath('days.0.shift.name', 'Day')
        ->assertJsonPath('days.1.is_rest_day', true)
        ->assertJsonPath('days.2.shift.name', 'Day');

    expect($response->json('days'))->toHaveCount(4);
});

it('refuses a preview range that would time out', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id]);

    test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations/{$rotation->public_id}/preview"
        .'?from=2026-01-01&to=2030-01-01'
    )->assertStatus(422);
});

it('does not leak rotations across tenants', function () {
    $tenantA = createTenant(['subdomain' => 'rota']);
    $tenantB = createTenant(['subdomain' => 'rotb']);
    ShiftRotation::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Rotation']);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenantA);

    $response = test()->getJson('http://rota.ethr.test/api/v1/shift-rotations')->assertOk();

    expect($response->json('data'))->toHaveCount(0);
});

it('requires shift permissions', function () {
    $tenant = createTenant();
    // An ordinary employee holds no shift.create permission.
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations", [
        'name' => 'Nope',
        'cycle_days' => 7,
        'steps' => [['day_offset' => 0, 'shift_id' => null]],
    ])->assertForbidden();
});

it('requires authentication', function () {
    $tenant = createTenant();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/shift-rotations")
        ->assertUnauthorized();
});

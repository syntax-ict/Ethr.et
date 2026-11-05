<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/**
 * GET /shifts/coverage: who each shift expected and who came, per day, and
 * the hours planned against the hours covered (charts upgrade, 2026-11-04).
 *
 * The week is fixed to Monday 2026-11-02 to Sunday 2026-11-08 so the
 * weekday arithmetic is the same whenever the suite runs.
 */
function coverageUrl(string $query = ''): string
{
    $tenant = app(CurrentTenant::class)->get();

    return "http://{$tenant->subdomain}.ethr.test/api/v1/shifts/coverage{$query}";
}

test('counts scheduled and attended people per shift and day with planned and covered minutes', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    // 08:30 to 17:30 with an hour's break: 480 minutes a person a day, Monday to Friday.
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Day shift']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Department::class,
        'assignable_id' => $department->id,
        'effective_from' => '2026-01-01',
    ]);

    [$first, $second] = Employee::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'department_id' => $department->id,
        'status' => EmployeeStatus::CONFIRMED,
        'hire_date' => '2025-01-01',
    ]);
    // Someone who has left is scheduled on nothing.
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $department->id,
        'status' => EmployeeStatus::TERMINATED,
        'hire_date' => '2025-01-01',
        'termination_date' => '2026-06-30',
    ]);

    // Monday: the first employee works the whole shift (08:30 to 17:30 Addis).
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $first->id,
        'shift_id' => $shift->id,
        'date' => '2026-11-02',
        'check_in' => Carbon::parse('2026-11-02 05:30:00', 'UTC'),
        'check_out' => Carbon::parse('2026-11-02 14:30:00', 'UTC'),
    ]);
    // Tuesday: the second employee checks in and leaves after four hours.
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $second->id,
        'shift_id' => $shift->id,
        'date' => '2026-11-03',
        'check_in' => Carbon::parse('2026-11-03 05:30:00', 'UTC'),
        'check_out' => Carbon::parse('2026-11-03 10:30:00', 'UTC'),
    ]);

    $response = test()->getJson(coverageUrl('?from=2026-11-02&to=2026-11-08'));

    $response->assertOk()
        ->assertJsonPath('from', '2026-11-02')
        ->assertJsonPath('to', '2026-11-08')
        ->assertJsonCount(7, 'days')
        ->assertJsonCount(1, 'shifts')
        ->assertJsonMissingPath('shifts.0.id');

    $row = $response->json('shifts.0');
    expect($row['public_id'])->toBe($shift->public_id)
        ->and($row['name'])->toBe('Day shift')
        ->and($row['scheduled_people'])->toBe(10)
        ->and($row['attended_people'])->toBe(2)
        ->and($row['planned_minutes'])->toBe(10 * 480)
        ->and($row['covered_minutes'])->toBe(480 + 240);

    $byDay = collect($row['by_day'])->keyBy('date');
    expect($byDay['2026-11-02'])->toBe(['date' => '2026-11-02', 'scheduled' => 2, 'attended' => 1])
        ->and($byDay['2026-11-03'])->toBe(['date' => '2026-11-03', 'scheduled' => 2, 'attended' => 1])
        ->and($byDay['2026-11-07'])->toBe(['date' => '2026-11-07', 'scheduled' => 0, 'attended' => 0])
        ->and($byDay['2026-11-08']['scheduled'])->toBe(0);
});

test('a range longer than a month is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson(coverageUrl('?from=2026-11-01&to=2026-12-02'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);

    test()->getJson(coverageUrl('?from=2026-11-10&to=2026-11-09'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);
});

test('defaults to this week and answers with no shifts when nothing is scheduled', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson(coverageUrl());

    $response->assertOk()
        ->assertJsonCount(7, 'days')
        ->assertJsonPath('shifts', []);

    expect(Carbon::parse($response->json('from'))->dayOfWeekIso)->toBe(1)
        ->and(Carbon::parse($response->json('to'))->dayOfWeekIso)->toBe(7);
});

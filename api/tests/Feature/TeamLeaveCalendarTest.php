<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;

function teamCalendarManager(): array
{
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    return [$tenant, $supervisor];
}

test('manager team leave calendar returns colour-coded blocks', function () {
    [$tenant, $supervisor] = teamCalendarManager();

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);

    $start = Carbon::now()->startOfMonth()->addDays(4);
    $end = $start->copy()->addDays(2);

    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ]);

    $month = $start->format('Y-m');
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/team/leave/calendar?month={$month}");

    // Regression: this endpoint previously selected a non-existent `color`
    // column on leave_types and returned 500 for any manager.
    $response->assertOk()
        ->assertJsonStructure(['month', 'team_size', 'employees', 'daily_summary']);

    $entry = collect($response->json('employees'))->firstWhere('public_id', $subordinate->public_id);
    expect($entry)->not->toBeNull();

    $cell = $entry['days'][$start->format('Y-m-d')];
    expect($cell['on_leave'])->toBeTrue();
    expect($cell['leave_type'])->toBe($leaveType->name);
    expect($cell['color'])->toBe($leaveType->calendarColor());
    expect($cell['color'])->toMatch('/^#[0-9A-Fa-f]{6}$/');

    expect($response->json("daily_summary.{$start->format('Y-m-d')}.on_leave_count"))->toBe(1);
});

test('leave type calendar colour is deterministic and distinct by code', function () {
    $annual = new LeaveType(['code' => 'annual']);
    $sick = new LeaveType(['code' => 'sick']);

    // Stable across instances for the same code.
    expect((new LeaveType(['code' => 'annual']))->calendarColor())->toBe($annual->calendarColor());

    // Always a 6-digit hex.
    expect($annual->calendarColor())->toMatch('/^#[0-9A-Fa-f]{6}$/');
    expect($sick->calendarColor())->toMatch('/^#[0-9A-Fa-f]{6}$/');
});

test('employee cannot access team leave calendar', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/team/leave/calendar")
        ->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Testing\TestResponse;

/*
 * GET /team/attendance/summary feeds the manager dashboard's attendance chart,
 * one point per weekday for present, late and absent.
 */

function teamSummaryFor(string $query): TestResponse
{
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant));

    [$late, $onTime] = Employee::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ])->all();

    // Monday of this week: always a weekday, always inside the weekly window.
    $monday = now()->startOfWeek()->toDateString();
    AttendanceRecord::factory()->late()->create(['tenant_id' => $tenant->id, 'employee_id' => $late->id, 'date' => $monday]);
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $onTime->id, 'date' => $monday]);

    return test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/team/attendance/summary{$query}");
}

test('the team attendance summary counts each weekday', function () {
    $response = teamSummaryFor('?period=weekly')->assertOk();

    expect($response->json('period'))->toBe('weekly')
        ->and($response->json('team_size'))->toBe(3)
        ->and(collect($response->json('data'))->firstWhere('date', now()->startOfWeek()->toDateString()))
        ->toMatchArray(['present' => 2, 'late' => 1, 'absent' => 1]);
});

test('the summary names the period it actually used, not the one asked for', function () {
    // Anything but `monthly` is served as a week; echoing the raw parameter
    // labelled a weekly series "yearly".
    expect(teamSummaryFor('?period=yearly')->assertOk()->json('period'))->toBe('weekly');
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\TenantTime;

/*
 * GET /team/attendance/today gives a supervisor each member's status and a
 * summary. `present` counts everyone who came in today, on time, late, still
 * checked in, or gone early.
 */

test('everyone who came in today counts as present', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    [$onTime, $late, $open, $early, $away] = Employee::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
        'hire_date' => '2025-01-01',
    ])->all();

    $today = TenantTime::today($tenant)->toDateString();
    $on = ['tenant_id' => $tenant->id, 'date' => $today];

    AttendanceRecord::factory()->create([...$on, 'employee_id' => $onTime->id]);
    AttendanceRecord::factory()->late()->create([...$on, 'employee_id' => $late->id]);
    AttendanceRecord::factory()->checkInOnly()->create([...$on, 'employee_id' => $open->id]);
    AttendanceRecord::factory()->create([...$on, 'employee_id' => $early->id, 'status' => AttendanceStatus::EARLY_LEAVE]);

    $response = test()->actingAs($user)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/team/attendance/today")
        ->assertOk();

    $statuses = collect($response->json('employees'))->pluck('status', 'public_id')->all();

    expect($response->json('summary.present'))->toBe(4)
        ->and($response->json('summary.late'))->toBe(1)
        ->and($statuses)->toMatchArray([
            $onTime->public_id => 'present',
            $late->public_id => 'late',
            $open->public_id => 'checked_in',
            $early->public_id => 'early_leave',
        ])
        ->and($statuses)->toHaveKey($away->public_id);
});

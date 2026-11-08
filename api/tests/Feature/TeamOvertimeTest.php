<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/*
 * GET /team/overtime reports each member's hours past eight a day for the
 * tenant's current month. A voided punch was rejected, so it is not work.
 */

test('team overtime reads the tenant month and leaves voided punches out', function () {
    // 22:00 UTC on 30 November is already 1 December in Addis Ababa.
    test()->travelTo(CarbonImmutable::parse('2026-11-30 22:00:00', 'UTC'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    $member = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);

    $day = fn (string $date, int $hours, AttendanceStatus $status = AttendanceStatus::PRESENT) => AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $member->id,
        'date' => $date,
        'check_in' => CarbonImmutable::parse("{$date} 05:00:00", 'UTC'),
        'check_out' => CarbonImmutable::parse("{$date} 05:00:00", 'UTC')->addHours($hours),
        'status' => $status,
    ]);

    $day('2026-12-01', 10);                            // 2 hours over, counted
    $day('2026-12-31', 9);                             // 1 hour over, last day of the month
    $day('2026-12-02', 12, AttendanceStatus::VOIDED);  // rejected, not work
    $day('2026-11-27', 12);                            // the UTC month, not the tenant's

    $response = test()->actingAs($user)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/team/overtime")
        ->assertOk();

    expect($response->json('month'))->toBe('2026-12')
        ->and($response->json('employees.0'))->toMatchArray([
            'public_id' => $member->public_id,
            'days_worked' => 2,
            'overtime_minutes' => 180,
        ])
        ->and($response->json('total_overtime_hours'))->toEqual(3);
});

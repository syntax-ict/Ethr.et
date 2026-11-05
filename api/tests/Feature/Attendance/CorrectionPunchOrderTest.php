<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use Carbon\Carbon;

// A-Z review D-04 (2026-11-04). A night worker who forgot to check out asks
// for a check-out at 06:00. The form rolled a check-out into the next day only
// when a check-in was typed too, and nothing on the server compared the
// proposed check-out with the check-in the record already had, so the
// correction was filed, approved, and the record closed before it opened.

function punchOrderUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function punchOrderAddis(string $day, string $time): Carbon
{
    return Carbon::parse("{$day} {$time}", 'Africa/Addis_Ababa')->utc();
}

/** A 22:00 to 06:00 shift, an employee on it, and the 22:00 check-in with no check-out. */
function punchOrderOpenNight(Tenant $tenant): array
{
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'start_time' => '22:00', 'end_time' => '06:00']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-06-15',
        'check_in' => punchOrderAddis('2026-06-15', '22:00'),
        'check_out' => null,
    ]);

    return [$employee, $record];
}

it('refuses a check-out that would come before the check-in the record already has', function () {
    $tenant = createTenant();
    [$employee, $record] = punchOrderOpenNight($tenant);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    test()->postJson(punchOrderUrl($tenant, 'attendance/corrections'), [
        'attendance_record_public_id' => $record->public_id,
        'proposed_check_out' => punchOrderAddis('2026-06-15', '06:00')->toIso8601String(),
        'reason' => 'Forgot to check out',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['proposed_check_out' => __('correction.check_out_before_check_in')]);
});

it('refuses a check-in that would come after the check-out the record already has', function () {
    $tenant = createTenant();
    [$employee, $record] = punchOrderOpenNight($tenant);
    $record->update(['check_out' => punchOrderAddis('2026-06-16', '06:00')]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    test()->postJson(punchOrderUrl($tenant, 'attendance/corrections'), [
        'attendance_record_public_id' => $record->public_id,
        'proposed_check_in' => punchOrderAddis('2026-06-16', '07:00')->toIso8601String(),
        'reason' => 'Wrong check-in',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['proposed_check_in' => __('correction.check_out_before_check_in')]);
});

it('refuses a proposed pair whose check-out comes first', function () {
    $tenant = createTenant();
    [$employee, $record] = punchOrderOpenNight($tenant);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    test()->postJson(punchOrderUrl($tenant, 'attendance/corrections'), [
        'attendance_record_public_id' => $record->public_id,
        'proposed_check_in' => punchOrderAddis('2026-06-15', '22:00')->toIso8601String(),
        'proposed_check_out' => punchOrderAddis('2026-06-15', '21:00')->toIso8601String(),
        'reason' => 'Both wrong',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['proposed_check_out' => __('correction.check_out_before_check_in')]);
});

it('closes a night shift the next morning when the check-out is on the next day', function () {
    $tenant = createTenant();
    [$employee, $record] = punchOrderOpenNight($tenant);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    $publicId = test()->postJson(punchOrderUrl($tenant, 'attendance/corrections'), [
        'attendance_record_public_id' => $record->public_id,
        'proposed_check_out' => punchOrderAddis('2026-06-16', '06:00')->toIso8601String(),
        'reason' => 'Forgot to check out',
    ])->assertCreated()->json('public_id');

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->putJson(punchOrderUrl($tenant, "attendance/corrections/{$publicId}/approve"))->assertOk();

    $record->refresh();
    expect($record->check_out->greaterThan($record->check_in))->toBeTrue()
        ->and((int) $record->check_in->diffInMinutes($record->check_out))->toBe(480);
});

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Shift;
use Carbon\Carbon;

// A-Z review E-06 (2026-11-04). Leave was counted on the organisation's
// working week alone. Someone whose shift works Saturdays was not charged for
// them, so payroll under-deducted their unpaid leave; someone on a four-day
// shift was charged for a day they never work. A leave day is now a day that
// person is scheduled to work, by the same rules attendance uses.

function ownScheduleDays(string $workingDays, string $start, string $end): float
{
    Carbon::setTestNow(Carbon::parse('2026-11-04 10:00', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'working_days' => $workingDays]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    $type = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual_own', 'default_days' => 20, 'min_notice_days' => 0]);

    return (float) test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $type->public_id,
        'start_date' => $start,
        'end_date' => $end,
        'reason' => 'Family visit',
    ])->assertCreated()->json('days');
}

afterEach(fn () => Carbon::setTestNow());

it('charges a Saturday to someone whose shift works Saturdays', function () {
    // Monday 16 to Saturday 21 November 2026.
    expect(ownScheduleDays('1,2,3,4,5,6', '2026-11-16', '2026-11-21'))->toBe(6.0);
});

it('does not charge a day someone\'s shift never works', function () {
    // Monday 16 to Friday 20 November 2026, on a Monday-to-Thursday shift.
    expect(ownScheduleDays('1,2,3,4', '2026-11-16', '2026-11-20'))->toBe(4.0);
});

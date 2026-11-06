<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

// A-Z review E-05 (2026-11-04). Minimum notice was measured from the server's
// current instant to midnight of the start date, as a fraction of days, so a
// request made at 10:00 for a day exactly seven days ahead had 6.6 days of
// notice and was refused. The refusal and the one for too many consecutive
// days had no translation, so the person read `leave.min_notice`.

function noticeSetUp(string $now): array
{
    Carbon::setTestNow(Carbon::parse($now, 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    $type = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'annual_notice', 'default_days' => 20, 'min_notice_days' => 7,
    ]);

    return [$tenant, $type];
}

function noticeRequest(Tenant $tenant, LeaveType $type, string $start, string $end): TestResponse
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $type->public_id,
        'start_date' => $start,
        'end_date' => $end,
        'reason' => 'Family visit',
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('accepts a request made exactly the notice period ahead, whatever the hour', function () {
    // Wednesday 4 November, 10:00 in Addis Ababa; Wednesday the 11th is 7 days ahead.
    [$tenant, $type] = noticeSetUp('2026-11-04 10:00');

    noticeRequest($tenant, $type, '2026-11-11', '2026-11-12')->assertCreated();
});

it('counts the days in the organisation\'s own calendar day, not the server\'s', function () {
    // 01:00 on the 4th in Addis Ababa is still the 3rd in UTC; the 10th is 6 days ahead here.
    [$tenant, $type] = noticeSetUp('2026-11-04 01:00');

    noticeRequest($tenant, $type, '2026-11-10', '2026-11-11')
        ->assertStatus(422)
        ->assertJsonPath('detail', 'This leave type needs 7 days\' notice. Choose a start date at least 7 days from today.');
});

it('explains a request longer than the type allows in words', function () {
    [$tenant, $type] = noticeSetUp('2026-11-04 10:00');
    $type->update(['min_notice_days' => 0, 'max_consecutive' => 2]);

    noticeRequest($tenant, $type, '2026-11-16', '2026-11-20')
        ->assertStatus(422)
        ->assertJsonPath('detail', 'This leave type allows at most 2 days in one request.');
});

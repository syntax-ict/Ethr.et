<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/**
 * What a punch came to, said the same way by every method, and one record a
 * day however many methods report it.
 *
 * Monday 12 October 2026 in Addis Ababa (UTC+3). The shift is 08:30-17:30 with
 * ten minutes' grace, so 08:25 is on time, 09:00 is thirty minutes late, and
 * leaving at 16:00 is ninety minutes early.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Employee, 2: Branch, 3: User} */
function outcomeSetup(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'latitude' => 9.0108,
        'longitude' => 38.7613,
        'geofence_radius_meters' => 150,
    ]);
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
        'grace_minutes' => 10,
        'early_departure_minutes' => 15,
        'working_days' => '1,2,3,4,5',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'employee_code' => 'OUT-1']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);
    $user = actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    return [$tenant, $employee, $branch, $user];
}

function outcomeUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** The phone (the installed web app) checks in or out. */
function phonePunch(Tenant $tenant, string $punch, string $key)
{
    return test()->postJson(outcomeUrl($tenant, "attendance/mobile/{$punch}"), [
        'idempotency_key' => $key,
        'latitude' => 9.0108,
        'longitude' => 38.7613,
    ]);
}

/** The shared kiosk takes a code and a punch (`auto` lets the server decide). */
function kioskPunch(Tenant $tenant, KioskSession $session, string $type, string $key)
{
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();

    return test()->postJson(outcomeUrl($tenant, 'kiosk/check-in'), [
        'employee_code' => 'OUT-1',
        'type' => $type,
        'idempotency_key' => $key,
    ], ['X-Kiosk-Token' => $session->token]);
}

function outcomeKiosk(Tenant $tenant, Branch $branch): KioskSession
{
    return KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);
}

// ── Arriving ────────────────────────────────────────────────────────────────

it('says checked in when on time', function () {
    Carbon::setTestNow('2026-10-12 05:25:00'); // 08:25 Addis
    [$tenant] = outcomeSetup();

    phonePunch($tenant, 'check-in', 'out-on-time')
        ->assertCreated()
        ->assertJsonPath('outcome.code', 'checked_in')
        ->assertJsonPath('outcome.minutes', 0)
        ->assertJsonPath('outcome.by', null);
});

it('says checked in late, and by how much', function () {
    Carbon::setTestNow('2026-10-12 06:00:00'); // 09:00 Addis
    [$tenant] = outcomeSetup();

    phonePunch($tenant, 'check-in', 'out-late')
        ->assertCreated()
        ->assertJsonPath('outcome.code', 'checked_in_late')
        ->assertJsonPath('outcome.minutes', 30)
        ->assertJsonPath('status', 'late');
});

it('says the same when the phone retries a punch that already went through', function () {
    Carbon::setTestNow('2026-10-12 06:00:00');
    [$tenant] = outcomeSetup();

    phonePunch($tenant, 'check-in', 'out-retry')->assertCreated();

    phonePunch($tenant, 'check-in', 'out-retry')
        ->assertOk()
        ->assertJsonPath('was_duplicate', true)
        ->assertJsonPath('outcome.code', 'checked_in_late')
        ->assertJsonPath('outcome.minutes', 30);
});

// ── Several methods, one record ─────────────────────────────────────────────

it('tells the kiosk the person is already in from the phone, and keeps one record', function () {
    [$tenant, $employee, $branch] = outcomeSetup();
    $session = outcomeKiosk($tenant, $branch);

    Carbon::setTestNow('2026-10-12 05:25:00'); // 08:25, on the phone
    phonePunch($tenant, 'check-in', 'out-phone')->assertCreated();

    Carbon::setTestNow('2026-10-12 05:50:00'); // 08:50, at the kiosk
    kioskPunch($tenant, $session, 'check_in', 'out-kiosk')
        ->assertOk()
        ->assertJsonPath('was_duplicate', true)
        ->assertJsonPath('outcome.code', 'already_checked_in')
        ->assertJsonPath('outcome.by', 'mobile')
        ->assertJsonPath('outcome.at', '2026-10-12T05:25:00+00:00');

    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->where('status', '!=', 'voided')->count())->toBe(1)
        ->and(AttendanceConflict::query()->count())->toBe(0);
});

it('says already in when two methods report the same arrival minutes apart', function () {
    [$tenant, $employee, $branch] = outcomeSetup();
    $session = outcomeKiosk($tenant, $branch);

    Carbon::setTestNow('2026-10-12 05:25:00');
    phonePunch($tenant, 'check-in', 'out-near-phone')->assertCreated();

    Carbon::setTestNow('2026-10-12 05:27:00'); // two minutes later, inside the merge window
    kioskPunch($tenant, $session, 'check_in', 'out-near-kiosk')
        ->assertJsonPath('outcome.code', 'already_checked_in')
        ->assertJsonPath('outcome.by', 'mobile');

    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->where('status', '!=', 'voided')->count())->toBe(1);
});

it('keeps a check-in after the day was closed as a second session', function () {
    [$tenant, $employee] = outcomeSetup();

    Carbon::setTestNow('2026-10-12 05:25:00');
    phonePunch($tenant, 'check-in', 'out-s1-in')->assertCreated();
    Carbon::setTestNow('2026-10-12 10:00:00');
    phonePunch($tenant, 'check-out', 'out-s1-out')->assertOk();

    Carbon::setTestNow('2026-10-12 12:00:00');
    phonePunch($tenant, 'check-in', 'out-s2-in')
        ->assertCreated()
        ->assertJsonPath('outcome.code', 'checked_in_again');

    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->where('status', '!=', 'voided')->count())->toBe(2);
});

// ── Leaving ─────────────────────────────────────────────────────────────────

it('says checked out when the shift was worked', function () {
    Carbon::setTestNow('2026-10-12 05:25:00');
    [$tenant] = outcomeSetup();
    phonePunch($tenant, 'check-in', 'out-full-in')->assertCreated();

    Carbon::setTestNow('2026-10-12 14:35:00'); // 17:35
    phonePunch($tenant, 'check-out', 'out-full-out')
        ->assertOk()
        ->assertJsonPath('outcome.code', 'checked_out')
        ->assertJsonPath('outcome.minutes', 0);
});

it('says checked out early, and by how much', function () {
    Carbon::setTestNow('2026-10-12 05:25:00');
    [$tenant] = outcomeSetup();
    phonePunch($tenant, 'check-in', 'out-early-in')->assertCreated();

    Carbon::setTestNow('2026-10-12 13:00:00'); // 16:00, ninety minutes before the end
    phonePunch($tenant, 'check-out', 'out-early-out')
        ->assertOk()
        ->assertJsonPath('outcome.code', 'checked_out_early')
        ->assertJsonPath('outcome.minutes', 90)
        ->assertJsonPath('status', 'early_leave');
});

it('tells the kiosk the person has already left by the phone', function () {
    [$tenant, , $branch] = outcomeSetup();
    $session = outcomeKiosk($tenant, $branch);

    Carbon::setTestNow('2026-10-12 05:25:00');
    phonePunch($tenant, 'check-in', 'out-left-in')->assertCreated();
    Carbon::setTestNow('2026-10-12 14:35:00');
    phonePunch($tenant, 'check-out', 'out-left-out')->assertOk();

    Carbon::setTestNow('2026-10-12 14:37:00'); // the gate reader, two minutes later
    kioskPunch($tenant, $session, 'auto', 'out-left-kiosk')
        ->assertOk()
        ->assertJsonPath('outcome.code', 'already_checked_out')
        ->assertJsonPath('outcome.by', 'mobile')
        ->assertJsonPath('outcome.at', '2026-10-12T14:35:00+00:00');
});

it('tells the kiosk it checked the person out, and by how much they were early', function () {
    [$tenant, , $branch] = outcomeSetup();
    $session = outcomeKiosk($tenant, $branch);

    Carbon::setTestNow('2026-10-12 05:25:00');
    phonePunch($tenant, 'check-in', 'out-ko-in')->assertCreated();

    Carbon::setTestNow('2026-10-12 13:00:00'); // 16:00
    kioskPunch($tenant, $session, 'auto', 'out-ko-out')
        ->assertOk()
        ->assertJsonPath('outcome.code', 'checked_out_early')
        ->assertJsonPath('outcome.minutes', 90);
});

<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceAutoCheckedOutNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The punching assistant: today's shift for the phone, the selfie rule, the
 * opt-in automatic check-in, and auto check-out at the shift's end.
 *
 * Monday 12 October 2026 in Addis Ababa; the shift is 08:30–17:30 there,
 * 05:30–14:30 UTC.
 */
const PA_BRANCH_LAT = 9.0108;
const PA_BRANCH_LNG = 38.7613;

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Employee, 2: User, 3: Shift} */
function punchAssistantSetup(array $settings = []): array
{
    $tenant = createTenant();
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'latitude' => PA_BRANCH_LAT,
        'longitude' => PA_BRANCH_LNG,
        'geofence_radius_meters' => 150,
    ]);
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
        'grace_minutes' => 10,
        'working_days' => '1,2,3,4,5',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);
    $user = actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    if ($settings !== []) {
        AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);
    }

    return [$tenant, $employee, $user, $shift];
}

function paUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

// ── GET /attendance/my-shift ────────────────────────────────────────────────

it('gives the phone today\'s shift as instants, by the tenant\'s clock', function () {
    Carbon::setTestNow('2026-10-12 04:00:00'); // 07:00 Addis
    [$tenant] = punchAssistantSetup();

    test()->getJson(paUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('date', '2026-10-12')
        ->assertJsonPath('working_day', true)
        ->assertJsonPath('shift.start_time', '08:30')
        ->assertJsonPath('shift.starts_at', '2026-10-12T05:30:00+00:00')
        ->assertJsonPath('shift.ends_at', '2026-10-12T14:30:00+00:00')
        ->assertJsonPath('attendance.status', 'not_checked_in')
        ->assertJsonPath('policy.geofence.radius_meters', 150)
        // Reminders are on by default; checking in for someone never is.
        ->assertJsonPath('assistant.reminders', true)
        ->assertJsonPath('assistant.auto_check_in', false)
        ->assertJsonPath('assistant.auto_checkout', false);
});

it('reports a check-in already made', function () {
    Carbon::setTestNow('2026-10-12 06:00:00');
    [$tenant, $employee, , $shift] = punchAssistantSetup();
    AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-10-12',
        'check_in' => '2026-10-12 05:31:00',
    ]);

    test()->getJson(paUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('attendance.status', 'checked_in')
        ->assertJsonPath('attendance.check_in', '2026-10-12T05:31:00+00:00');
});

it('is not a working day on approved leave or a holiday, so nothing reminds them', function (string $case) {
    Carbon::setTestNow('2026-10-12 04:00:00');
    [$tenant, $employee] = punchAssistantSetup();

    if ($case === 'leave') {
        LeaveRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-13',
            'status' => LeaveStatus::APPROVED,
        ]);
    } else {
        Holiday::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'date' => '2026-10-12',
            'is_active' => true,
        ]);
    }

    test()->getJson(paUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('working_day', false);
})->with(['leave', 'holiday']);

it('answers 404 for an account with no employee record', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson(paUrl($tenant, '/attendance/my-shift'))->assertNotFound();
});

// ── Selfie required ─────────────────────────────────────────────────────────

it('refuses a mobile check-in without a selfie when the organisation requires one', function () {
    Carbon::setTestNow('2026-10-12 05:40:00');
    [$tenant] = punchAssistantSetup(['mobile_photo_required' => true]);

    test()->postJson(paUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => 'pa-photo-1',
        'latitude' => PA_BRANCH_LAT,
        'longitude' => PA_BRANCH_LNG,
    ])->assertStatus(422)->assertJsonValidationErrors('photo');
});

it('accepts a mobile check-in without a selfie when none is required', function () {
    Carbon::setTestNow('2026-10-12 05:40:00');
    [$tenant] = punchAssistantSetup(['mobile_photo_required' => false]);

    test()->postJson(paUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => 'pa-photo-2',
        'latitude' => PA_BRANCH_LAT,
        'longitude' => PA_BRANCH_LNG,
    ])->assertCreated();
});

// ── Automatic check-in ──────────────────────────────────────────────────────

it('checks in automatically only where allowed, opted in and inside the branch area', function () {
    Carbon::setTestNow('2026-10-12 05:25:00');
    [$tenant, , $user] = punchAssistantSetup(['geofence_auto_checkin_enabled' => true]);
    $body = fn (string $key, float $lat) => [
        'idempotency_key' => $key,
        'latitude' => $lat,
        'longitude' => PA_BRANCH_LNG,
        'auto' => true,
    ];

    // Not opted in yet.
    test()->postJson(paUrl($tenant, '/attendance/mobile/check-in'), $body('pa-auto-1', PA_BRANCH_LAT))
        ->assertStatus(422);

    $user->update(['preferences' => ['auto_check_in' => true]]);

    // Opted in, but about 1.1 km away.
    test()->postJson(paUrl($tenant, '/attendance/mobile/check-in'), $body('pa-auto-2', PA_BRANCH_LAT + 0.01))
        ->assertStatus(422);

    test()->postJson(paUrl($tenant, '/attendance/mobile/check-in'), $body('pa-auto-3', PA_BRANCH_LAT))
        ->assertCreated();

    $record = AttendanceRecord::query()->latest('id')->first();
    expect($record->metadata['auto_punch'] ?? null)->toBeTrue();
});

it('never checks in automatically where the organisation has not allowed it', function () {
    Carbon::setTestNow('2026-10-12 05:25:00');
    [$tenant, , $user] = punchAssistantSetup(['geofence_auto_checkin_enabled' => false]);
    $user->update(['preferences' => ['auto_check_in' => true]]);

    test()->postJson(paUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => 'pa-auto-4',
        'latitude' => PA_BRANCH_LAT,
        'longitude' => PA_BRANCH_LNG,
        'auto' => true,
    ])->assertStatus(422);

    expect(AttendanceRecord::query()->count())->toBe(0);
});

// ── Preferences ─────────────────────────────────────────────────────────────

it('keeps the employee\'s own reminder and auto check-in choices', function () {
    [$tenant] = punchAssistantSetup();

    test()->putJson(paUrl($tenant, '/profile/preferences'), [
        'punch_reminders' => false,
        'auto_check_in' => true,
    ])->assertOk()
        ->assertJsonPath('punch_reminders', false)
        ->assertJsonPath('auto_check_in', true)
        // Merged, not replaced.
        ->assertJsonPath('theme', 'system');
});

// ── Auto check-out ──────────────────────────────────────────────────────────

/** @return array{0: Tenant, 1: AttendanceRecord, 2: User} */
function autoCheckoutSetup(bool $enabled, string $checkIn = '2026-10-12 05:29:00'): array
{
    [$tenant, $employee, $user, $shift] = punchAssistantSetup([
        'auto_checkout_enabled' => $enabled,
        'auto_checkout_after_minutes' => 120,
    ]);
    $record = AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-10-12',
        'check_in' => $checkIn,
    ]);

    return [$tenant, $record, $user];
}

it('closes a check-in left open, at the shift\'s end, flagged and announced', function () {
    Notification::fake();
    [, $record, $user] = autoCheckoutSetup(true);

    // 20:00 Addis: the shift ended at 17:30, two and a half hours ago.
    Carbon::setTestNow('2026-10-12 17:00:00');
    $this->artisan('attendance:auto-checkout')->assertSuccessful();

    $record->refresh();
    expect($record->check_out->toIso8601String())->toBe('2026-10-12T14:30:00+00:00')
        ->and($record->metadata['auto_checkout'])->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['action' => 'attendance.auto_checkout', 'auditable_id' => $record->id]);
    Notification::assertSentTo($user, AttendanceAutoCheckedOutNotification::class);

    // A second run changes nothing.
    $this->artisan('attendance:auto-checkout')->assertSuccessful();
    expect(DB::table('audit_log')->where('action', 'attendance.auto_checkout')->count())->toBe(1);
});

it('waits until the configured time after the shift has passed', function () {
    [, $record] = autoCheckoutSetup(true);

    // 19:00 Addis: only 90 minutes after the 17:30 end; the setting is 120.
    Carbon::setTestNow('2026-10-12 16:00:00');
    $this->artisan('attendance:auto-checkout')->assertSuccessful();

    expect($record->refresh()->check_out)->toBeNull();
});

it('leaves open records alone where auto check-out is off', function () {
    [, $record] = autoCheckoutSetup(false);

    Carbon::setTestNow('2026-10-12 20:00:00');
    $this->artisan('attendance:auto-checkout')->assertSuccessful();

    expect($record->refresh()->check_out)->toBeNull();
});

it('does not close a check-in made after the shift had already ended', function () {
    // Checked in at 18:00 Addis, after the 17:30 end: no scheduled end to use.
    [, $record] = autoCheckoutSetup(true, '2026-10-12 15:00:00');

    Carbon::setTestNow('2026-10-12 20:00:00');
    $this->artisan('attendance:auto-checkout')->assertSuccessful();

    expect($record->refresh()->check_out)->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\KioskSession;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\Tenant;
use App\Services\Onboarding\ReadinessScorer;
use Carbon\Carbon;

/**
 * What is keeping attendance from working, as a list a person can act on
 * (AttendanceHealth, GET /attendance/setup-status).
 */
afterEach(fn () => Carbon::setTestNow());

function ahUrl(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/setup-status";
}

/** A tenant set up properly: default shift, holidays, settings. */
function ahHealthyTenant(array $settings = [], array $tenantAttributes = []): Tenant
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant($tenantAttributes);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'working_days' => '1,2,3,4,5']);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'date' => '2026-09-11', 'is_active' => true]);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    return $tenant;
}

/** @return list<string> */
function ahIds(Tenant $tenant): array
{
    return array_column(test()->getJson(ahUrl($tenant))->assertOk()->json('checks'), 'id');
}

test('a properly set-up organisation has nothing to fix', function () {
    $tenant = ahHealthyTenant();

    test()->getJson(ahUrl($tenant))
        ->assertOk()
        ->assertJsonPath('ready', true)
        ->assertJsonPath('checks', []);
});

test('no default schedule is a blocker', function () {
    $tenant = ahHealthyTenant();
    Shift::query()->update(['is_default' => false]);

    test()->getJson(ahUrl($tenant))
        ->assertJsonPath('ready', false)
        ->assertJsonPath('checks.0.id', 'no_default_schedule')
        ->assertJsonPath('checks.0.severity', 'blocker')
        ->assertJsonPath('checks.0.fix_route', '/shifts');
});

test('a required geofence at branches not on the map is a blocker, counted', function () {
    $tenant = ahHealthyTenant(['geofence_required' => true]);
    Branch::factory()->count(2)->create(['tenant_id' => $tenant->id, 'latitude' => null, 'longitude' => null]);
    Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => 9.01, 'longitude' => 38.76, 'geofence_radius_meters' => 100]);

    $check = collect(test()->getJson(ahUrl($tenant))->json('checks'))->firstWhere('id', 'geofence_unplaced');
    expect($check['severity'])->toBe('blocker')->and($check['count'])->toBe(2);
});

test('an idle crew rotation is a blocker for a round-the-clock organisation and a warning elsewhere', function () {
    $tenant = ahHealthyTenant([], ['settings' => ['configuration_profile' => ['workforce_profile' => 'continuous_24_7']]]);
    ShiftRotation::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
    expect(collect(test()->getJson(ahUrl($tenant))->json('checks'))->firstWhere('id', 'crews_unassigned')['severity'])->toBe('blocker');

    $office = ahHealthyTenant();
    ShiftRotation::factory()->create(['tenant_id' => $office->id, 'is_active' => true]);
    expect(collect(test()->getJson(ahUrl($office))->json('checks'))->firstWhere('id', 'crews_unassigned')['severity'])->toBe('warning');
});

test('punches with no shift, missing kiosk PINs, field staff without sites and missing holidays are warnings', function () {
    $tenant = ahHealthyTenant(['kiosk_pin_required' => true]);
    Holiday::query()->delete();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => 9.01, 'longitude' => 38.76]);
    KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'kiosk_pin' => null, 'attendance_mode' => AttendanceMode::FIELD]);
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => null, 'date' => '2026-10-10', 'status' => AttendanceStatus::PENDING]);

    expect(ahIds($tenant))->toContain('pending_punches', 'kiosk_pins_missing', 'field_without_sites', 'holidays_missing');
});

test('a working week that disagrees with the default shift is worth knowing', function () {
    $tenant = ahHealthyTenant([], ['settings' => ['working_days' => [1, 2, 3, 4, 5, 6]]]);

    expect(ahIds($tenant))->toContain('working_week_mismatch');
});

test('only those who manage attendance see it', function () {
    $tenant = ahHealthyTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson(ahUrl($tenant))->assertForbidden();
});

test('an organisation punching by phone has an attendance source without any device', function () {
    $tenant = ahHealthyTenant(['enabled_methods' => ['mobile']]);

    $check = collect(app(ReadinessScorer::class)->score($tenant->id)['categories']['data']['checks'])
        ->firstWhere('id', 'attendance_source_configured');
    expect($check['passed'])->toBeTrue();

    AttendanceSetting::query()->update(['enabled_methods' => ['biometric']]);
    $check = collect(app(ReadinessScorer::class)->score($tenant->id)['categories']['data']['checks'])
        ->firstWhere('id', 'attendance_source_configured');
    expect($check['passed'])->toBeFalse();
});

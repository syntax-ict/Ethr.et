<?php

declare(strict_types=1);

use App\Enums\AttendanceMode;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * The rules that apply to a person: the organisation's settings, then their
 * branch's overrides, then their attendance mode (AttendancePolicy). Until
 * 2026-10-24 there was one row per organisation, so a head office that
 * needed a geofence and a field depot that could not use one shared a rule.
 */
const AP_LAT = 9.0108;
const AP_LNG = 38.7613;

afterEach(fn () => Carbon::setTestNow());

function apUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** @return array{0: Tenant, 1: Branch} */
function apOrganisation(array $settings = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id, 'latitude' => AP_LAT, 'longitude' => AP_LNG, 'geofence_radius_meters' => 150,
    ]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);

    return [$tenant, $branch];
}

function apEmployee(Tenant $tenant, Branch $branch, AttendanceMode $mode = AttendanceMode::OFFICE): Employee
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'attendance_mode' => $mode]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    return $employee;
}

function apPunch(Tenant $tenant, string $key, float $lat): TestResponse
{
    return test()->postJson(apUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => $key, 'latitude' => $lat, 'longitude' => AP_LNG,
    ]);
}

// ── Branch overrides ────────────────────────────────────────────────────────

test("a branch's override is what its people are held to", function () {
    [$tenant, $strict] = apOrganisation(['geofence_required' => false]);
    $relaxed = Branch::factory()->create([
        'tenant_id' => $tenant->id, 'latitude' => AP_LAT, 'longitude' => AP_LNG, 'geofence_radius_meters' => 150,
    ]);
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $strict->id, 'overrides' => ['geofence_required' => true]]);

    apEmployee($tenant, $strict);
    apPunch($tenant, 'ap-1', 9.0500)->assertStatus(422);

    apEmployee($tenant, $relaxed);
    apPunch($tenant, 'ap-2', 9.0500)->assertCreated();
});

test('a method turned off at one branch is refused only there', function () {
    [$tenant, $branch] = apOrganisation();
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'overrides' => ['enabled_methods' => ['biometric', 'kiosk']]]);
    apEmployee($tenant, $branch);

    apPunch($tenant, 'ap-3', AP_LAT)->assertStatus(422);
    test()->getJson(apUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('policy.mobile_enabled', false)
        ->assertJsonPath('policy.qr_enabled', false);
});

test('a selfie required at the branch is required of its phone punches', function () {
    [$tenant, $branch] = apOrganisation(['mobile_photo_required' => false]);
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'overrides' => ['mobile_photo_required' => true]]);
    apEmployee($tenant, $branch);

    apPunch($tenant, 'ap-4', AP_LAT)->assertStatus(422)->assertJsonValidationErrors('photo');
});

test('automatic check-out switched on at one branch closes only its records', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 20:30', 'Africa/Addis_Ababa')->utc());
    [$tenant, $on] = apOrganisation(['auto_checkout_enabled' => false]);
    Carbon::setTestNow(Carbon::parse('2026-10-12 20:30', 'Africa/Addis_Ababa')->utc());
    $off = Branch::factory()->create(['tenant_id' => $tenant->id]);
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $on->id, 'overrides' => ['auto_checkout_enabled' => true]]);
    $shift = Shift::query()->where('is_default', true)->sole();

    $records = [];
    foreach ([$on, $off] as $branch) {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $records[] = AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => '2026-10-12', 'check_in' => Carbon::parse('2026-10-12 08:25', 'Africa/Addis_Ababa')->utc(), 'check_out' => null,
        ]);
    }

    test()->artisan('attendance:auto-checkout')->assertSuccessful();

    expect($records[0]->fresh()->check_out)->not->toBeNull()
        ->and($records[1]->fresh()->check_out)->toBeNull();
});

// ── Attendance mode ─────────────────────────────────────────────────────────

test('a remote worker is not held to the geofence and is not flagged for being away', function () {
    [$tenant, $branch] = apOrganisation(['geofence_required' => true]);
    $employee = apEmployee($tenant, $branch, AttendanceMode::REMOTE);

    apPunch($tenant, 'ap-5', 9.2000)->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect(AttendanceIntegrityFlag::where('attendance_record_id', $record->id)->pluck('type')->all())
        ->not->toContain('outside_geofence');
    test()->getJson(apUrl($tenant, '/attendance/my-shift'))
        ->assertJsonPath('policy.attendance_mode', 'remote')
        ->assertJsonPath('policy.geofence', null);
});

test('a field worker away from the branch is recorded and flagged for review, not refused', function () {
    [$tenant, $branch] = apOrganisation(['geofence_required' => true]);
    $employee = apEmployee($tenant, $branch, AttendanceMode::FIELD);

    apPunch($tenant, 'ap-6', 9.0500)->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect(AttendanceIntegrityFlag::where('attendance_record_id', $record->id)->pluck('type')->all())
        ->toContain('outside_geofence');
});

test('an admin sets where an employee punches, and it is audited', function () {
    [$tenant, $branch] = apOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->putJson(apUrl($tenant, "/employees/{$employee->public_id}/attendance-mode"), ['attendance_mode' => 'field'])
        ->assertOk()
        ->assertJsonPath('attendance_mode', 'field');
    test()->putJson(apUrl($tenant, "/employees/{$employee->public_id}/attendance-mode"), ['attendance_mode' => 'anywhere'])
        ->assertStatus(422);

    expect($employee->fresh()->attendance_mode)->toBe(AttendanceMode::FIELD)
        ->and(AuditLog::query()->where('action', 'employee.attendance_mode_changed')->exists())->toBeTrue();
    test()->getJson(apUrl($tenant, "/employees/{$employee->public_id}"))->assertJsonPath('attendance_mode', 'field');
});

// ── Branch rules API ────────────────────────────────────────────────────────

test('branch rules list what each branch changes and what applies there', function () {
    [$tenant, $branch] = apOrganisation(['geofence_required' => false]);
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'overrides' => ['geofence_required' => true]]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(apUrl($tenant, '/attendance/settings/branches'))
        ->assertOk()
        ->assertJsonPath('organisation.geofence_required', false)
        ->assertJsonPath('branches.0.public_id', $branch->public_id)
        ->assertJsonPath('branches.0.placed', true)
        ->assertJsonPath('branches.0.overrides.geofence_required', true)
        ->assertJsonPath('branches.0.overrides.kiosk_pin_required', null)
        ->assertJsonPath('branches.0.effective.geofence_required', true);
});

test('saving branch rules keeps only real differences and clears when none are left', function () {
    [$tenant, $branch] = apOrganisation(['kiosk_pin_required' => false]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $url = apUrl($tenant, "/attendance/settings/branches/{$branch->public_id}");

    test()->putJson($url, ['overrides' => [
        'kiosk_pin_required' => true,
        // Same as the organisation: not an override.
        'mobile_photo_required' => false,
        // Not overridable here: ignored.
        'photo_retention_days' => 1,
    ]])->assertOk()->assertJsonPath('overrides.kiosk_pin_required', true);

    expect(BranchAttendanceOverride::query()->sole()->overrides)->toBe(['kiosk_pin_required' => true]);

    test()->putJson($url, ['overrides' => ['kiosk_pin_required' => null]])->assertOk();
    expect(BranchAttendanceOverride::query()->exists())->toBeFalse();

    test()->putJson($url, ['overrides' => ['mobile_accuracy_threshold_meters' => 3]])->assertStatus(422);
});

test('an employee cannot read or change branch rules', function () {
    [$tenant, $branch] = apOrganisation();
    apEmployee($tenant, $branch);

    test()->getJson(apUrl($tenant, '/attendance/settings/branches'))->assertForbidden();
    test()->putJson(apUrl($tenant, "/attendance/settings/branches/{$branch->public_id}"), ['overrides' => []])->assertForbidden();
});

test("one organisation's branch rules never reach another's", function () {
    [$tenant, $branch] = apOrganisation();
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'overrides' => ['geofence_required' => true]]);
    [$other] = apOrganisation();
    actingAsUser(['role' => UserRole::HR_ADMIN], $other);

    test()->getJson(apUrl($other, '/attendance/settings/branches'))
        ->assertOk()
        ->assertJsonMissing(['public_id' => $branch->public_id]);
    test()->putJson(apUrl($other, "/attendance/settings/branches/{$branch->public_id}"), ['overrides' => []])
        ->assertNotFound();
});

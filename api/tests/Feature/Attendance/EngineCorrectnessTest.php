<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Engine defects found in the 2026-10-24 attendance review: an organisation
 * could lose its default shift, a required geofence refused everyone at a
 * branch nobody had placed yet, and today's summary did not count a punch
 * that had no shift to be rated against.
 */
afterEach(fn () => Carbon::setTestNow());

function ecUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

// ── The default shift ───────────────────────────────────────────────────────

test("an organisation's first shift becomes the default even if the box was not ticked", function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(ecUrl($tenant, '/shifts'), [
        'name' => 'Day',
        'start_time' => '08:30',
        'end_time' => '17:30',
    ])->assertCreated()->assertJsonPath('is_default', true);
});

test('the default cannot be unticked while another shift could take the role', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $default = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    Shift::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

    test()->putJson(ecUrl($tenant, "/shifts/{$default->public_id}"), ['is_default' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_default');

    test()->putJson(ecUrl($tenant, "/shifts/{$default->public_id}"), ['is_active' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_active');

    expect($default->fresh()->is_default)->toBeTrue();
});

test('making another shift the default moves the role', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $old = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $new = Shift::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

    test()->putJson(ecUrl($tenant, "/shifts/{$new->public_id}"), ['is_default' => true])->assertOk();

    expect($new->fresh()->is_default)->toBeTrue()
        ->and($old->fresh()->is_default)->toBeFalse();
});

test('the default cannot be deleted while other shifts are active', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $default = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    Shift::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);

    test()->deleteJson(ecUrl($tenant, "/shifts/{$default->public_id}"))->assertStatus(409);

    expect($default->fresh()->trashed())->toBeFalse();
});

test('an organisation left with no default is healed on the next shift change', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $oldest = Shift::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'is_default' => false]);
    $other = Shift::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'is_default' => false]);

    test()->putJson(ecUrl($tenant, "/shifts/{$other->public_id}"), ['name' => 'Renamed'])->assertOk();

    expect($oldest->fresh()->is_default)->toBeTrue()
        ->and($other->fresh()->is_default)->toBeFalse();
});

// ── A required geofence at a branch nobody has placed ───────────────────────

test('a required geofence at a branch with no coordinates records the punch and flags it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => null, 'longitude' => null]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'geofence_required' => true]);

    test()->postJson(ecUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => 'ec-geo-1',
        'latitude' => 9.0108,
        'longitude' => 38.7613,
    ])->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->status)->toBe(AttendanceStatus::PRESENT)
        ->and(AttendanceIntegrityFlag::where('attendance_record_id', $record->id)->pluck('type')->all())
        ->toContain('geofence_unconfigured');
});

test('a required geofence still refuses a punch outside a placed branch', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id, 'latitude' => 9.0108, 'longitude' => 38.7613, 'geofence_radius_meters' => 150,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'geofence_required' => true]);

    test()->postJson(ecUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => 'ec-geo-2',
        'latitude' => 9.0500,
        'longitude' => 38.7613,
    ])->assertStatus(422);

    expect(AttendanceRecord::where('employee_id', $employee->id)->exists())->toBeFalse();
});

// ── Today's summary ─────────────────────────────────────────────────────────

test('a punch with no shift to rate it still counts as present today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => null,
        'date' => '2026-10-16',
        'status' => AttendanceStatus::PENDING,
    ]);

    test()->actingAs($hr)->getJson(ecUrl($tenant, '/attendance/today'))
        ->assertOk()
        ->assertJsonPath('present', 1)
        ->assertJsonPath('absent', 0);
});

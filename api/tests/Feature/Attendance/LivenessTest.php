<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * On-device liveness: the phone checks for a live face before a selfie and
 * sends only the outcome. It is one risk signal, never a refusal.
 */
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Employee} */
function lvEmployee(array $settings = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => 9.0108, 'longitude' => 38.7613, 'geofence_radius_meters' => 150]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    return [$tenant, $employee];
}

test('the phone is told to check for a live face only when a selfie is due and the check is on', function () {
    [$tenant] = lvEmployee(['mobile_photo_required' => true, 'liveness_check' => true]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/my-shift";

    test()->getJson($url)->assertOk()->assertJsonPath('policy.liveness_required', true);

    AttendanceSetting::query()->update(['mobile_photo_required' => false]);
    test()->getJson($url)->assertOk()->assertJsonPath('policy.liveness_required', false);
});

test('a failed check is kept on the punch and scored, and the punch is still recorded', function () {
    [$tenant, $employee] = lvEmployee(['liveness_check' => true]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'lv-1', 'latitude' => 9.0108, 'longitude' => 38.7613, 'liveness' => 'failed',
    ])->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->metadata['liveness'])->toBe('failed')
        ->and($record->risk_reasons)->toHaveKey('liveness_failed');
});

test('a phone that could not run the check counts for nothing', function () {
    [$tenant, $employee] = lvEmployee(['liveness_check' => true]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'lv-2', 'latitude' => 9.0108, 'longitude' => 38.7613, 'liveness' => 'unavailable',
    ])->assertCreated();

    expect(AttendanceRecord::where('employee_id', $employee->id)->sole()->risk_score)->toBe(0);
});

test('only the known outcomes are accepted', function () {
    [$tenant] = lvEmployee();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'lv-3', 'latitude' => 9.0108, 'longitude' => 38.7613, 'liveness' => 'probably',
    ])->assertStatus(422)->assertJsonValidationErrors('liveness');
});

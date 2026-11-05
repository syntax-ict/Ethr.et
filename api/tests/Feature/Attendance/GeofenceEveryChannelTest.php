<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Exceptions\AttendanceRefused;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Carbon\Carbon;

// A-Z review D-01 (2026-11-04). "Require geofence" says it rejects check-ins
// outside the branch, but only a phone check-in was refused. A QR code
// photographed and forwarded, or the web button pressed at home, checked in
// with no location at all, or from outside the branch.

const GEO_BRANCH = [9.0108, 38.7613];
// About 2.2 km north of the branch.
const GEO_AWAY = [9.0308, 38.7613];

function geoSetUp(array $settings = []): Employee
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    app(CurrentTenant::class)->set($tenant);
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id, 'latitude' => GEO_BRANCH[0], 'longitude' => GEO_BRANCH[1], 'geofence_radius_meters' => 100,
    ]);
    AttendanceSetting::create([
        'tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'geofence_required' => true, ...$settings,
    ]);

    return Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
}

function geoCheckIn(Employee $employee, AttendanceSource $source, ?array $at, ?string $ip = '197.156.1.10'): AttendanceRecord
{
    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $employee->tenant_id,
        source: $source,
        type: 'check_in',
        idempotencyKey: 'geo-'.uniqid(),
        latitude: $at[0] ?? null,
        longitude: $at[1] ?? null,
        ipAddress: $ip,
    ))->record;
}

afterEach(fn () => Carbon::setTestNow());

dataset('self-service channels', [
    'phone' => [AttendanceSource::MOBILE],
    'QR code' => [AttendanceSource::QR],
    'web' => [AttendanceSource::WEB],
]);

it('refuses a check-in with no location', function (AttendanceSource $source) {
    $employee = geoSetUp();

    expect(fn () => geoCheckIn($employee, $source, null))
        ->toThrow(AttendanceRefused::class, __('attendance.geofence_location_required'));
})->with('self-service channels');

it('refuses a check-in from outside the branch', function (AttendanceSource $source) {
    $employee = geoSetUp();

    expect(fn () => geoCheckIn($employee, $source, GEO_AWAY))
        ->toThrow(AttendanceRefused::class, __('attendance.outside_geofence'));
})->with('self-service channels');

it('accepts a check-in inside the branch', function (AttendanceSource $source) {
    $employee = geoSetUp();

    expect(geoCheckIn($employee, $source, GEO_BRANCH)->geofence_verified)->toBeTrue();
})->with('self-service channels');

it('accepts a web check-in with no location from the office network', function () {
    $employee = geoSetUp(['office_networks' => ['197.156.1.0/24']]);

    expect(geoCheckIn($employee, AttendanceSource::WEB, null, '197.156.1.10')->exists)->toBeTrue();
    expect(fn () => geoCheckIn($employee, AttendanceSource::WEB, null, '10.9.8.7'))
        ->toThrow(AttendanceRefused::class, __('attendance.geofence_location_required'));
});

it('leaves a punch synced later from an offline phone to review rather than losing it', function () {
    $employee = geoSetUp();

    expect(geoCheckIn($employee, AttendanceSource::OFFLINE_MOBILE, null)->exists)->toBeTrue();
});

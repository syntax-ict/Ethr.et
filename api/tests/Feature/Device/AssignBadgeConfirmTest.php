<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\UnmatchedPunches;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

// Assigning a held badge asks a person to confirm first. The preview says what
// would happen and what speaks against it, and the assign request refuses to
// proceed past a warning the person has not acknowledged. The automatic
// recording of a strictly matching badge has no person and no such gate.

/** @return array{0: Tenant, 1: Device} */
function assignConfirmOrganisation(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id,
        'adapter_type' => 'zkteco_adms',
        'connection_config' => ['mode' => 'adms'],
        'serial_number' => 'ACF-SN-'.$tenant->id,
        'status' => 'online',
    ]);

    return [$tenant, $device];
}

/** @param  array<string, mixed>  $attributes */
function assignConfirmStaff(Tenant $tenant, string $name, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'name_am' => null,
        'badge_number' => null,
        'status' => EmployeeStatus::CONFIRMED,
        'hire_date' => '2024-01-01',
        'created_at' => '2024-01-02 08:00:00',
        ...$attributes,
    ]);
}

/** @param  list<string>  $days */
function assignConfirmHold(Tenant $tenant, Device $device, string $badge, array $days = ['2026-11-10', '2026-11-11']): void
{
    foreach ($days as $i => $day) {
        UnmatchedDevicePunch::create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'badge' => $badge,
            'punch_type' => 'check_in',
            'occurred_raw' => "{$day} 08:0{$i}:00",
            'idempotency_key' => "acf-{$device->id}-{$badge}-{$day}-{$i}",
            'status' => UnmatchedDevicePunch::PENDING,
        ]);
    }
}

/** @return array<string, string> */
function assignConfirmQuery(Device $device, string $badge, Employee $employee): array
{
    return ['device_public_id' => $device->public_id, 'badge' => $badge, 'employee_public_id' => $employee->public_id];
}

function assignConfirmUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/devices/unmatched-punches/{$path}";
}

function assignConfirmPreview(Tenant $tenant, Device $device, string $badge, Employee $employee): TestResponse
{
    return test()->getJson(assignConfirmUrl($tenant, 'preview').'?'.http_build_query(assignConfirmQuery($device, $badge, $employee)));
}

function assignConfirmPending(Device $device, string $badge): int
{
    return UnmatchedDevicePunch::withoutGlobalScopes()
        ->where('device_id', $device->id)->where('badge', $badge)
        ->where('status', UnmatchedDevicePunch::PENDING)->count();
}

it('counts the held punches and names the first and last day', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele', ['employee_code' => 'WOR-9']);
    assignConfirmHold($tenant, $device, 'R040', ['2026-11-12', '2026-11-10', '2026-11-12']);
    assignConfirmHold($tenant, $device, 'R041', ['2026-11-01']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $body = assignConfirmPreview($tenant, $device, 'R040', $person)->assertOk()->json();

    expect($body['punches'])->toBe(3)
        ->and($body['first_day'])->toBe('2026-11-10')
        ->and($body['last_day'])->toBe('2026-11-12')
        ->and($body['employee']['public_id'])->toBe($person->public_id)
        ->and($body['employee']['name'])->toBe('Hana Bekele')
        ->and($body['employee']['employee_code'])->toBe('WOR-9')
        ->and($body['employee']['status'])->toBe('confirmed')
        ->and($body['warnings'])->toBe([]);
});

it('answers no days when nothing is held, and writes nothing', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $body = assignConfirmPreview($tenant, $device, 'R999', $person)->assertOk()->json();

    expect($body['punches'])->toBe(0)->and($body['first_day'])->toBeNull()->and($body['last_day'])->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('warns about another badge, a late hire date, attendance of their own, and a badge held by someone else', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $holder = assignConfirmStaff($tenant, 'Kebede Alemu', ['employee_code' => 'R050']);
    $person = assignConfirmStaff($tenant, 'Hana Bekele', ['badge_number' => 'W-77', 'hire_date' => '2026-11-11', 'created_at' => '2026-09-01 08:00:00']);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'date' => '2026-11-11', 'status' => AttendanceStatus::PRESENT,
    ]);
    assignConfirmHold($tenant, $device, 'R050');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $warnings = assignConfirmPreview($tenant, $device, 'R050', $person)->assertOk()->json('warnings');

    expect($warnings)->toBe([
        ['code' => 'other_badge', 'value' => 'W-77'],
        ['code' => 'hired_after', 'value' => '2026-11-10'],
        ['code' => 'own_attendance', 'value' => 1],
        ['code' => 'moves_from', 'value' => 'Kebede Alemu'],
    ]);

    $own = assignConfirmPreview($tenant, $device, 'R050', $holder)->assertOk()->json('warnings');
    expect(array_column($own, 'code'))->not->toContain('moves_from');
});

it('names no one for moves_from when the viewer may not open the holder', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    assignConfirmStaff($tenant, 'Kebede Alemu', ['employee_code' => 'R051', 'department_id' => $theirs->id]);
    $person = assignConfirmStaff($tenant, 'Hana Bekele', ['department_id' => $mine->id]);
    $manager = assignConfirmStaff($tenant, 'Dept Manager', ['department_id' => $mine->id]);
    assignConfirmHold($tenant, $device, 'R051');
    test()->actingAs(createScopedUser($tenant, $manager->id, 'department', ['device.viewAny', 'attendance.manage']));

    $response = assignConfirmPreview($tenant, $device, 'R051', $person)->assertOk();

    expect($response->json('warnings'))->toBe([['code' => 'moves_from', 'value' => null]])
        ->and($response->getContent())->not->toContain('Kebede');
});

it('needs attendance.manage to preview', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele');
    assignConfirmHold($tenant, $device, 'R040');
    $viewer = assignConfirmStaff($tenant, 'Just A Viewer');
    test()->actingAs(createScopedUser($tenant, $viewer->id, 'organisation', ['device.viewAny']));

    assignConfirmPreview($tenant, $device, 'R040', $person)->assertForbidden();
});

it('answers 404 for an employee outside the viewer scope, and 422 when a field is missing', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $outside = assignConfirmStaff($tenant, 'Outside Person', ['department_id' => $theirs->id]);
    $manager = assignConfirmStaff($tenant, 'Dept Manager', ['department_id' => $mine->id]);
    assignConfirmHold($tenant, $device, 'R040');
    test()->actingAs(createScopedUser($tenant, $manager->id, 'department', ['device.viewAny', 'attendance.manage']));

    assignConfirmPreview($tenant, $device, 'R040', $outside)->assertNotFound();
    test()->getJson(assignConfirmUrl($tenant, 'preview').'?'.http_build_query(['badge' => 'R040']))->assertUnprocessable();
});

it('answers 404 for another organisation\'s device or employee', function () {
    [$other, $otherDevice] = assignConfirmOrganisation();
    $stranger = assignConfirmStaff($other, 'Other Org Person');
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele');
    assignConfirmHold($tenant, $device, 'R040');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    assignConfirmPreview($tenant, $otherDevice, 'R040', $person)->assertNotFound();
    assignConfirmPreview($tenant, $device, 'R040', $stranger)->assertNotFound();
});

it('refuses to assign past a warning that was not accepted, and changes nothing', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele', ['badge_number' => 'W-77']);
    assignConfirmHold($tenant, $device, 'R040');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(assignConfirmUrl($tenant, 'assign'), assignConfirmQuery($device, 'R040', $person))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('accept_warnings')
        ->assertJsonPath('warnings', ['other_badge']);

    test()->postJson(assignConfirmUrl($tenant, 'assign'), assignConfirmQuery($device, 'R040', $person) + ['accept_warnings' => false])
        ->assertUnprocessable();

    expect(assignConfirmPending($device, 'R040'))->toBe(2)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $person->id)->count())->toBe(0)
        ->and(DB::table('employee_external_identities')->where('employee_id', $person->id)->count())->toBe(0);
});

it('replays when the warnings are accepted, and the audit entry carries the server\'s own codes', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele', ['badge_number' => 'W-77']);
    assignConfirmHold($tenant, $device, 'R040');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(assignConfirmUrl($tenant, 'assign'), assignConfirmQuery($device, 'R040', $person) + ['accept_warnings' => true])
        ->assertOk()->assertJsonPath('replayed', 2);

    $entry = AuditLog::query()->where('action', 'device.badge_assigned')->sole();
    expect(assignConfirmPending($device, 'R040'))->toBe(0)
        ->and(json_encode($entry->toArray()))->toContain('other_badge');
});

it('assigns with no flag when there is nothing to warn of', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele');
    assignConfirmHold($tenant, $device, 'R040');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(assignConfirmUrl($tenant, 'assign'), assignConfirmQuery($device, 'R040', $person))
        ->assertOk()->assertJsonPath('replayed', 2);

    expect(AuditLog::query()->where('action', 'device.badge_assigned')->count())->toBe(1);
});

it('does not ask again for the next slice of a replay the same person has just started', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    $person = assignConfirmStaff($tenant, 'Hana Bekele');
    assignConfirmHold($tenant, $device, 'R040', ['2026-11-10', '2026-11-10']);
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    UnmatchedDevicePunch::withoutGlobalScopes()->where('device_id', $device->id)->orderBy('id')->first()->update([
        'status' => UnmatchedDevicePunch::REPLAYED, 'employee_id' => $person->id, 'resolved_by' => $user->id, 'resolved_at' => now(),
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'date' => '2026-11-10', 'status' => AttendanceStatus::PRESENT,
    ]);

    test()->postJson(assignConfirmUrl($tenant, 'assign'), assignConfirmQuery($device, 'R040', $person))->assertOk();
});

it('still records a strictly matching badge by itself, with no flag and no warnings gate', function () {
    [$tenant, $device] = assignConfirmOrganisation();
    assignConfirmStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['badge_number' => '7777']);
    assignConfirmHold($tenant, $device, '7777');
    DB::table('device_enrollment_snapshots')->insert([
        'tenant_id' => $tenant->id, 'device_id' => $device->id, 'device_user_id' => '7777', 'name' => 'Seblewengel Mekonen',
        'seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = app(UnmatchedPunches::class)->recordMatched($device, '7777', app(DevicePunchRecorder::class));

    expect($result)->not->toBeNull()->and(assignConfirmPending($device, '7777'))->toBe(0);
});

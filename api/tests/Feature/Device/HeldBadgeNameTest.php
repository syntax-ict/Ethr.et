<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceEnrollmentSnapshot;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\Device\HikvisionEvent;
use App\Services\Device\TerminalNames;

// A Hikvision terminal sends the enrolled person's name beside the number. ETHR
// kept only the number, so a held badge could not be traced to anyone. The name
// is kept and shown on the card with the people it could be; nothing is ever
// assigned from it.

/** @return array{0: Tenant, 1: Device} */
function badgeNamesOrganisation(string $token = 'badge-names-token'): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id,
        'adapter_type' => 'hikvision',
        'connection_config' => ['mode' => 'push'],
        'webhook_token' => $token,
        'timezone' => 'Africa/Addis_Ababa',
        'status' => 'pending',
    ]);

    return [$tenant, $device];
}

function badgeNamesSend(string $token, string $badge, ?string $name, string $time = '2026-11-17T09:00:00+03:00'): void
{
    // A real request carries no organisation: the token is all there is.
    app()->forgetScopedInstances();

    $event = ['employeeNoString' => $badge, 'attendanceStatus' => 'checkIn'];
    if ($name !== null) {
        $event['name'] = $name;
    }

    test()->post("http://ethr.test/api/v1/devices/webhook/hikvision?token={$token}", [
        'event_log' => json_encode(['dateTime' => $time, 'eventType' => 'AccessControllerEvent', 'AccessControllerEvent' => $event]),
    ])->assertOk()->assertJsonPath('status', 'accepted');
}

function badgeNamesHold(Tenant $tenant, Device $device, string $badge): void
{
    UnmatchedDevicePunch::create([
        'tenant_id' => $tenant->id,
        'device_id' => $device->id,
        'badge' => $badge,
        'punch_type' => 'check_in',
        'occurred_raw' => '2026-11-17 08:00:00',
        'idempotency_key' => "held-names-{$device->id}-{$badge}",
        'status' => UnmatchedDevicePunch::PENDING,
    ]);
}

function badgeNamesNote(Device $device, string $badge, ?string $name): void
{
    app(TerminalNames::class)->note($device, $badge, $name);
}

/** @param  array<string, mixed>  $attributes */
function badgeNamesStaff(Tenant $tenant, string $name, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'status' => EmployeeStatus::CONFIRMED,
        ...$attributes,
    ]);
}

function badgeNamesUrl(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/devices/unmatched-punches";
}

it('reads the name an event carries, cleaned and capped', function () {
    expect(HikvisionEvent::personName(['name' => "  Abebe \t Kebede\n"]))->toBe('Abebe Kebede')
        ->and(HikvisionEvent::personName(['name' => "Ab\x00ebe"]))->toBe('Ab ebe')
        ->and(HikvisionEvent::personName(['name' => str_repeat('x', 300)]))->toHaveLength(100)
        ->and(HikvisionEvent::personName(['name' => '   ']))->toBeNull()
        ->and(HikvisionEvent::personName(['name' => ['a']]))->toBeNull()
        ->and(HikvisionEvent::personName([]))->toBeNull();
});

it('keeps the name a terminal sends, one row per badge, and updates it', function () {
    [, $device] = badgeNamesOrganisation();

    badgeNamesSend('badge-names-token', 'R011', 'Abebe Kebede', '2026-11-17T09:00:00+03:00');
    badgeNamesSend('badge-names-token', 'R011', 'Abebe K Tesfaye', '2026-11-17T10:00:00+03:00');

    $rows = DeviceEnrollmentSnapshot::withoutGlobalScopes()->where('device_id', $device->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->device_user_id)->toBe('R011')
        ->and($rows[0]->name)->toBe('Abebe K Tesfaye')
        ->and($rows[0]->tenant_id)->toBe($device->tenant_id);
});

it('writes no row for an event with no name, and still accepts the punch', function () {
    [, $device] = badgeNamesOrganisation();

    badgeNamesSend('badge-names-token', 'R011', null);

    expect(DeviceEnrollmentSnapshot::withoutGlobalScopes()->where('device_id', $device->id)->count())->toBe(0)
        ->and(UnmatchedDevicePunch::withoutGlobalScopes()->where('device_id', $device->id)->count())->toBe(1);
});

it('leaves what an ADMS roster row holds when a name is noted for the same user id', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    DeviceEnrollmentSnapshot::create([
        'tenant_id' => $tenant->id,
        'device_id' => $device->id,
        'device_user_id' => '88',
        'name' => 'Old Name',
        'card_number' => '0012345',
        'is_admin' => true,
        'fingerprint_count' => 3,
        'face_registered' => true,
        'seen_at' => now()->subDay(),
    ]);

    badgeNamesNote($device, '88', 'New Name');

    $row = DeviceEnrollmentSnapshot::withoutGlobalScopes()->where('device_id', $device->id)->sole();

    expect($row->name)->toBe('New Name')
        ->and($row->card_number)->toBe('0012345')
        ->and($row->is_admin)->toBeTrue()
        ->and($row->fingerprint_count)->toBe(3)
        ->and($row->face_registered)->toBeTrue();
});

it('shows a manager the terminal name and the people it could be', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Land Office']);
    $abebe = badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye', ['department_id' => $department->id]);
    badgeNamesStaff($tenant, 'Kebede Alemu');
    badgeNamesHold($tenant, $device, 'R011');
    badgeNamesNote($device, 'R011', 'Abebe Kebede');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect($row['name_on_device'])->toBe('Abebe Kebede')
        ->and($row['name_matches'])->toHaveCount(1)
        ->and($row['name_matches'][0]['employee_public_id'])->toBe($abebe->public_id)
        ->and($row['name_matches'][0]['employee_name'])->toBe('Abebe Kebede Tesfaye')
        ->and($row['name_matches'][0]['department'])->toBe('Land Office');
});

it('tells someone who cannot manage attendance nothing of the name', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye');
    badgeNamesHold($tenant, $device, 'R011');
    badgeNamesNote($device, 'R011', 'Abebe Kebede');
    $role = CustomRole::query()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Device viewer', 'is_active' => true, 'org_scope' => 'all']);
    $role->permissions()->sync(Permission::whereIn('name', ['device.viewAny'])->pluck('id'));
    Permission::clearCacheForCustomRole($role->id);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant));

    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect($row['name_on_device'])->toBeNull()
        ->and($row['name_matches'])->toBe([]);
});

it('never offers another organisation a person, and never reads its device names', function () {
    [$tenant, $device] = badgeNamesOrganisation('token-a');
    [$other, $otherDevice] = badgeNamesOrganisation('token-b');
    badgeNamesStaff($other, 'Abebe Kebede Tesfaye');
    badgeNamesHold($tenant, $device, 'R011');
    // The other organisation's terminal has a name for the same number.
    badgeNamesNote($otherDevice, 'R011', 'Someone Else');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');
    expect($row['name_on_device'])->toBeNull()->and($row['name_matches'])->toBe([]);

    // Same terminal name, a same-named person only in the other organisation.
    badgeNamesNote($device, 'R011', 'Abebe Kebede');
    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect($row['name_on_device'])->toBe('Abebe Kebede')
        ->and($row['name_matches'])->toBe([])
        ->and(app(TerminalNames::class)->forBadges($tenant->id, [[$otherDevice->id, 'R011']]))->toBe([]);
});

it('suggests nobody for a one-word name, or a name more than five people share', function () {
    [$tenant] = badgeNamesOrganisation();
    badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye');
    foreach (range(1, 6) as $n) {
        badgeNamesStaff($tenant, "Mulu Haile Person{$n}x");
    }
    $names = app(TerminalNames::class);

    expect($names->lookalikes($tenant->id, 'Abebe'))->toBe([])
        ->and($names->lookalikes($tenant->id, 'Mulu Haile'))->toBe([])
        ->and($names->lookalikes($tenant->id, 'Abebe Kebede'))->toHaveCount(1);
});

it('does not suggest a person who has left, or one whose name only contains the letters', function () {
    [$tenant] = badgeNamesOrganisation();
    badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye')->delete();
    badgeNamesStaff($tenant, 'Kebede Alemu');
    badgeNamesStaff($tenant, 'Gabebe Kebedew');

    expect(app(TerminalNames::class)->lookalikes($tenant->id, 'Abebe Kebede'))->toBe([]);
});

it('says a number equal to an employee code is only probable when the names share no word', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    badgeNamesStaff($tenant, 'Selam Tesfaye', ['employee_code' => '66']);
    badgeNamesHold($tenant, $device, '66');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    // Without a name the number alone decides, as before.
    expect(test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0.match.outcome'))->toBe('matched');

    badgeNamesNote($device, '66', 'Guest Visitor');
    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect($row['match']['outcome'])->not->toBe('matched')
        ->and($row['match']['outcome'])->toBe('probable');
});

it('lists no name matches for a badge the card already says whose it is', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    badgeNamesStaff($tenant, 'Selam Tesfaye', ['employee_code' => '66']);
    badgeNamesStaff($tenant, 'Selam Tesfaye Bekele');
    badgeNamesHold($tenant, $device, '66');
    badgeNamesNote($device, '66', 'Selam Tesfaye');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect($row['match']['outcome'])->toBe('matched')
        ->and($row['name_on_device'])->toBe('Selam Tesfaye')
        ->and($row['name_matches'])->toBe([]);
});

it('leaves out a person the viewer may not open', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye', ['department_id' => $theirs->id]);
    $visible = badgeNamesStaff($tenant, 'Abebe Kebede Girma', ['department_id' => $mine->id]);
    $manager = badgeNamesStaff($tenant, 'Dept Manager', ['department_id' => $mine->id]);
    badgeNamesHold($tenant, $device, 'R011');
    badgeNamesNote($device, 'R011', 'Abebe Kebede');
    test()->actingAs(createScopedUser($tenant, $manager->id, 'department', ['device.viewAny', 'attendance.manage']));

    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect(array_column($row['name_matches'], 'employee_public_id'))->toBe([$visible->public_id])
        ->and(json_encode($row))->not->toContain('Tesfaye');
});

it('finds the name of a held badge whatever the case the terminal first sent it in', function () {
    [$tenant, $device] = badgeNamesOrganisation();
    badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye');
    badgeNamesNote($device, 'r011', 'Abebe Kebede');
    badgeNamesHold($tenant, $device, 'R011');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeNamesUrl($tenant))->assertOk()->json('data.0');

    expect($row['badge'])->toBe('R011')
        ->and($row['name_on_device'])->toBe('Abebe Kebede')
        ->and($row['name_matches'])->toHaveCount(1);
});

it('matches the words of a terminal name to the employee name from the first, in order', function () {
    [$tenant] = badgeNamesOrganisation();
    $child = badgeNamesStaff($tenant, 'Abebe Kebede Tesfaye');
    $names = app(TerminalNames::class);
    $ids = fn (string $name): array => array_map(fn (Employee $e) => $e->id, $names->lookalikes($tenant->id, $name));

    expect($ids('Abebe Kebede'))->toBe([$child->id])
        ->and($ids('Abebe Kebede Tesfaye'))->toBe([$child->id])
        ->and($ids('Abe Keb'))->toBe([$child->id])
        // His father, whose names are the child's second and third.
        ->and($ids('Kebede Tesfaye'))->toBe([])
        ->and($ids('Kebede Abebe'))->toBe([])
        ->and($ids('Tigist Abebe Kebede'))->toBe([])
        ->and($ids('Abebe Tesfaye Kebede'))->toBe([])
        ->and($ids('Abebe Kebede Tesfaye Alemu'))->toBe([]);
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\CurrentTenant;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\TerminalNames;
use App\Services\Device\UnmatchedPunches;
use Illuminate\Support\Facades\DB;

// The Unrecognised badges card suggests people from the name a terminal sends,
// forgiving the way Ethiopian names are spelled (Mekonnen, Mekonen, Makonnen;
// an Ethiopic record against a Latin terminal name), and says who each person
// is and what speaks against them. It only suggests: a name never assigns a
// badge, and the automatic recording keeps its strict rule.

/** @return array{0: Tenant, 1: Device} */
function badgeSugOrganisation(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id,
        'adapter_type' => 'zkteco_adms',
        'connection_config' => ['mode' => 'adms'],
        'serial_number' => 'SUG-SN-'.$tenant->id,
        'status' => 'online',
    ]);

    return [$tenant, $device];
}

/** @param  array<string, mixed>  $attributes */
function badgeSugStaff(Tenant $tenant, string $name, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'name_am' => null,
        'badge_number' => null,
        'status' => EmployeeStatus::CONFIRMED,
        ...$attributes,
    ]);
}

/** @param  list<string>  $days */
function badgeSugHold(Tenant $tenant, Device $device, string $badge, array $days = ['2026-11-10', '2026-11-11']): void
{
    foreach ($days as $day) {
        UnmatchedDevicePunch::create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'badge' => $badge,
            'punch_type' => 'check_in',
            'occurred_raw' => "{$day} 08:00:00",
            'idempotency_key' => "sug-{$device->id}-{$badge}-{$day}",
            'status' => UnmatchedDevicePunch::PENDING,
        ]);
    }
}

function badgeSugName(Tenant $tenant, Device $device, string $badge, string $name): void
{
    DB::table('device_enrollment_snapshots')->insert([
        'tenant_id' => $tenant->id, 'device_id' => $device->id, 'device_user_id' => $badge, 'name' => $name,
        'seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<string> the employees' names, in the order suggested */
function badgeSugNames(Tenant $tenant, string $terminalName): array
{
    return array_map(
        fn (array $s): string => $s['employee']->name.' ('.$s['fit'].')',
        app(TerminalNames::class)->suggestions($tenant->id, $terminalName),
    );
}

function badgeSugUrl(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/devices/unmatched-punches";
}

it('fits the spellings of an Ethiopian name to each other', function () {
    [$tenant] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye');

    foreach (['Seblewongel Mekonnen', 'Seblewengel Makonnen', 'Seblewongel Makonnen', 'Seblewengel Mekonen'] as $terminal) {
        $names = array_map(fn (array $s) => $s['employee']->name, app(TerminalNames::class)->suggestions($tenant->id, $terminal));

        expect($names)->toBe(['Seblewengel Mekonen Tesfaye'], $terminal);
    }
});

it('fits a name written in Ethiopic to a Latin terminal name, and the other way round', function () {
    [$tenant] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Foo Bar', ['name_am' => 'ሰብለወንጌል መኮንን ተስፋዬ']);
    badgeSugStaff($tenant, 'ሰብለወንጌል መኮንን');

    expect(badgeSugNames($tenant, 'Seblewongel Mekonnen'))->toBe(['Foo Bar (similar)', 'ሰብለወንጌል መኮንን (similar)'])
        ->and(badgeSugNames($tenant, 'ሰብለወንጌል መኮንን'))->toBe(['Foo Bar (same)', 'ሰብለወንጌል መኮንን (same)']);
});

it('puts the exact fits before the ones spelled differently', function () {
    [$tenant] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Seblewengel Mekonen Alemu');
    badgeSugStaff($tenant, 'Seblewongel Mekonnen Bekele');

    expect(badgeSugNames($tenant, 'Seblewongel Mekonnen'))
        ->toBe(['Seblewongel Mekonnen Bekele (same)', 'Seblewengel Mekonen Alemu (similar)']);
});

it('does not fit the father first, a single word, a third word that differs, or someone else', function () {
    [$tenant] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye');
    badgeSugStaff($tenant, 'Tigist Haile Girma');

    expect(badgeSugNames($tenant, 'Mekonnen Seblewongel'))->toBe([])
        ->and(badgeSugNames($tenant, 'Seblewongel'))->toBe([])
        ->and(badgeSugNames($tenant, 'Seblewongel Mekonnen Bekele'))->toBe([])
        ->and(badgeSugNames($tenant, 'Hanna Girma'))->toBe([]);
});

it('suggests nobody when more than five people fit exactly, and keeps the exact ones when only spellings add up to more', function () {
    [$tenant] = badgeSugOrganisation();
    foreach (range(1, 6) as $n) {
        badgeSugStaff($tenant, "Mulu Haile Person{$n}x");
    }
    expect(badgeSugNames($tenant, 'Mulu Haile'))->toBe([]);

    badgeSugStaff($tenant, 'Abebe Kebede Tesfaye');
    foreach (range(1, 5) as $n) {
        badgeSugStaff($tenant, "Ababe Kebede Spelled{$n}x");
    }
    expect(badgeSugNames($tenant, 'Abebe Kebede'))->toBe(['Abebe Kebede Tesfaye (same)']);

    foreach (range(1, 6) as $n) {
        badgeSugStaff($tenant, "Zeru Bekele Exact{$n}x");
    }
    badgeSugStaff($tenant, 'Zaru Bekele Spelled');
    expect(badgeSugNames($tenant, 'Zeru Bekele'))->toBe([]);
});

it('never suggests a person who has left the records or an employee of another organisation', function () {
    [$tenant] = badgeSugOrganisation();
    [$other] = badgeSugOrganisation();
    badgeSugStaff($other, 'Seblewengel Mekonen Tesfaye');
    badgeSugStaff($tenant, 'Seblewengel Mekonen Alemu')->delete();

    expect(badgeSugNames($tenant, 'Seblewongel Mekonnen'))->toBe([])
        ->and(badgeSugNames($other, 'Seblewongel Mekonnen'))->toBe(['Seblewengel Mekonen Tesfaye (similar)']);
});

it('lists a person spelled differently on the card with the full identity line', function () {
    [$tenant, $device] = badgeSugOrganisation();
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Land Office']);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Surveyor']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Habru Branch']);
    $person = badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', [
        'department_id' => $department->id,
        'position_id' => $position->id,
        'branch_id' => $branch->id,
        'hire_date' => '2024-03-01',
        'employee_code' => 'WOR-0042',
    ]);
    badgeSugHold($tenant, $device, 'R030');
    badgeSugName($tenant, $device, 'R030', 'Seblewongel Mekonnen');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0');

    expect($row['name_matches'])->toHaveCount(1)
        ->and($row['name_matches'][0]['employee_public_id'])->toBe($person->public_id)
        ->and($row['name_matches'][0]['fit'])->toBe('similar')
        ->and($row['name_matches'][0]['department'])->toBe('Land Office')
        ->and($row['name_matches'][0]['identity'])->toBe([
            'employee_code' => 'WOR-0042',
            'department' => 'Land Office',
            'position' => 'Surveyor',
            'branch' => 'Habru Branch',
            'hire_date' => '2024-03-01',
            'status' => 'confirmed',
            'badge_number' => null,
            'warnings' => [],
        ]);
});

it('warns about a person who carries another badge, was hired after the first punch, or has attendance of their own', function () {
    [$tenant, $device] = badgeSugOrganisation();
    $person = badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['badge_number' => 'W-77', 'hire_date' => '2026-11-11', 'created_at' => '2026-09-01 08:00:00']);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'date' => '2026-11-11', 'status' => AttendanceStatus::PRESENT,
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'date' => '2026-11-10', 'status' => AttendanceStatus::VOIDED,
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $person->id, 'date' => '2026-11-20', 'status' => AttendanceStatus::PRESENT,
    ]);
    badgeSugHold($tenant, $device, 'R030');
    badgeSugName($tenant, $device, 'R030', 'Seblewongel Mekonnen');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $identity = test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0.name_matches.0.identity');

    expect($identity['warnings'])->toBe([
        ['code' => 'other_badge', 'value' => 'W-77'],
        ['code' => 'hired_after', 'value' => '2026-11-10'],
        ['code' => 'own_attendance', 'value' => 1],
    ])->and($identity['badge_number'])->toBe('W-77');
});

/** @return list<array{code: string, value: string|int|null}> */
function badgeSugHiredWarnings(string $hireDate, string $createdAtUtc): array
{
    [$tenant, $device] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['hire_date' => $hireDate, 'created_at' => $createdAtUtc]);
    badgeSugHold($tenant, $device, 'R030');
    badgeSugName($tenant, $device, 'R030', 'Seblewongel Mekonnen');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    return test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0.name_matches.0.identity.warnings');
}

it('does not warn of a late hire date that is only the day the record was created', function () {
    expect(badgeSugHiredWarnings('2026-11-15', '2026-11-15 09:00:00'))->toBe([]);
});

it('still warns of a late hire date that is not the day the record was created', function () {
    expect(badgeSugHiredWarnings('2026-11-15', '2026-11-01 09:00:00'))->toBe([
        ['code' => 'hired_after', 'value' => '2026-11-10'],
    ]);
});

it('reads the creation day in the organisation time zone, so a late UTC evening is the next local day', function () {
    expect(badgeSugHiredWarnings('2026-11-16', '2026-11-15 22:30:00'))->toBe([])
        ->and(badgeSugHiredWarnings('2026-11-15', '2026-11-15 22:30:00'))->toBe([
            ['code' => 'hired_after', 'value' => '2026-11-10'],
        ]);
});

it('warns of nothing for a person with no other badge, hired earlier, with no attendance on those days', function () {
    [$tenant, $device] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['hire_date' => '2020-01-01']);
    badgeSugHold($tenant, $device, 'R030');
    badgeSugName($tenant, $device, 'R030', 'Seblewongel Mekonnen');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    expect(test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0.name_matches.0.identity.warnings'))->toBe([]);
});

it('gives the people the resolver says it may be the same identity line', function () {
    [$tenant, $device] = badgeSugOrganisation();
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Records']);
    badgeSugStaff($tenant, 'Selam Tesfaye', ['employee_code' => '66', 'department_id' => $department->id]);
    badgeSugHold($tenant, $device, '66');
    badgeSugName($tenant, $device, '66', 'Guest Visitor');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0');

    expect($row['match']['outcome'])->toBe('probable')
        ->and($row['match']['candidates'][0]['identity']['department'])->toBe('Records')
        ->and($row['match']['candidates'][0]['identity']['employee_code'])->toBe('66');
});

it('leaves a person the viewer may not open out of the suggestions', function () {
    [$tenant, $device] = badgeSugOrganisation();
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['department_id' => $theirs->id]);
    $visible = badgeSugStaff($tenant, 'Seblewengel Mekonnen Girma', ['department_id' => $mine->id]);
    $manager = badgeSugStaff($tenant, 'Dept Manager', ['department_id' => $mine->id]);
    badgeSugHold($tenant, $device, 'R030');
    badgeSugName($tenant, $device, 'R030', 'Seblewongel Mekonnen');
    test()->actingAs(createScopedUser($tenant, $manager->id, 'department', ['device.viewAny', 'attendance.manage']));

    $row = test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0');

    expect(array_column($row['name_matches'], 'employee_public_id'))->toBe([$visible->public_id])
        ->and(json_encode($row))->not->toContain('Tesfaye');
});

it('never offers an employee or attendance of another organisation', function () {
    [$tenant, $device] = badgeSugOrganisation();
    [$other] = badgeSugOrganisation();
    $theirs = badgeSugStaff($other, 'Seblewengel Mekonen Tesfaye');
    AttendanceRecord::factory()->create(['tenant_id' => $other->id, 'employee_id' => $theirs->id, 'date' => '2026-11-10']);
    badgeSugStaff($tenant, 'Seblewengel Mekonnen Girma');
    badgeSugHold($tenant, $device, 'R030');
    badgeSugName($tenant, $device, 'R030', 'Seblewongel Mekonnen');
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $row = test()->getJson(badgeSugUrl($tenant))->assertOk()->json('data.0');

    expect($row['name_matches'])->toHaveCount(1)
        ->and($row['name_matches'][0]['employee_name'])->toBe('Seblewengel Mekonnen Girma')
        ->and($row['name_matches'][0]['identity']['warnings'])->toBe([]);
});

it('reads the identity, the days and the attendance in a fixed number of queries however many badges it lists', function () {
    [$tenant, $device] = badgeSugOrganisation();
    foreach (range(1, 8) as $n) {
        badgeSugStaff($tenant, "Seblewengel Mekonen Person{$n}x", ['hire_date' => '2020-01-01']);
    }
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $queries = function () use ($tenant): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson(badgeSugUrl($tenant))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // The statements this feature adds: one read of the people, of the
        // days, and of the attendance, whatever the number of badges.
        return count(array_filter($log, fn (array $q): bool => str_contains($q['query'], 'attendance_records')
            || str_contains($q['query'], 'SUBSTR(occurred_raw')
            || (str_contains($q['query'], 'from `employees`') || str_contains($q['query'], 'from "employees"'))));
    };

    foreach (['R001', 'R002'] as $badge) {
        badgeSugHold($tenant, $device, $badge);
        badgeSugName($tenant, $device, $badge, 'Seblewongel Mekonnen');
    }
    $few = $queries();

    foreach (['R003', 'R004', 'R005', 'R006', 'R007', 'R008'] as $badge) {
        badgeSugHold($tenant, $device, $badge);
        badgeSugName($tenant, $device, $badge, 'Seblewongel Mekonnen');
    }
    $many = $queries();

    // The resolver reads employees per badge (its own queries), so only the
    // growth that the suggestions add is bounded: the attendance and day
    // statements are one each, and the people one more, at either size.
    expect($many - $few)->toBeLessThanOrEqual(6 * 4);
});

it('never records a badge by itself on a name that only fits loosely', function () {
    [$tenant, $device] = badgeSugOrganisation();
    $person = badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['badge_number' => '7777']);
    badgeSugHold($tenant, $device, '7777');
    badgeSugName($tenant, $device, '7777', 'Seblewongel Mekonnen');
    $unmatched = app(UnmatchedPunches::class);

    $suggested = array_map(fn (array $s) => $s['employee']->id, app(TerminalNames::class)->suggestions($tenant->id, 'Seblewongel Mekonnen'));

    expect($suggested)->toBe([$person->id])
        ->and(app(TerminalNames::class)->lookalikes($tenant->id, 'Seblewongel Mekonnen'))->toBe([])
        ->and($unmatched->recordMatched($device, '7777', app(DevicePunchRecorder::class)))->toBeNull()
        ->and(UnmatchedDevicePunch::query()->where('status', UnmatchedDevicePunch::PENDING)->count())->toBe(2);
});

it('still records a badge when the terminal name is the exact one, so the test above can see a positive', function () {
    [$tenant, $device] = badgeSugOrganisation();
    badgeSugStaff($tenant, 'Seblewengel Mekonen Tesfaye', ['badge_number' => '7777']);
    badgeSugHold($tenant, $device, '7777');
    badgeSugName($tenant, $device, '7777', 'Seblewengel Mekonen');

    $result = app(UnmatchedPunches::class)->recordMatched($device, '7777', app(DevicePunchRecorder::class));

    expect($result)->not->toBeNull()
        ->and(UnmatchedDevicePunch::query()->where('status', UnmatchedDevicePunch::PENDING)->count())->toBe(0);
});

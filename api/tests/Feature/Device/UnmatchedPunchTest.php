<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Device\UnmatchedPunchController;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\Migration\WorkforceMigrationService;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

// A punch from a badge no employee matched is held, not dropped, and replayed
// once someone says whose badge it is. Until 2026-10-20 every device path
// logged the badge and discarded the punch (blueprint assessment AT3, AT20).

const UNMATCHED_SN = 'CQZ7232460777';

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function unmatchedSetup(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 18:00:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms',
        'serial_number' => UNMATCHED_SN,
        'connection_config' => ['mode' => 'adms'],
        'webhook_ip_allowlist' => ['127.0.0.1'],
        'status' => 'pending',
    ]);
    // Badge 7777 is on the device; the employee's record does not say so.
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'badge_number' => null, 'name' => 'Selam Tesfaye', 'status' => EmployeeStatus::CONFIRMED,
    ]);

    return [$tenant, $device->fresh(), $employee];
}

function unmatchedPost(string $body): TestResponse
{
    $uri = '/iclock/cdata?'.http_build_query(['SN' => UNMATCHED_SN, 'table' => 'ATTLOG']);

    return test()->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

function unmatchedUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/devices/unmatched-punches{$path}";
}

const UNMATCHED_DAY = "7777\t2026-10-14 08:01:22\t0\t1\t0\t0\t0\n7777\t2026-10-14 17:05:00\t1\t1\t0\t0\t0\n";

afterEach(fn () => Carbon::setTestNow());

it('holds a punch from an unknown badge instead of dropping it, once', function () {
    [, $device] = unmatchedSetup();

    unmatchedPost(UNMATCHED_DAY)->assertOk();
    unmatchedPost(UNMATCHED_DAY)->assertOk();

    expect(UnmatchedDevicePunch::withoutGlobalScopes()->where('device_id', $device->id)->count())->toBe(2)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('lists held badges by device for people who see devices', function () {
    [$tenant, $device] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(unmatchedUrl($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.device_public_id', $device->public_id)
        ->assertJsonPath('data.0.badge', '7777')
        ->assertJsonPath('data.0.punches', 2);
});

it('replays the held punches when the badge is assigned, and remembers whose it is', function () {
    [$tenant, $device, $employee] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(unmatchedUrl($tenant, '/assign'), [
        'device_public_id' => $device->public_id,
        'badge' => '7777',
        'employee_public_id' => $employee->public_id,
    ])->assertOk()->assertJsonPath('replayed', 2)->assertJsonPath('refused', 0);

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->check_in->setTimezone('Africa/Addis_Ababa')->format('H:i:s'))->toBe('08:01:22')
        ->and($record->check_out->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('17:05')
        ->and(EmployeeExternalIdentity::where('employee_id', $employee->id)->whereNotNull('verified_at')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'device.badge_assigned')->exists())->toBeTrue();

    // The next day the badge resolves at once, and nothing is held.
    Carbon::setTestNow(Carbon::parse('2026-10-15 18:00:00', 'Africa/Addis_Ababa'));
    unmatchedPost("7777\t2026-10-15 08:00:00\t0\t1\t0\t0\t0\n");
    expect(AttendanceRecord::where('employee_id', $employee->id)->count())->toBe(2)
        ->and(UnmatchedDevicePunch::where('status', UnmatchedDevicePunch::PENDING)->count())->toBe(0);
});

it('drops the held punches of a badge that is no one\'s', function () {
    [$tenant, $device] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(unmatchedUrl($tenant, '/dismiss'), ['device_public_id' => $device->public_id, 'badge' => '7777'])
        ->assertOk()->assertJsonPath('dismissed', 2);

    test()->getJson(unmatchedUrl($tenant))->assertOk()->assertJsonCount(0, 'data');
});

it('lets only attendance managers assign or dismiss', function () {
    [$tenant, $device, $employee] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->postJson(unmatchedUrl($tenant, '/assign'), [
        'device_public_id' => $device->public_id, 'badge' => '7777', 'employee_public_id' => $employee->public_id,
    ])->assertForbidden();
    test()->postJson(unmatchedUrl($tenant, '/dismiss'), ['device_public_id' => $device->public_id, 'badge' => '7777'])
        ->assertForbidden();
});

it('never shows one organisation another\'s held badges', function () {
    unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    $other = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $other);

    test()->getJson(unmatchedUrl($other))->assertOk()->assertJsonCount(0, 'data');
});

// The card tells the truth about each badge (2026-11-12). The table holds the
// badge, not the verdict it had when it arrived, so the index asks again.

it('says a badge nobody has is new', function () {
    [$tenant] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(unmatchedUrl($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.match.outcome', 'new')
        ->assertJsonPath('data.0.match.employee_public_id', null);
});

it('says whose a badge is once its holder arrived by import, so the punches can be replayed', function () {
    [$tenant] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $migration = app(WorkforceMigrationService::class);
    $migration->commit($migration->stageFromRows([
        ['name' => 'Dawit Bekele', 'badge_number' => '7777', 'hire_date' => '2026-10-01'],
    ], $tenant->id));
    $dawit = Employee::where('name', 'Dawit Bekele')->sole();

    test()->getJson(unmatchedUrl($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.match.outcome', 'matched')
        ->assertJsonPath('data.0.match.employee_public_id', $dawit->public_id)
        ->assertJsonPath('data.0.match.candidates.0.employee_name', 'Dawit Bekele');

    // Linking is not replaying: the held punches wait for a person to say so.
    expect(UnmatchedDevicePunch::where('status', UnmatchedDevicePunch::PENDING)->count())->toBe(2);
});

it('suggests nothing to someone who cannot act on it', function () {
    [$tenant] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    $role = CustomRole::query()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Device viewer', 'is_active' => true, 'org_scope' => 'all']);
    $role->permissions()->sync(Permission::whereIn('name', ['device.viewAny'])->pluck('id'));
    Permission::clearCacheForCustomRole($role->id);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant));

    test()->getJson(unmatchedUrl($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.badge', '7777')
        ->assertJsonPath('data.0.match', null);
});

it('names nobody a department-scoped manager cannot open', function () {
    [$tenant, , $selam] = unmatchedSetup();
    unmatchedPost(UNMATCHED_DAY);
    $selam->update(['badge_number' => '7777']);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $other = Department::factory()->create(['tenant_id' => $tenant->id]);
    $selam->update(['department_id' => $other->id]);
    $manager = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);
    $role = CustomRole::query()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Dept attendance', 'is_active' => true, 'org_scope' => 'department']);
    $role->permissions()->sync(Permission::whereIn('name', ['device.viewAny', 'attendance.manage'])->pluck('id'));
    Permission::clearCacheForCustomRole($role->id);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $manager->id, 'custom_role_id' => $role->id], $tenant));

    $row = test()->getJson(unmatchedUrl($tenant))->assertOk()->json('data.0');

    expect($row['match']['employee_public_id'])->toBeNull()
        ->and($row['match']['candidates'])->toBe([])
        ->and(json_encode($row))->not->toContain('Selam')
        ->and(json_encode($row))->not->toContain($selam->public_id);

    // Moved into their department, the same person is named.
    $selam->update(['department_id' => $theirs->id]);
    test()->getJson(unmatchedUrl($tenant))
        ->assertOk()
        ->assertJsonPath('data.0.match.employee_public_id', $selam->public_id);
});

it('resolves only the most recent badges, and lists the rest without a suggestion', function () {
    [$tenant, $device] = unmatchedSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $limit = UnmatchedPunchController::RESOLVE_LIMIT;
    foreach (range(1, $limit + 1) as $n) {
        UnmatchedDevicePunch::create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'badge' => "B{$n}",
            'punch_type' => 'check_in',
            'occurred_raw' => '2026-10-14 08:00:00',
            'idempotency_key' => "cap-{$n}",
            'status' => UnmatchedDevicePunch::PENDING,
        ])->forceFill(['created_at' => now()->subMinutes($n)])->save();
    }

    $data = test()->getJson(unmatchedUrl($tenant))->assertOk()->json('data');

    expect($data)->toHaveCount($limit + 1)
        ->and($data[0]['badge'])->toBe('B1')
        ->and($data[0]['match']['outcome'])->toBe('new')
        ->and($data[$limit - 1]['match'])->not->toBeNull()
        ->and($data[$limit]['badge'])->toBe('B'.($limit + 1))
        ->and($data[$limit]['match'])->toBeNull();
});

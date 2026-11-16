<?php

declare(strict_types=1);

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\Migration\MigrationTerminals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * After a list is added: what the terminals hold for the people it brought in.
 * The people are sent by the observer that watches every employee save, so
 * these tests build people the way the observer sees them and read the answer.
 */
function terminalsDevice(Tenant $tenant, Branch $branch, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms',
        'commands_enabled' => true,
        ...$attributes,
    ]);
}

/** @param  list<Employee>  $people */
function terminalsBatch(Tenant $tenant, array $people): MigrationBatch
{
    $batch = MigrationBatch::create(['tenant_id' => $tenant->id, 'source_type' => 'manual', 'status' => 'committed', 'totals' => []]);

    foreach ($people as $employee) {
        MigrationStagingRow::create([
            'tenant_id' => $tenant->id,
            'batch_id' => $batch->id,
            'raw' => ['name' => $employee->name],
            'display_name' => $employee->name,
            'resolved_employee_id' => $employee->id,
            'action' => 'create',
            'processed_at' => now(),
        ]);
    }

    return $batch;
}

function terminalsPerson(Tenant $tenant, ?Branch $branch, ?string $badge, string $name = 'Abebe Kebede', array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch?->id,
        'badge_number' => $badge,
        'name' => $name,
        'status' => EmployeeStatus::HIRED,
        ...$attributes,
    ]);
}

function terminalsUrl(Tenant $tenant, MigrationBatch|string $batch): string
{
    $id = $batch instanceof MigrationBatch ? $batch->public_id : $batch;

    return "http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/migration/batches/{$id}/terminals";
}

function terminalsCommand(Device $device, Employee $employee, DeviceCommandStatus $status, ?string $error = null): DeviceCommand
{
    return DeviceCommand::create([
        'tenant_id' => $device->tenant_id,
        'device_id' => $device->id,
        'employee_id' => $employee->id,
        'type' => DeviceCommandType::UPSERT_USER,
        'payload' => ['device_user_id' => (string) $employee->badge_number, 'name' => $employee->name],
        'status' => $status,
        'last_error' => $error,
    ]);
}

beforeEach(function () {
    Queue::fake();
});

it('counts, per device, who is waiting, who was sent, who the terminal refused and who was not sent', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);

    $waiting = terminalsPerson($tenant, $branch, '101', 'Waiting');
    $sent = terminalsPerson($tenant, $branch, '102', 'Sent');
    $done = terminalsPerson($tenant, $branch, '103', 'Done');
    $refused = terminalsPerson($tenant, $branch, '104', 'Refused');
    $lettered = terminalsPerson($tenant, $branch, 'A105', 'Lettered');
    // The observer queued each of them on save; set each to the state under test.
    DeviceCommand::query()->where('device_id', $device->id)->where('employee_id', $sent->id)->update(['status' => 'sent']);
    DeviceCommand::query()->where('device_id', $device->id)->where('employee_id', $done->id)->update(['status' => 'done']);
    DeviceCommand::query()->where('device_id', $device->id)->where('employee_id', $refused->id)
        ->update(['status' => 'failed', 'last_error' => 'The device answered with error 4.']);

    $batch = terminalsBatch($tenant, [$waiting, $sent, $done, $refused, $lettered]);

    test()->getJson(terminalsUrl($tenant, $batch))
        ->assertOk()
        ->assertJsonPath('visible', true)
        ->assertJsonCount(1, 'devices')
        ->assertJsonPath('devices.0.device_public_id', $device->public_id)
        ->assertJsonPath('devices.0.device_name', $device->name)
        ->assertJsonPath('devices.0.adapter_type', 'zkteco_adms')
        ->assertJsonPath('devices.0.writable', true)
        ->assertJsonPath('devices.0.receives_only', false)
        ->assertJsonPath('devices.0.queued', 2)
        ->assertJsonPath('devices.0.done', 1)
        ->assertJsonPath('devices.0.failed', 1)
        ->assertJsonPath('devices.0.not_sent', 1)
        ->assertJsonPath('devices.0.last_error', 'The device answered with error 4.')
        ->assertJsonPath('devices.0.enrol_by_hand', [])
        ->assertJsonPath('without_number', 0)
        ->assertJsonPath('without_branch', 0);
});

it('reads each person\'s newest command, not the history', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $person = terminalsPerson($tenant, $branch, '101');
    $device->commands()->delete();

    terminalsCommand($device, $person, DeviceCommandStatus::FAILED, 'The device answered with error 4.');
    terminalsCommand($device, $person, DeviceCommandStatus::DONE);
    $batch = terminalsBatch($tenant, [$person]);

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('devices.0.done', 1)
        ->assertJsonPath('devices.0.failed', 0)
        ->assertJsonPath('devices.0.last_error', null);

    terminalsCommand($device, $person, DeviceCommandStatus::FAILED, 'The device never confirmed it.');

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('devices.0.done', 0)
        ->assertJsonPath('devices.0.failed', 1)
        ->assertJsonPath('devices.0.last_error', 'The device never confirmed it.');
});

it('lists, for a terminal that only sends, who to enrol by hand and with which numbers', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['mode' => 'push']]);
    $abebe = terminalsPerson($tenant, $branch, '1042', 'Abebe Kebede');
    $hanna = terminalsPerson($tenant, $branch, 'H-7', 'Hanna Girma');
    $nobody = terminalsPerson($tenant, $branch, null, 'No Number');
    $batch = terminalsBatch($tenant, [$abebe, $hanna, $nobody]);

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('devices.0.writable', false)
        ->assertJsonPath('devices.0.receives_only', true)
        ->assertJsonPath('devices.0.queued', 0)
        ->assertJsonCount(2, 'devices.0.enrol_by_hand')
        ->assertJsonPath('devices.0.enrol_by_hand.0', ['employee_public_id' => $abebe->public_id, 'name' => 'Abebe Kebede', 'device_user_id' => '1042'])
        ->assertJsonPath('devices.0.enrol_by_hand.1.device_user_id', 'H-7')
        ->assertJsonPath('without_number', 1);
});

it('lists no one to enrol by hand when a writable terminal can be sent them', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    terminalsDevice($tenant, $branch);
    $batch = terminalsBatch($tenant, [terminalsPerson($tenant, $branch, '7')]);

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('devices.0.enrol_by_hand', []);
});

it('counts the people who have no number for the terminals of their branch, and those with no branch', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $bare = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $linked = terminalsPerson($tenant, $branch, null, 'Linked');
    EmployeeExternalIdentity::create([
        'tenant_id' => $tenant->id, 'employee_id' => $linked->id, 'source_type' => 'zkteco_adms',
        'source_ref' => 'device:'.$device->id, 'identifier_type' => 'device_user_id', 'identifier_value' => '17', 'confidence' => 1,
    ]);
    $batch = terminalsBatch($tenant, [
        terminalsPerson($tenant, $branch, null, 'No number'),
        terminalsPerson($tenant, $branch, '  ', 'Blank number'),
        $linked,
        terminalsPerson($tenant, $branch, '5', 'Numbered'),
        terminalsPerson($tenant, null, '6', 'No branch'),
        terminalsPerson($tenant, null, null, 'No branch either'),
        terminalsPerson($tenant, $bare, null, 'Branch without a terminal'),
    ]);

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonCount(1, 'devices')
        ->assertJsonPath('without_number', 2)
        ->assertJsonPath('without_branch', 2);
});

it('says nothing about devices to someone who may not see them', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    terminalsDevice($tenant, $branch);
    $batch = terminalsBatch($tenant, [terminalsPerson($tenant, $branch, '7', 'Secret Name')]);
    test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.viewAny', 'employee.view']));

    $response = test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertExactJson(['visible' => false, 'devices' => [], 'without_number' => 0, 'without_branch' => 0, 'can_assign' => false, 'held' => [], 'held_more' => 0]);

    expect($response->getContent())->not->toContain('Secret Name');
});

it('is closed to someone who may not list employees', function () {
    $tenant = createTenant();
    $batch = terminalsBatch($tenant, []);
    test()->actingAs(createScopedUser($tenant, null, 'all', ['device.viewAny']));

    test()->getJson(terminalsUrl($tenant, $batch))->assertForbidden();
});

it('names and counts only the people the viewer may open', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['mode' => 'push']]);
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = terminalsPerson($tenant, $branch, null, 'Head', ['department_id' => $mine->id]);
    $inside = terminalsPerson($tenant, $branch, '11', 'Inside Person', ['department_id' => $mine->id]);
    $outside = terminalsPerson($tenant, $branch, '12', 'Outside Person', ['department_id' => $theirs->id]);
    $batch = terminalsBatch($tenant, [$inside, $outside]);
    test()->actingAs(createScopedUser($tenant, $head->id, 'department', ['employee.viewAny', 'employee.view', 'device.viewAny']));

    $response = test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('visible', true)
        ->assertJsonCount(1, 'devices.0.enrol_by_hand')
        ->assertJsonPath('devices.0.enrol_by_hand.0.name', 'Inside Person');

    expect($response->getContent())->not->toContain('Outside Person')->not->toContain($outside->public_id)
        ->and($device->id)->toBeInt();
});

it('does not read another organisation\'s batch', function () {
    $other = createTenant();
    $otherBranch = Branch::factory()->create(['tenant_id' => $other->id]);
    $batch = terminalsBatch($other, [terminalsPerson($other, $otherBranch, '1')]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(terminalsUrl($tenant, $batch))->assertNotFound();
});

it('reads a list in a fixed number of queries whatever the number of people or of devices', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    terminalsDevice($tenant, $branch);

    $count = function (int $people) use ($tenant, $branch): int {
        $list = [];
        for ($i = 0; $i < $people; $i++) {
            $list[] = terminalsPerson($tenant, $branch, (string) (1000 + $i + $people * 100 + Employee::query()->count()));
        }
        $batch = terminalsBatch($tenant, $list);
        test()->getJson(terminalsUrl($tenant, $batch))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson(terminalsUrl($tenant, $batch))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $oneDevice = $count(3);
    expect($count(12))->toBe($oneDevice);

    terminalsDevice($tenant, $branch);
    terminalsDevice($tenant, $branch);
    expect($count(12))->toBe($oneDevice);
});

// Punches held for a number the list brought in (2026-11-16). A held badge is
// the number of a person who was not on the list when the punch arrived; once
// the list adds them, the terminals read offers to bring those punches in.

/** @param  int  $count  held punches, one a day */
function terminalsHeld(Tenant $tenant, Device $device, string $badge, int $count = 2): void
{
    foreach (range(1, $count) as $n) {
        UnmatchedDevicePunch::create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'badge' => $badge,
            'punch_type' => 'check_in',
            'occurred_raw' => sprintf('2026-10-%02d 08:00:00', $n),
            'idempotency_key' => "held-{$device->id}-{$badge}-{$n}",
            'status' => UnmatchedDevicePunch::PENDING,
        ]);
    }
}

it('lists a held badge that is now a person of the list, with its punches', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $selam = terminalsPerson($tenant, $branch, '7777', 'Selam Tesfaye');
    terminalsHeld($tenant, $device, '7777', 3);
    $batch = terminalsBatch($tenant, [$selam]);

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('can_assign', true)
        ->assertJsonPath('held_more', 0)
        ->assertJsonCount(1, 'held')
        ->assertJsonPath('held.0', [
            'device_public_id' => $device->public_id,
            'device_name' => $device->name,
            'badge' => '7777',
            'punches' => 3,
            'employee_public_id' => $selam->public_id,
            'employee_name' => 'Selam Tesfaye',
        ]);
});

it('lists a held badge on a terminal of another branch, and compares the number without regard to case', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $elsewhere = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $elsewhere);
    $selam = terminalsPerson($tenant, $branch, 'a105', 'Selam Tesfaye');
    terminalsHeld($tenant, $device, 'A105', 1);

    test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, [$selam])))->assertOk()
        ->assertJsonCount(1, 'held')
        ->assertJsonPath('held.0.badge', 'A105')
        ->assertJsonPath('held.0.employee_public_id', $selam->public_id);
});

it('lists a held number a terminal reported for a person of the list', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $selam = terminalsPerson($tenant, $branch, null, 'Selam Tesfaye');
    EmployeeExternalIdentity::create([
        'tenant_id' => $tenant->id, 'employee_id' => $selam->id, 'source_type' => 'zkteco_adms',
        'source_ref' => 'device:'.$device->id, 'identifier_type' => 'device_user_id', 'identifier_value' => '31', 'confidence' => 1,
    ]);
    terminalsHeld($tenant, $device, '31', 2);

    test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, [$selam])))->assertOk()
        ->assertJsonCount(1, 'held')
        ->assertJsonPath('held.0.punches', 2)
        ->assertJsonPath('held.0.employee_public_id', $selam->public_id);
});

it('does not list a held number two people share', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $selam = terminalsPerson($tenant, $branch, '7777', 'Selam Tesfaye');
    terminalsPerson($tenant, $branch, '7777', 'Dawit Bekele');
    terminalsHeld($tenant, $device, '7777');

    test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, [$selam])))->assertOk()
        ->assertJsonPath('held', [])
        ->assertJsonPath('held_more', 0);
});

it('does not list a held number that resolves to someone who is not on the list', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $selam = terminalsPerson($tenant, $branch, '7777', 'Selam Tesfaye');
    $outside = terminalsPerson($tenant, $branch, null, 'Outside Person');
    // The terminal's own number 7777 was confirmed as the outsider's.
    EmployeeExternalIdentity::create([
        'tenant_id' => $tenant->id, 'employee_id' => $outside->id, 'source_type' => 'zkteco_adms',
        'source_ref' => 'device:'.$device->id, 'identifier_type' => 'device_user_id', 'identifier_value' => '7777',
        'confidence' => 1, 'verified_at' => now(),
    ]);
    terminalsHeld($tenant, $device, '7777');
    // A number only an outsider holds is never looked at.
    terminalsPerson($tenant, $branch, '8888', 'Another Outsider');
    terminalsHeld($tenant, $device, '8888');

    $response = test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, [$selam])))->assertOk()
        ->assertJsonPath('held', []);

    expect($response->getContent())->not->toContain('Outside Person')->not->toContain('Another Outsider');
});

it('lists nothing, and says so, to someone who may not assign a badge', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $selam = terminalsPerson($tenant, $branch, '7777', 'Selam Tesfaye');
    terminalsHeld($tenant, $device, '7777');
    $batch = terminalsBatch($tenant, [$selam]);
    test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.viewAny', 'employee.view', 'device.viewAny']));

    test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('visible', true)
        ->assertJsonPath('can_assign', false)
        ->assertJsonPath('held', [])
        ->assertJsonPath('held_more', 0);
});

it('names no one the viewer may not open', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = terminalsPerson($tenant, $branch, null, 'Head', ['department_id' => $mine->id]);
    $inside = terminalsPerson($tenant, $branch, '11', 'Inside Person', ['department_id' => $mine->id]);
    $outside = terminalsPerson($tenant, $branch, '12', 'Outside Person', ['department_id' => $theirs->id]);
    terminalsHeld($tenant, $device, '11');
    terminalsHeld($tenant, $device, '12');
    $batch = terminalsBatch($tenant, [$inside, $outside]);
    test()->actingAs(createScopedUser($tenant, $head->id, 'department', ['employee.viewAny', 'employee.view', 'device.viewAny', 'attendance.manage']));

    $response = test()->getJson(terminalsUrl($tenant, $batch))->assertOk()
        ->assertJsonPath('can_assign', true)
        ->assertJsonCount(1, 'held')
        ->assertJsonPath('held.0.employee_name', 'Inside Person');

    expect($response->getContent())->not->toContain('Outside Person')->not->toContain($outside->public_id);
});

it('never lists another organisation\'s held punches', function () {
    $other = createTenant();
    $otherBranch = Branch::factory()->create(['tenant_id' => $other->id]);
    $otherDevice = terminalsDevice($other, $otherBranch);
    terminalsHeld($other, $otherDevice, '7777', 4);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $selam = terminalsPerson($tenant, $branch, '7777', 'Selam Tesfaye');

    test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, [$selam])))->assertOk()
        ->assertJsonPath('held', [])
        ->assertJsonPath('held_more', 0);
});

it('does not list punches that were already brought in or dismissed', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $selam = terminalsPerson($tenant, $branch, '7777', 'Selam Tesfaye');
    terminalsHeld($tenant, $device, '7777', 3);
    UnmatchedDevicePunch::query()->where('idempotency_key', 'like', 'held-%-7777-1')->update(['status' => UnmatchedDevicePunch::REPLAYED]);
    UnmatchedDevicePunch::query()->where('idempotency_key', 'like', 'held-%-7777-2')->update(['status' => UnmatchedDevicePunch::DISMISSED]);

    test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, [$selam])))->assertOk()
        ->assertJsonPath('held.0.punches', 1);
});

it('resolves no more held badges than the cap and counts the rest in held_more', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    app()->when(MigrationTerminals::class)->needs('$heldLimit')->give(2);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);
    $people = [];
    foreach (['101', '102', '103', '104', '105'] as $badge) {
        $people[] = terminalsPerson($tenant, $branch, $badge, "Person {$badge}");
        terminalsHeld($tenant, $device, $badge, 1);
    }

    $held = test()->getJson(terminalsUrl($tenant, terminalsBatch($tenant, $people)))->assertOk()
        ->assertJsonCount(2, 'held')
        ->assertJsonPath('held_more', 3)
        ->json('held');

    expect(count(array_unique(array_column($held, 'badge'))))->toBe(2);
});

it('reads the held badges in a bounded number of queries', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = terminalsDevice($tenant, $branch);

    $count = function (int $people) use ($tenant, $branch, $device): int {
        $list = [];
        for ($i = 0; $i < $people; $i++) {
            $badge = (string) (5000 + Employee::query()->count());
            $list[] = terminalsPerson($tenant, $branch, $badge);
            terminalsHeld($tenant, $device, $badge, 1);
        }
        $batch = terminalsBatch($tenant, $list);
        test()->getJson(terminalsUrl($tenant, $batch))->assertOk()->assertJsonCount($people, 'held');

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson(terminalsUrl($tenant, $batch))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(2);
    $many = $count(6);

    // The terminals part is fixed; each held badge costs the resolver's own
    // lookups and nothing else, and no more than that is resolved per cap.
    $perBadge = ($many - $few) / 4;
    expect($perBadge)->toBeLessThanOrEqual(3.0);
    fwrite(STDERR, "\nterminals read queries: 2 held badges = {$few}, 6 held badges = {$many}, per badge = {$perBadge}\n");
});

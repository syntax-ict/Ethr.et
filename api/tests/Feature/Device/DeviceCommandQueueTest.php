<?php

declare(strict_types=1);

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\DeliverDeviceCommandsJob;
use App\Jobs\PullDeviceEventsJob;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Services\Device\DeviceCommandQueue;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Device management used to be one-way (gap 3, 2026-10-14): every new hire was
 * enrolled on every terminal by hand, leavers stayed on them, and clocks were
 * never set. These pin the outbox that replaces that.
 *
 * Queueing tests use ZKTeco devices: ETHR does not call those (they collect
 * their commands over ADMS), so what is queued stays visible as pending.
 */
function commandDevice(Tenant $tenant, Branch $branch, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms',
        'commands_enabled' => true,
        ...$attributes,
    ]);
}

/** @return list<array{type: string, status: string, user: mixed}> */
function commandsOn(Device $device): array
{
    return $device->commands()->orderBy('id')->get()
        ->map(fn (DeviceCommand $c) => ['type' => $c->type->value, 'status' => $c->status->value, 'user' => $c->payload['device_user_id'] ?? null])
        ->all();
}

it('adds a new hire to the devices of their branch that take commands, and only those', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $other = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $off = commandDevice($tenant, $branch, ['commands_enabled' => false]);
    $elsewhere = commandDevice($tenant, $other);

    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1042', 'name' => 'Abebe Kebede', 'status' => EmployeeStatus::HIRED]);

    expect(commandsOn($device))->toBe([['type' => 'upsert_user', 'status' => 'pending', 'user' => '1042']])
        ->and(commandsOn($off))->toBe([])
        ->and(commandsOn($elsewhere))->toBe([]);
});

it('sends nothing for a person with no badge and no linked device id', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);

    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => null, 'status' => EmployeeStatus::HIRED]);

    expect(commandsOn($device))->toBe([]);
});

it('sends a rename once, replacing the command the device has not picked up', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '7', 'status' => EmployeeStatus::HIRED]);

    $employee->update(['name' => 'Almaz Tesfaye']);
    $employee->update(['phone' => '+251911000000']);

    expect(commandsOn($device))->toBe([
        ['type' => 'upsert_user', 'status' => 'cancelled', 'user' => '7'],
        ['type' => 'upsert_user', 'status' => 'pending', 'user' => '7'],
    ])->and($device->commands()->latest('id')->first()->payload['name'])->toBe('Almaz Tesfaye');
});

it('removes a leaver, and a deleted employee, from the devices', function (Closure $leave) {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '55', 'status' => EmployeeStatus::CONFIRMED]);
    $device->commands()->update(['status' => DeviceCommandStatus::DONE->value]);

    $leave($employee);

    expect(commandsOn($device)[1] ?? null)->toBe(['type' => 'delete_user', 'status' => 'pending', 'user' => '55']);
})->with([
    'terminated' => [fn (Employee $e) => $e->update(['status' => EmployeeStatus::TERMINATED])],
    'resigned' => [fn (Employee $e) => $e->update(['status' => EmployeeStatus::RESIGNED])],
    'deleted' => [fn (Employee $e) => $e->delete()],
]);

it('leaves a suspended person on the devices', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '9', 'status' => EmployeeStatus::CONFIRMED]);

    $employee->update(['status' => EmployeeStatus::SUSPENDED]);

    expect(array_column(commandsOn($device), 'type'))->not->toContain('delete_user');
});

it('moves a person between branches: off the old devices, onto the new', function () {
    $tenant = createTenant();
    $from = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $to = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $old = commandDevice($tenant, $from);
    $new = commandDevice($tenant, $to);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $from->id, 'badge_number' => '300', 'status' => EmployeeStatus::CONFIRMED]);

    $employee->update(['branch_id' => $to->id]);

    expect(commandsOn($old))->toBe([
        ['type' => 'upsert_user', 'status' => 'cancelled', 'user' => '300'],
        ['type' => 'delete_user', 'status' => 'pending', 'user' => '300'],
    ])->and(commandsOn($new))->toBe([['type' => 'upsert_user', 'status' => 'pending', 'user' => '300']]);
});

it('uses the id already linked to the device before the badge', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => 'B-1', 'status' => EmployeeStatus::CONFIRMED]);
    EmployeeExternalIdentity::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source_type' => 'zkteco_adms',
        'source_ref' => 'device:'.$device->id,
        'identifier_type' => 'device_user_id',
        'identifier_value' => '17',
        'confidence' => 1,
    ]);

    expect(app(DeviceCommandQueue::class)->deviceUserId($device, $employee))->toBe('17');
});

it('delivers to a device ETHR can call, and gives up after three refusals', function () {
    Queue::fake();
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch, ['adapter_type' => 'mock', 'connection_config' => []]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1', 'status' => EmployeeStatus::HIRED]);
    Queue::assertPushed(DeliverDeviceCommandsJob::class);

    $result = app(DeviceCommandQueue::class)->deliver($device);

    // The upsert, and the daily clock setting a first delivery adds.
    expect($result)->toBe(['done' => 2, 'failed' => 0, 'pending' => 0])
        ->and($device->commands()->where('status', 'done')->pluck('type')->map->value->sort()->values()->all())
        ->toBe(['set_time', 'upsert_user']);

    $device->update(['connection_config' => ['reject_commands' => true]]);
    app(DeviceCommandQueue::class)->setTime($device->fresh());
    $queue = app(DeviceCommandQueue::class);
    $queue->deliver($device->fresh());
    $queue->deliver($device->fresh());
    $last = $queue->deliver($device->fresh());

    $refused = $device->commands()->latest('id')->first();
    expect($last)->toBe(['done' => 0, 'failed' => 1, 'pending' => 0])
        ->and($refused->status)->toBe(DeviceCommandStatus::FAILED)
        ->and($refused->attempts)->toBe(3)
        ->and($refused->last_error)->toContain('refuse');
});

it('speaks ISAPI to a Hikvision terminal', function () {
    Queue::fake();
    config(['devices.allow_private_hosts' => true]);
    Http::fake(['*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'])]);

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['ip' => '192.168.1.64', 'port' => 80, 'username' => 'admin', 'password' => 'x']]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1042', 'name' => 'Abebe Kebede', 'status' => EmployeeStatus::HIRED]);
    $employee->update(['status' => EmployeeStatus::TERMINATED]);
    app(DeviceCommandQueue::class)->setTime($device);

    expect(app(DeviceCommandQueue::class)->deliver($device))->toBe(['done' => 2, 'failed' => 0, 'pending' => 0]);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/ISAPI/AccessControl/UserInfo/Delete')
        && $r->method() === 'PUT'
        && $r['UserInfoDelCond']['EmployeeNoList'][0]['employeeNo'] === '1042');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/ISAPI/System/time')
        && str_contains($r->body(), '<timeZone>CST-3:00:00</timeZone>'));
    // The add was replaced by the removal before the device picked it up.
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'UserInfo/SetUp'));
});

it('sends a Hikvision add with the badge as the employee number', function () {
    Queue::fake();
    config(['devices.allow_private_hosts' => true]);
    Http::fake(['*' => Http::response(['statusCode' => 1])]);
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['ip' => '192.168.1.64', 'port' => 80]]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '88', 'name' => 'Sara Bekele', 'status' => EmployeeStatus::HIRED]);

    app(DeviceCommandQueue::class)->deliver($device);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/ISAPI/AccessControl/UserInfo/SetUp')
        && $r['UserInfo']['employeeNo'] === '88'
        && $r['UserInfo']['name'] === 'Sara Bekele');
});

it('keeps commands pending while the device cannot be reached, and counts a refusal', function () {
    Queue::fake();
    config(['devices.allow_private_hosts' => true]);
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['ip' => '192.168.1.64', 'port' => 80]]);
    $command = app(DeviceCommandQueue::class)->setTime($device);

    Http::fakeSequence()
        ->pushFailedConnection('Connection timed out')
        ->push('<ResponseStatus><subStatusCode>badParameters</subStatusCode></ResponseStatus>', 400);
    app(DeviceCommandQueue::class)->deliver($device);
    expect($command->fresh()->status)->toBe(DeviceCommandStatus::PENDING)
        ->and($command->fresh()->attempts)->toBe(0)
        ->and($command->fresh()->last_error)->toContain('Not reached');

    app(DeviceCommandQueue::class)->deliver($device);
    expect($command->fresh()->attempts)->toBe(1)
        ->and($command->fresh()->last_error)->toContain('HTTP 400');
});

it('delivers waiting commands when a sync finds the device online', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch, ['adapter_type' => 'mock', 'connection_config' => []]);

    PullDeviceEventsJob::dispatchSync($device->id, $device->tenant_id);

    expect($device->commands()->where('type', DeviceCommandType::SET_TIME->value)->value('status'))
        ->toBe(DeviceCommandStatus::DONE);
});

describe('the API', function () {
    it('lists a device\'s commands, newest first', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '5', 'name' => 'Hana', 'status' => EmployeeStatus::HIRED]);
        app(DeviceCommandQueue::class)->setTime($device);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'set_time')
            ->assertJsonPath('data.1.type', 'upsert_user')
            ->assertJsonPath('data.1.employee.name', 'Hana')
            ->assertJsonPath('data.1.device_user_id', '5')
            ->assertJsonMissingPath('data.0.id');
    });

    it('refuses commands until the device is switched to take them', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch, ['commands_enabled' => false]);
        $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands";

        test()->postJson("{$url}/set-time")->assertStatus(409);
        test()->postJson("{$url}/send-staff")->assertStatus(409);

        $device->update(['commands_enabled' => true]);
        test()->postJson("{$url}/set-time")->assertStatus(202)->assertJsonPath('type', 'set_time');
    });

    it('sends the whole branch to a device, counting who has no badge', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1', 'status' => EmployeeStatus::CONFIRMED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => null, 'status' => EmployeeStatus::CONFIRMED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '3', 'status' => EmployeeStatus::TERMINATED]);
        $device = commandDevice($tenant, $branch);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands/send-staff")
            ->assertStatus(202)
            ->assertExactJson(['queued' => 1, 'skipped' => 1]);
    });

    it('enrols one employee on their branch devices from the profile', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '12', 'status' => EmployeeStatus::CONFIRMED]);
        commandDevice($tenant, $branch);
        commandDevice($tenant, $branch);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/enrol-on-devices")
            ->assertStatus(202)
            ->assertExactJson(['devices' => 2]);
    });

    it('sends again the people whose newest command failed, and leaves a number the terminal still refuses unsent', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch);
        $refused = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '21', 'status' => EmployeeStatus::HIRED]);
        $fine = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '22', 'status' => EmployeeStatus::HIRED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => 'A23', 'status' => EmployeeStatus::HIRED]);
        $device->commands()->where('employee_id', $refused->id)->update(['status' => 'failed', 'last_error' => 'The device answered with error 4.']);
        $device->commands()->where('employee_id', $fine->id)->update(['status' => 'done']);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands/retry")
            ->assertStatus(202)
            ->assertExactJson(['queued' => 1, 'skipped' => 1]);

        expect(commandsOn($device))->toBe([
            ['type' => 'upsert_user', 'status' => 'failed', 'user' => '21'],
            ['type' => 'upsert_user', 'status' => 'done', 'user' => '22'],
            ['type' => 'upsert_user', 'status' => 'failed', 'user' => 'A23'],
            ['type' => 'upsert_user', 'status' => 'pending', 'user' => '21'],
        ]);

        // A second request finds nobody whose newest command failed but the lettered number.
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands/retry")
            ->assertStatus(202)
            ->assertExactJson(['queued' => 0, 'skipped' => 1]);
    });

    it('sends again only the people of one list when it names one', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch);
        $inList = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '31', 'status' => EmployeeStatus::HIRED]);
        $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '32', 'status' => EmployeeStatus::HIRED]);
        $device->commands()->update(['status' => 'failed', 'last_error' => 'The device answered with error 4.']);
        $batch = MigrationBatch::create(['tenant_id' => $tenant->id, 'source_type' => 'manual', 'status' => 'committed', 'totals' => []]);
        MigrationStagingRow::create([
            'tenant_id' => $tenant->id, 'batch_id' => $batch->id, 'raw' => ['name' => 'x'], 'display_name' => 'x',
            'resolved_employee_id' => $inList->id, 'action' => 'create', 'processed_at' => now(),
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands/retry", ['batch_public_id' => $batch->public_id])
            ->assertStatus(202)
            ->assertExactJson(['queued' => 1, 'skipped' => 0]);

        expect($device->commands()->where('employee_id', $inList->id)->where('status', 'pending')->count())->toBe(1)
            ->and($device->commands()->where('employee_id', $outside->id)->where('status', 'pending')->count())->toBe(0);
    });

    it('does not put someone back on a device of the branch they have left', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $from = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $to = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $from);
        $mover = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $from->id, 'badge_number' => '41', 'status' => EmployeeStatus::HIRED]);
        $device->commands()->update(['status' => 'failed', 'last_error' => 'The device answered with error 4.']);
        $mover->update(['branch_id' => $to->id]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands/retry")
            ->assertStatus(202)
            ->assertExactJson(['queued' => 0, 'skipped' => 1]);

        expect(commandsOn($device)[1])->toBe(['type' => 'delete_user', 'status' => 'pending', 'user' => '41'])
            ->and($device->commands()->where('type', 'upsert_user')->where('status', 'pending')->exists())->toBeFalse();
    });

    it('refuses a retry on a device that cannot be written to', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $off = commandDevice($tenant, $branch, ['commands_enabled' => false]);
        $sendsOnly = commandDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['mode' => 'push']]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$off->public_id}/commands/retry")->assertStatus(409);
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$sendsOnly->public_id}/commands/retry")->assertStatus(409);
    });

    it('refuses a retry from someone who may not update devices, and for another organisation\'s device or list', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch);
        $other = createTenant();
        $otherBranch = Branch::factory()->create(['tenant_id' => $other->id]);
        $otherDevice = commandDevice($other, $otherBranch);
        $otherBatch = MigrationBatch::create(['tenant_id' => $other->id, 'source_type' => 'manual', 'status' => 'committed', 'totals' => []]);
        $url = fn (Device $d) => "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$d->public_id}/commands/retry";

        test()->actingAs(createScopedUser($tenant, null, 'all', ['device.view', 'device.viewAny']));
        test()->postJson($url($device))->assertForbidden();

        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        test()->postJson($url($otherDevice))->assertNotFound();
        test()->postJson($url($device), ['batch_public_id' => $otherBatch->public_id])->assertUnprocessable();
        test()->postJson($url($device), ['batch_public_id' => 'not-a-batch'])->assertUnprocessable();
    });

    it('is closed to an employee', function () {
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/commands")->assertForbidden();
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/enrol-on-devices")->assertForbidden();
    });

    it('saves the switch on a device', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = commandDevice($tenant, $branch, ['commands_enabled' => false, 'adapter_type' => 'hikvision']);

        test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}", ['commands_enabled' => true])
            ->assertOk()
            ->assertJsonPath('commands_enabled', true)
            ->assertJsonPath('accepts_commands', true);
    });
});

it('does not queue a person number a ZKTeco terminal would refuse, and records why it was not sent', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $zk = commandDevice($tenant, $branch);
    $hik = commandDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['ip' => '203.0.113.10']]);
    Queue::fake();

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => 'A12', 'status' => EmployeeStatus::HIRED]);

    $refused = $zk->commands()->sole();
    expect($refused->type)->toBe(DeviceCommandType::UPSERT_USER)
        ->and($refused->status)->toBe(DeviceCommandStatus::FAILED)
        ->and($refused->attempts)->toBe(0)
        ->and($refused->completed_at)->not->toBeNull()
        ->and($refused->last_error)->toBe('Not sent: this terminal takes digits only as a person number.')
        ->and($zk->commands()->where('status', DeviceCommandStatus::PENDING->value)->exists())->toBeFalse()
        ->and(commandsOn($hik))->toBe([['type' => 'upsert_user', 'status' => 'pending', 'user' => 'A12']]);

    Queue::assertPushed(DeliverDeviceCommandsJob::class, fn (DeliverDeviceCommandsJob $job) => $job->deviceId === $hik->id);
    Queue::assertNotPushed(DeliverDeviceCommandsJob::class, fn (DeliverDeviceCommandsJob $job) => $job->deviceId === $zk->id);

    $employee->update(['badge_number' => '0012']);
    expect(commandsOn($zk)[1])->toBe(['type' => 'upsert_user', 'status' => 'pending', 'user' => '0012']);
});

it('counts a refused number as skipped when sending the whole branch', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch, ['commands_enabled' => false]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => 'A12', 'status' => EmployeeStatus::HIRED]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '12', 'status' => EmployeeStatus::HIRED]);

    expect(app(DeviceCommandQueue::class)->sendStaff($device))->toBe(['queued' => 1, 'skipped' => 1]);
});

it('does not queue anything for a device that cannot be written to, even with commands switched on', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $sendsOnly = commandDevice($tenant, $branch, ['adapter_type' => 'hikvision', 'connection_config' => ['mode' => 'push']]);
    $readOnly = commandDevice($tenant, $branch, ['adapter_type' => 'suprema']);

    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '1042', 'status' => EmployeeStatus::HIRED]);

    expect(commandsOn($sendsOnly))->toBe([])->and(commandsOn($readOnly))->toBe([]);
});

it('sends an id the terminal itself reported, even one its own rule would not let ETHR write', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $linked = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => null, 'status' => EmployeeStatus::CONFIRMED]);
    EmployeeExternalIdentity::create([
        'tenant_id' => $tenant->id, 'employee_id' => $linked->id, 'source_type' => 'zkteco_adms',
        'source_ref' => 'device:'.$device->id, 'identifier_type' => 'device_user_id', 'identifier_value' => 'A_12', 'confidence' => 1,
    ]);
    $device->commands()->delete();

    $linked->update(['name' => 'Almaz Tesfaye']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => 'A12', 'status' => EmployeeStatus::HIRED]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => '0012', 'status' => EmployeeStatus::HIRED]);

    expect(commandsOn($device))->toBe([
        ['type' => 'upsert_user', 'status' => 'pending', 'user' => 'A_12'],
        ['type' => 'upsert_user', 'status' => 'failed', 'user' => 'A12'],
        ['type' => 'upsert_user', 'status' => 'pending', 'user' => '0012'],
    ]);
});

it('records a refusal once, and again only when the refused number or the answer changes', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = commandDevice($tenant, $branch);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'badge_number' => 'A12', 'status' => EmployeeStatus::HIRED]);
    $queue = app(DeviceCommandQueue::class);

    $queue->enrol($employee);
    $queue->sendStaff($device);
    expect(commandsOn($device))->toBe([['type' => 'upsert_user', 'status' => 'failed', 'user' => 'A12']]);

    $employee->update(['badge_number' => 'B34']);
    expect(commandsOn($device))->toBe([
        ['type' => 'upsert_user', 'status' => 'failed', 'user' => 'A12'],
        ['type' => 'upsert_user', 'status' => 'failed', 'user' => 'B34'],
    ]);

    $employee->update(['badge_number' => '34']);
    expect(commandsOn($device)[2])->toBe(['type' => 'upsert_user', 'status' => 'pending', 'user' => '34']);
});

it('clears the commands switch of a device created on a type ETHR cannot write to', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $make = fn (string $type, array $config) => test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Door '.$type,
        'adapter_type' => $type,
        'branch_public_id' => $branch->public_id,
        'serial_number' => 'CQZ72324'.strlen($type).'0002',
        'connection_config' => $config,
        'commands_enabled' => true,
    ]);

    $make('zkteco', ['ip' => '203.0.113.10', 'port' => 4370])->assertCreated()->assertJsonPath('commands_enabled', false);
    $make('zkteco_adms', ['mode' => 'adms'])->assertCreated()->assertJsonPath('commands_enabled', true);
});

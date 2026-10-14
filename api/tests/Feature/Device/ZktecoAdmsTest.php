<?php

declare(strict_types=1);

use App\Enums\DeviceCommandStatus;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Device\AdmsDeviceResolver;
use App\Services\Device\AdmsProtocol;
use App\Services\Device\DeviceManager;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * ZKTeco ADMS (2026-10-14, gap 3). The terminal calls ETHR — which is what
 * makes it usable from a customer LAN to shared hosting — so punches arrive
 * and commands leave on its schedule, authenticated by serial and the
 * device's IP allowlist.
 *
 * Bodies follow the PUSH SDK 2.x text formats; no real terminal has spoken to
 * this yet (field test O1).
 */
const ADMS_SN = 'CQZ7232460001';

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function admsSetup(array $device = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 18:00:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $model = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms',
        'serial_number' => ADMS_SN,
        'connection_config' => ['mode' => 'adms'],
        'webhook_ip_allowlist' => ['127.0.0.1'],
        'commands_enabled' => true,
        'status' => 'pending',
        'last_sync_at' => null,
        ...$device,
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'badge_number' => '1042',
        'name' => 'Abebe Kebede',
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    return [$tenant, $model->fresh(), $employee];
}

function adms(string $method, string $path, string $body = '', array $query = []): TestResponse
{
    $uri = '/iclock/'.$path.'?'.http_build_query(['SN' => ADMS_SN, ...$query]);

    return test()->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

afterEach(fn () => Carbon::setTestNow());

it('answers the start-up request with its options, in the device zone', function () {
    [, $device] = admsSetup();

    $response = adms('GET', 'cdata', '', ['options' => 'all', 'pushver' => '2.4.1']);

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())
        ->toContain('GET OPTION FROM: '.ADMS_SN)
        ->toContain("TimeZone=3\n")
        ->toContain("Realtime=1\n")
        ->and($device->fresh()->status)->toBe('online')
        ->and($device->fresh()->last_sync_at)->not->toBeNull();
});

it('refuses a serial it does not know, an address off the allowlist, and an empty allowlist', function () {
    [, $device] = admsSetup();

    test()->call('GET', '/iclock/cdata?SN=NOPE')->assertStatus(401);

    $device->update(['webhook_ip_allowlist' => ['10.0.0.1']]);
    adms('GET', 'getrequest')->assertStatus(401);
    expect(AdmsDeviceResolver::lastRejection($device))->toMatchArray(['ip' => '127.0.0.1']);

    $device->update(['webhook_ip_allowlist' => []]);
    adms('GET', 'getrequest')->assertStatus(401);
});

it('does not let a REST ZKTeco with the same serial answer for an ADMS one', function () {
    [$tenant, $device] = admsSetup();
    $device->delete();
    Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $device->branch_id,
        'adapter_type' => 'zkteco',
        'serial_number' => ADMS_SN,
        'webhook_ip_allowlist' => ['127.0.0.1'],
    ]);

    adms('GET', 'cdata')->assertStatus(401);
});

it('records pushed punches in the device zone, acknowledges every line, and dedupes a resend', function () {
    [, , $employee] = admsSetup();
    $body = "1042\t2026-10-14 08:01:22\t0\t1\t0\t0\t0\n"
        ."1042\t2026-10-14 17:05:00\t1\t1\t0\t0\t0\n"
        ."9999\t2026-10-14 09:00:00\t0\t1\t0\t0\t0\n";

    adms('POST', 'cdata', $body, ['table' => 'ATTLOG', 'Stamp' => '9999'])->assertOk()->assertSeeText('OK: 3');
    adms('POST', 'cdata', $body, ['table' => 'ATTLOG', 'Stamp' => '9999'])->assertOk()->assertSeeText('OK: 3');

    $records = AttendanceRecord::where('employee_id', $employee->id)->get();
    expect($records)->toHaveCount(1)
        ->and($records->first()->check_in->setTimezone('Africa/Addis_Ababa')->format('H:i:s'))->toBe('08:01:22')
        ->and($records->first()->check_out->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('17:05');
});

it('acknowledges logs it does not store', function () {
    admsSetup();

    adms('POST', 'cdata', "USER PIN=1\tName=A\nOPLOG 4\t0\t2026-10-14 08:00:00\n", ['table' => 'OPERLOG'])
        ->assertOk()->assertSeeText('OK: 2');
});

it('hands the device its waiting commands once, and the clock setting', function () {
    [, $device, $employee] = admsSetup();
    $employee->update(['name' => 'Abebe K. Tadesse']);

    $first = adms('GET', 'getrequest')->assertOk()->getContent();
    $upsert = $device->commands()->where('type', 'upsert_user')->where('status', 'sent')->firstOrFail();
    $clock = $device->commands()->where('type', 'set_time')->firstOrFail();

    expect($first)
        ->toContain("C:{$upsert->id}:DATA UPDATE USERINFO PIN=1042\tName=Abebe K. Tadesse\tPri=0")
        ->toContain("C:{$clock->id}:SET OPTION DateTime=")
        ->and(adms('GET', 'getrequest')->getContent())->toBe('OK');
});

it('marks a command done, or tries again, from what the device reports', function () {
    [, $device, $employee] = admsSetup();
    $employee->update(['status' => EmployeeStatus::TERMINATED]);
    adms('GET', 'getrequest');
    $delete = $device->commands()->where('type', 'delete_user')->firstOrFail();
    $clock = $device->commands()->where('type', 'set_time')->firstOrFail();

    adms('POST', 'devicecmd', "ID={$delete->id}&Return=0&CMD=DATA\nID={$clock->id}&Return=-1&CMD=SET OPTION\n")
        ->assertOk()->assertSeeText('OK');

    expect($delete->fresh()->status)->toBe(DeviceCommandStatus::DONE)
        ->and($clock->fresh()->status)->toBe(DeviceCommandStatus::PENDING)
        ->and($clock->fresh()->last_error)->toContain('-1');
});

it('offers an unconfirmed command again after half an hour, and gives up after three sends', function () {
    [, $device] = admsSetup(['commands_enabled' => true]);
    adms('GET', 'getrequest');
    $clock = $device->commands()->where('type', 'set_time')->firstOrFail();

    foreach ([2, 3] as $attempt) {
        Carbon::setTestNow(now()->addMinutes(31));
        expect(adms('GET', 'getrequest')->getContent())->toContain("C:{$clock->id}:")
            ->and($clock->fresh()->attempts)->toBe($attempt);
    }

    Carbon::setTestNow(now()->addMinutes(31));
    adms('GET', 'getrequest');
    expect($clock->fresh()->status)->toBe(DeviceCommandStatus::FAILED);
});

it('sends nothing while commands are off', function () {
    [, $device] = admsSetup(['commands_enabled' => false]);

    expect(adms('GET', 'getrequest')->getContent())->toBe('OK')
        ->and(DeviceCommand::where('device_id', $device->id)->count())->toBe(0);
});

it('reads the device clock off a punch, and flags a device that runs ahead', function () {
    [, $device, $employee] = admsSetup();

    // 18:05 on the device at 18:00 on the server: five minutes ahead.
    adms('POST', 'cdata', "1042\t2026-10-14 18:05:00\t0\t1\t0\t0\t0\n", ['table' => 'ATTLOG'])->assertOk();

    expect($device->fresh()->clock_offset_seconds)->toBe(300);
    $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
    expect(AttendanceIntegrityFlag::where('attendance_record_id', $record->id)->pluck('type')->all())
        ->toContain('device_clock_ahead');
});

it('shows a device behind without flagging it, and ignores a backlog', function () {
    [, $device, $employee] = admsSetup();

    adms('POST', 'cdata', "1042\t2026-10-14 17:57:00\t0\t1\t0\t0\t0\n", ['table' => 'ATTLOG']);
    expect($device->fresh()->clock_offset_seconds)->toBe(-180)
        ->and(AttendanceIntegrityFlag::where('employee_id', $employee->id)->where('type', 'device_clock_ahead')->exists())->toBeFalse();

    adms('POST', 'cdata', "1042\t2026-10-13 09:00:00\t0\t1\t0\t0\t0\n", ['table' => 'ATTLOG']);
    expect($device->fresh()->clock_offset_seconds)->toBe(-180);
});

it('packs the time the way the firmware reads it', function () {
    expect(AdmsProtocol::encodeTime(Carbon::parse('2026-10-14 08:00:00')))->toBe(860918400);
});

it('is online while it keeps checking in', function () {
    [, $device] = admsSetup();
    adms('GET', 'getrequest');

    $status = app(DeviceManager::class)->adapter($device->fresh())->getStatus($device->fresh());
    expect($status['online'])->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(11));
    expect(app(DeviceManager::class)->adapter($device->fresh())->getStatus($device->fresh())['online'])->toBeFalse();
});

describe('registering one', function () {
    it('takes an address range for the allowlist, but not one wide enough to admit anyone', function () {
        [$tenant, $device] = admsSetup();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}";

        test()->putJson($url, ['webhook_ip_allowlist' => ['196.188.0.0/16', '10.0.0.7']])->assertOk();
        test()->putJson($url, ['webhook_ip_allowlist' => ['0.0.0.0/0']])->assertUnprocessable();
        test()->putJson($url, ['webhook_ip_allowlist' => ['10.0.0.0/7']])->assertUnprocessable();
    });

    it('needs a serial and no address, and tells the admin where to point it', function () {
        [$tenant, $device] = admsSetup();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices";

        test()->postJson($url, [
            'name' => 'Gate',
            'adapter_type' => 'zkteco_adms',
            'branch_public_id' => $device->branch->public_id,
            'connection_config' => ['mode' => 'adms'],
        ])->assertUnprocessable()->assertJsonValidationErrors('serial_number');

        test()->postJson($url, [
            'name' => 'Gate',
            'adapter_type' => 'zkteco_adms',
            'serial_number' => 'CQZ7232460002',
            'branch_public_id' => $device->branch->public_id,
            'connection_config' => ['mode' => 'adms'],
            'webhook_ip_allowlist' => ['196.188.0.0/16'],
        ])->assertCreated()
            ->assertJsonPath('adms.server', parse_url((string) config('app.url'), PHP_URL_HOST))
            ->assertJsonPath('accepts_commands', true);
    });
});

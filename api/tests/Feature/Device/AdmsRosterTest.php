<?php

declare(strict_types=1);

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollmentSnapshot;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\AdmsProtocol;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

/**
 * Who a ZKTeco ADMS terminal says is enrolled on it (EMPLOYEE-INVITATIONS-PLAN,
 * PR D). ETHR cannot call the device, so it asks with a command and the answer
 * arrives as upload lines at the next check-in.
 *
 * No terminal has sent these yet. The bodies below follow the two published
 * generations of the protocol, PUSH 2.x (`USER PIN=` lines in `cdata`) and
 * PUSH 3.x (`user pin=` lines in `querydata`), including the variations open
 * implementations report: tabs or spaces, bracketed and padded cards, blank
 * names, firmware that resends a finger.
 */
const ROSTER_SN = 'CQZ7232460777';

/** @return array{0: Tenant, 1: Device} */
function rosterSetup(array $device = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $model = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms',
        'serial_number' => ROSTER_SN,
        'connection_config' => ['mode' => 'adms'],
        'webhook_ip_allowlist' => ['127.0.0.1'],
        'commands_enabled' => false,
        'status' => 'online',
        ...$device,
    ]);

    return [$tenant, $model->fresh()];
}

function iclock(string $method, string $path, string $body = '', array $query = [], string $sn = ROSTER_SN): TestResponse
{
    $uri = '/iclock/'.$path.'?'.http_build_query(['SN' => $sn, ...$query]);

    return test()->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

function rosterApi(Tenant $tenant, Device $device, string $method = 'GET', string $suffix = ''): TestResponse
{
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/enrollments{$suffix}";

    return $method === 'GET' ? test()->getJson($url) : test()->postJson($url);
}

/** @return Collection<int, DeviceEnrollmentSnapshot> */
function snapshots(Device $device)
{
    return DeviceEnrollmentSnapshot::withoutGlobalScopes()
        ->where('device_id', $device->id)
        ->orderBy('device_user_id')
        ->get();
}

afterEach(fn () => Carbon::setTestNow());

// ── Reading the lines ──

it('reads PUSH 2.x user lines, and keeps neither the password nor a template', function () {
    $body = "USER PIN=1042\tName=Abebe Kebede\tPri=0\tPasswd=4471\tCard=[0000123456]\tGrp=1\tTZ=0000000100000000\tVerify=0\n"
        ."USER PIN=7\tName=ሰላም ታደሰ\tPri=14\tPasswd=\tCard=[0000000000]\tGrp=1\n"
        ."USER PIN=8\tName=\tPri=0\tPasswd=\tCard=\n"
        ."FP PIN=1042\tFID=6\tSize=1184\tValid=1\tTMP=SgdTUzIxAAADnJ4ECAUHCc7QAAAbnWkBAAAAg\n"
        ."FP PIN=1042\tFID=7\tSize=1100\tValid=1\tTMP=SgdTUzIxAAADnJ4ECAUHCc7QAAAbnWkBAAAAh\n"
        ."FP PIN=1042\tFID=6\tSize=1184\tValid=1\tTMP=SgdTUzIxAAADnJ4ECAUHCc7QAAAbnWkBAAAAg\n"
        ."FACE PIN=7\tFID=0\tSize=2048\tValid=1\tTMP=AAECAwQFBgcICQ==\n";

    $people = AdmsProtocol::userLines($body);

    expect($people['1042'])->toMatchArray(['user' => true, 'name' => 'Abebe Kebede', 'card' => '123456', 'is_admin' => false, 'fingerprints' => 2, 'face' => false])
        ->and($people['7'])->toMatchArray(['name' => 'ሰላም ታደሰ', 'card' => null, 'is_admin' => true, 'face' => true])
        ->and($people['8'])->toMatchArray(['name' => null, 'card' => null])
        ->and(json_encode($people))->not->toContain('4471')
        ->not->toContain('SgdTUz')
        ->not->toContain('AAECAw');
});

it('reads firmware that separates fields with spaces, keeping a name with a space whole', function () {
    $people = AdmsProtocol::userLines("USER PIN=31 Name=Hanna Girma Wolde Pri=6 Passwd=99 Card=[0012003400]\n");

    expect($people['31'])->toMatchArray(['name' => 'Hanna Girma Wolde', 'card' => '12003400', 'is_admin' => true]);
});

it('reads PUSH 3.x user and template lines, in lower case', function () {
    $body = "user uid=1\tcardno=88121\tpin=200\tpassword=1234\tgroup=1\tstarttime=0\tendtime=0\tname=Meron Alemu\tprivilege=0\tdisable=0\tverify=0\n"
        ."templatev10 size=1184\tuid=1\tpin=200\tfingerid=3\tvalid=1\ttemplate=TUzIxAAADnJ4E\n"
        ."biodata pin=200\tno=0\tindex=0\tvalid=1\tduress=0\ttype=9\tmajorver=5\ttmp=QkFTRTY0\n";

    $people = AdmsProtocol::userLines($body);

    expect($people['200'])->toMatchArray(['name' => 'Meron Alemu', 'card' => '88121', 'fingerprints' => 1, 'face' => true])
        ->and(json_encode($people))->not->toContain('1234')->not->toContain('TUzIx');
});

it('skips lines it cannot read, and a name that is not valid UTF-8 is cleaned rather than refused', function () {
    $people = AdmsProtocol::userLines(
        "OPLOG 4\t0\t2026-10-14 08:00:00\t0\t0\t0\t0\n"
        ."USER Name=No PIN here\n"
        ."USER PIN=../etc\tName=Bad pin\n"
        ."USER PIN=55\tName=Ab\xC3\x28ebe\n"
        ."\n"
    );

    expect(array_column($people, 'pin'))->toBe(['55'])
        ->and(mb_check_encoding((string) $people['55']['name'], 'UTF-8'))->toBeTrue();
});

// ── Arriving at ETHR ──

it('stores people from an OPERLOG upload and acknowledges every line', function () {
    [, $device] = rosterSetup();

    iclock('POST', 'cdata', "USER PIN=1042\tName=Abebe Kebede\tPri=0\tPasswd=4471\tCard=[0000123456]\n"
        ."FP PIN=1042\tFID=6\tSize=1184\tValid=1\tTMP=SgdTUzIx\n"
        ."OPLOG 4\t0\t2026-10-28 08:00:00\t0\t0\t0\t0\n", ['table' => 'OPERLOG', 'Stamp' => '9999'])
        ->assertOk()->assertSeeText('OK: 3');

    $rows = snapshots($device);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->makeVisible('tenant_id')->toArray())->toMatchArray([
            'tenant_id' => $device->tenant_id,
            'device_user_id' => '1042',
            'name' => 'Abebe Kebede',
            'card_number' => '123456',
            'fingerprint_count' => 1,
        ])
        ->and(json_encode($rows->first()->getAttributes()))->not->toContain('4471')->not->toContain('SgdTUz');
});

it('stores people from a USERINFO upload, and a resend updates rather than duplicates', function () {
    [, $device] = rosterSetup();

    iclock('POST', 'cdata', "USER PIN=7\tName=Old Name\tPri=0\n", ['table' => 'USERINFO'])->assertOk();
    iclock('POST', 'cdata', "USER PIN=7\tName=New Name\tPri=14\n", ['table' => 'USERINFO'])->assertOk();

    expect(snapshots($device))->toHaveCount(1)
        ->and(snapshots($device)->first()->name)->toBe('New Name')
        ->and(snapshots($device)->first()->is_admin)->toBeTrue();
});

it('keeps a known count when an upload carries no template lines, and invents no one from templates alone', function () {
    [, $device] = rosterSetup();

    iclock('POST', 'cdata', "USER PIN=7\tName=A\nFP PIN=7\tFID=1\tTMP=x\nFP PIN=7\tFID=2\tTMP=y\n", ['table' => 'OPERLOG'])->assertOk();
    iclock('POST', 'cdata', "USER PIN=7\tName=A\n", ['table' => 'OPERLOG'])->assertOk();
    iclock('POST', 'cdata', "FP PIN=99\tFID=1\tTMP=z\n", ['table' => 'OPERLOG'])->assertOk()->assertSeeText('OK: 1');

    expect(snapshots($device)->pluck('fingerprint_count', 'device_user_id')->all())->toBe(['7' => 2]);
});

it('takes a PUSH 3.x answer at querydata and answers with the count received', function () {
    [, $device] = rosterSetup();

    iclock('POST', 'querydata', "user uid=1\tpin=200\tname=Meron Alemu\tprivilege=0\tpassword=1234\tcardno=88121\n"
        ."user uid=2\tpin=201\tname=Dawit Bekele\tprivilege=0\tpassword=\tcardno=\n",
        ['type' => 'tabledata', 'cmdid' => '12', 'tablename' => 'user', 'count' => '2', 'packcnt' => '1', 'packidx' => '1'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('user=2');

    expect(snapshots($device)->pluck('name', 'device_user_id')->all())->toBe(['200' => 'Meron Alemu', '201' => 'Dawit Bekele']);
});

it('refuses an unknown serial at querydata', function () {
    rosterSetup();

    iclock('POST', 'querydata', "user pin=1\tname=X\n", ['tablename' => 'user'], 'NOPE')->assertStatus(401);

    expect(DeviceEnrollmentSnapshot::withoutGlobalScopes()->count())->toBe(0);
});

it('stores nothing from a photo upload', function () {
    [, $device] = rosterSetup();

    iclock('POST', 'cdata', "USER PIN=7\tName=A\n", ['table' => 'ATTPHOTO'])->assertOk();

    expect(snapshots($device))->toHaveCount(0);
});

it('files each device\'s people under its own organisation', function () {
    [$tenantA, $deviceA] = rosterSetup();
    $tenantB = createTenant();
    $deviceB = Device::factory()->create([
        'tenant_id' => $tenantB->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenantB->id])->id,
        'adapter_type' => 'zkteco_adms',
        'serial_number' => 'OTHER-SN-1',
        'connection_config' => ['mode' => 'adms'],
        'webhook_ip_allowlist' => ['127.0.0.1'],
    ]);

    iclock('POST', 'cdata', "USER PIN=1\tName=Belongs to B\n", ['table' => 'OPERLOG'], 'OTHER-SN-1')->assertOk();
    iclock('POST', 'cdata', "USER PIN=1\tName=Belongs to A\n", ['table' => 'OPERLOG'])->assertOk();

    expect(snapshots($deviceA)->pluck('tenant_id', 'name')->all())->toBe(['Belongs to A' => $tenantA->id])
        ->and(snapshots($deviceB)->pluck('tenant_id', 'name')->all())->toBe(['Belongs to B' => $tenantB->id]);

    app(CurrentTenant::class)->set($tenantA);
    rosterApi($tenantA, $deviceA)->assertOk()
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('enrollments.0.name', 'Belongs to A');
    rosterApi($tenantA, $deviceB)->assertNotFound();
});

// ── Asking, and the answer ──

it('asks the device for its list without commands enabled, once however often it is asked', function () {
    [$tenant, $device] = rosterSetup(['commands_enabled' => false]);

    rosterApi($tenant, $device, 'POST', '/request')->assertStatus(202)
        ->assertJsonPath('roster.source', 'reported')
        ->assertJsonPath('roster.requested_at', fn ($at) => $at !== null);
    rosterApi($tenant, $device, 'POST', '/request')->assertStatus(202);

    $queries = $device->commands()->where('type', DeviceCommandType::QUERY_USERS->value)->get();
    expect($queries)->toHaveCount(1);

    // A person waiting to be added is not sent while commands are off.
    $device->commands()->create([
        'tenant_id' => $device->tenant_id, 'type' => DeviceCommandType::UPSERT_USER,
        'payload' => ['device_user_id' => '9', 'name' => 'X'], 'status' => DeviceCommandStatus::PENDING,
    ]);

    $answer = iclock('GET', 'getrequest')->assertOk()->getContent();
    expect($answer)->toBe("C:{$queries->first()->id}:DATA QUERY USERINFO\n");
});

it('shows the reported list with matches, when it arrived, and whether a request is waiting', function () {
    [$tenant, $device] = rosterSetup();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $device->branch_id,
        'badge_number' => '1042', 'name' => 'Abebe Kebede', 'status' => EmployeeStatus::CONFIRMED,
    ]);

    rosterApi($tenant, $device)->assertOk()
        ->assertJsonPath('summary.total', 0)
        ->assertJsonPath('roster.source', 'reported')
        ->assertJsonPath('roster.received_at', null)
        ->assertJsonPath('roster.can_request', true);

    iclock('POST', 'cdata', "USER PIN=1042\tName=Abebe Kebede\tPri=0\n", ['table' => 'OPERLOG'])->assertOk();

    rosterApi($tenant, $device)->assertOk()
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('enrollments.0.device_user_id', '1042')
        ->assertJsonPath('enrollments.0.match.employee_public_id', $employee->public_id)
        ->assertJsonPath('roster.received_at', now()->toIso8601String())
        ->assertJsonPath('roster.requested_at', null);
});

it('drops people the device no longer mentions once it confirms the answer', function () {
    [$tenant, $device] = rosterSetup();
    iclock('POST', 'cdata', "USER PIN=1\tName=Stays\nUSER PIN=2\tName=Removed at the terminal\n", ['table' => 'OPERLOG']);

    Carbon::setTestNow(now()->addHour());
    rosterApi($tenant, $device, 'POST', '/request')->assertStatus(202);
    $query = $device->commands()->where('type', 'query_users')->firstOrFail();
    iclock('GET', 'getrequest');

    Carbon::setTestNow(now()->addSeconds(20));
    iclock('POST', 'cdata', "USER PIN=1\tName=Stays\n", ['table' => 'OPERLOG']);
    iclock('POST', 'devicecmd', "ID={$query->id}&Return=0&CMD=DATA\n")->assertOk();

    expect(snapshots($device)->pluck('name')->all())->toBe(['Stays'])
        ->and($query->fresh()->status)->toBe(DeviceCommandStatus::DONE);
});

it('keeps the list when a confirmation arrives before any of the answer', function () {
    [$tenant, $device] = rosterSetup();
    iclock('POST', 'cdata', "USER PIN=1\tName=A\nUSER PIN=2\tName=B\n", ['table' => 'OPERLOG']);

    Carbon::setTestNow(now()->addHour());
    rosterApi($tenant, $device, 'POST', '/request');
    $query = $device->commands()->where('type', 'query_users')->firstOrFail();
    iclock('GET', 'getrequest');
    iclock('POST', 'devicecmd', "ID={$query->id}&Return=0&CMD=DATA\n");

    expect(snapshots($device))->toHaveCount(2);
});

it('treats a PUSH 3.x record count as success', function () {
    [$tenant, $device] = rosterSetup();

    rosterApi($tenant, $device, 'POST', '/request');
    $query = $device->commands()->where('type', 'query_users')->firstOrFail();
    iclock('GET', 'getrequest');
    iclock('POST', 'devicecmd', "ID={$query->id}&Return=2&CMD=DATA\n");

    expect($query->fresh()->status)->toBe(DeviceCommandStatus::DONE);
});

it('asks once more in the PUSH 3.x form when the device refuses the 2.x one, and then stops', function () {
    [$tenant, $device] = rosterSetup();

    rosterApi($tenant, $device, 'POST', '/request');
    $v2 = $device->commands()->where('type', 'query_users')->firstOrFail();
    iclock('GET', 'getrequest');
    iclock('POST', 'devicecmd', "ID={$v2->id}&Return=-1002&CMD=DATA\n");

    expect($v2->fresh()->status)->toBe(DeviceCommandStatus::FAILED);
    $v3 = $device->commands()->where('type', 'query_users')->where('id', '>', $v2->id)->firstOrFail();
    expect(iclock('GET', 'getrequest')->getContent())
        ->toBe("C:{$v3->id}:DATA QUERY tablename=user,fielddesc=*,filter=*\n");

    iclock('POST', 'devicecmd', "ID={$v3->id}&Return=-1&CMD=DATA\n");

    expect($v3->fresh()->status)->toBe(DeviceCommandStatus::FAILED)
        ->and($device->commands()->where('type', 'query_users')->count())->toBe(2);
});

it('asks at start-up when it holds no list, and not again the same day', function () {
    [, $device] = rosterSetup();

    iclock('GET', 'cdata', '', ['options' => 'all', 'pushver' => '2.4.1'])->assertOk()
        ->assertSeeText('TransFlag=TransData AttLog OpLog EnrollUser ChgUser');
    $first = $device->commands()->where('type', 'query_users')->firstOrFail();
    $first->update(['status' => DeviceCommandStatus::FAILED]);

    iclock('GET', 'cdata', '', ['options' => 'all']);
    expect($device->commands()->where('type', 'query_users')->count())->toBe(1);

    Carbon::setTestNow(now()->addHours(25));
    iclock('GET', 'cdata', '', ['options' => 'all']);
    expect($device->commands()->where('type', 'query_users')->count())->toBe(2);
});

it('does not ask at start-up when it already holds a list', function () {
    [, $device] = rosterSetup();
    iclock('POST', 'cdata', "USER PIN=1\tName=A\n", ['table' => 'OPERLOG']);

    iclock('GET', 'cdata', '', ['options' => 'all']);

    expect($device->commands()->where('type', 'query_users')->exists())->toBeFalse();
});

it('keeps the list in step with the people ETHR adds and removes', function () {
    [, $device] = rosterSetup(['commands_enabled' => true]);
    iclock('POST', 'cdata', "USER PIN=5\tName=Leaving\n", ['table' => 'OPERLOG']);

    $add = $device->commands()->create([
        'tenant_id' => $device->tenant_id, 'type' => DeviceCommandType::UPSERT_USER,
        'payload' => ['device_user_id' => '6', 'name' => 'Joining'], 'status' => DeviceCommandStatus::PENDING,
    ]);
    $remove = $device->commands()->create([
        'tenant_id' => $device->tenant_id, 'type' => DeviceCommandType::DELETE_USER,
        'payload' => ['device_user_id' => '5'], 'status' => DeviceCommandStatus::PENDING,
    ]);
    iclock('GET', 'getrequest');
    iclock('POST', 'devicecmd', "ID={$add->id}&Return=0&CMD=DATA\nID={$remove->id}&Return=0&CMD=DATA\n");

    expect(snapshots($device)->pluck('name', 'device_user_id')->all())->toBe(['6' => 'Joining']);
});

it('refuses a request for a device it reads directly, and needs device.update', function () {
    [$tenant, $device] = rosterSetup();
    $direct = Device::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $device->branch_id, 'adapter_type' => 'mock', 'status' => 'online',
    ]);

    rosterApi($tenant, $direct, 'POST', '/request')->assertStatus(409);
    rosterApi($tenant, $direct)->assertOk()->assertJsonPath('roster.source', 'live');
    expect(DeviceCommand::withoutGlobalScopes()->where('device_id', $direct->id)->exists())->toBeFalse();

    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    rosterApi($tenant, $device, 'POST', '/request')->assertForbidden();
});

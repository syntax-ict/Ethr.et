<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Jobs\ProcessDeviceInboundPunchesJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Every push path goes through the inbox: ZKTeco and Suprema JSON webhooks and
 * ADMS ATTLOG uploads store the punches, queue the work and answer. The engine
 * is not called in the request.
 */

afterEach(fn () => Carbon::setTestNow());

const PUSH_SN = 'PUSH-SN-1';

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function pushSetup(string $adapter): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 18:00:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => $adapter,
        'serial_number' => PUSH_SN,
        'connection_config' => $adapter === 'zkteco_adms' ? ['mode' => 'adms'] : [],
        'webhook_token' => $adapter === 'zkteco_adms' ? null : 'push-token',
        'webhook_ip_allowlist' => ['127.0.0.1'],
        'status' => 'pending',
        'last_sync_at' => null,
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'badge_number' => '1042', 'status' => EmployeeStatus::CONFIRMED,
    ]);

    return [$tenant, $device, $employee];
}

/** @param array<string, mixed> $body */
function pushJson(Tenant $tenant, string $vendor, array $body): TestResponse
{
    return test()->withHeader('X-Webhook-Token', 'push-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/{$vendor}", $body);
}

function pushAdms(string $body): TestResponse
{
    return test()->call('POST', '/iclock/cdata?'.http_build_query(['SN' => PUSH_SN, 'table' => 'ATTLOG']), [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

it('puts a ZKTeco punch in the inbox once, however often it is sent', function () {
    Queue::fake();
    [$tenant] = pushSetup('zkteco');
    $body = ['sn' => PUSH_SN, 'records' => [['pin' => '1042', 'timestamp' => '2026-10-14T08:00:00+03:00', 'punch' => 0]]];

    pushJson($tenant, 'zkteco', $body)->assertOk()->assertJsonPath('status', 'processed')->assertJsonPath('count', 1);
    pushJson($tenant, 'zkteco', $body)->assertOk()->assertJsonPath('count', 1);

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->idempotency_key)->toStartWith('webhook:zk:')
        ->and($row->raw)->toContain('1042')
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertPushed(ProcessDeviceInboundPunchesJob::class, 1);
});

it('puts a Suprema punch in the inbox once, however often it is sent', function () {
    Queue::fake();
    [$tenant] = pushSetup('suprema');
    $body = ['events' => [['user_id' => '1042', 'datetime' => '2026-10-14T08:00:00+03:00', 'event_type_id' => 0x1000]]];

    pushJson($tenant, 'suprema', $body)->assertOk()->assertJsonPath('count', 1);
    pushJson($tenant, 'suprema', $body)->assertOk();

    expect(DeviceInboundPunch::withoutGlobalScopes()->sole()->idempotency_key)->toStartWith('webhook:sup:');
});

it('records a pushed ZKTeco punch when the queue runs', function () {
    [$tenant, , $employee] = pushSetup('zkteco');

    pushJson($tenant, 'zkteco', ['sn' => PUSH_SN, 'records' => [['pin' => '1042', 'timestamp' => '2026-10-14T08:00:00+03:00', 'punch' => 0]]])->assertOk();

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('puts an ADMS punch in the inbox once and answers OK', function () {
    Queue::fake();
    pushSetup('zkteco_adms');
    $line = "1042\t2026-10-14 08:00:00\t0\t1\t0\t0\n";

    pushAdms($line)->assertOk()->assertSee('OK: 1');
    pushAdms($line)->assertOk()->assertSee('OK: 1');

    expect(DeviceInboundPunch::withoutGlobalScopes()->sole()->idempotency_key)->toStartWith('webhook:adms:');
    Queue::assertPushed(ProcessDeviceInboundPunchesJob::class, 1);
});

it('acknowledges two thousand ADMS lines with two thousand rows and no engine call', function () {
    Queue::fake();
    pushSetup('zkteco_adms');

    $lines = [];
    for ($i = 0; $i < 2000; $i++) {
        $lines[] = '1042'."\t".Carbon::parse('2026-10-14 00:00:00')->addSeconds($i * 20)->format('Y-m-d H:i:s')."\t0\t1\t0\t0";
    }

    pushAdms(implode("\n", $lines))->assertOk()->assertSee('OK: 2000');

    expect(DeviceInboundPunch::withoutGlobalScopes()->count())->toBe(2000)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0)
        ->and(DeviceInboundPunch::withoutGlobalScopes()->whereNull('processed_at')->count())->toBe(2000);
});

it('keeps an unreadable ADMS line as a quarantined row with the line', function () {
    Queue::fake();
    pushSetup('zkteco_adms');

    pushAdms("garbage without tabs\n1042\t2026-10-14 08:00:00\t0\n")->assertOk()->assertSee('OK: 2');

    $bad = DeviceInboundPunch::withoutGlobalScopes()->whereNotNull('failed_at')->sole();
    expect($bad->failure)->toBe('error')
        ->and($bad->raw)->toBe('garbage without tabs');
});

it('answers /iclock through the background-work middleware', function () {
    $route = app('router')->getRoutes()->match(Illuminate\Http\Request::create('/iclock/cdata', 'POST'));

    expect($route->gatherMiddleware())->toContain(App\Http\Middleware\RunBackgroundWorkAfterResponse::class);
});

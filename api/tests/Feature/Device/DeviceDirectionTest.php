<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Device\GenericHttpAdapter;
use App\Services\Device\HikvisionAdapter;
use App\Services\Device\ZktecoAdapter;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Whether a device punch is an arrival or a departure (2026-10-14).
 *
 * The Hikvision webhook recorded every event as a check-in, so an evening
 * scan opened a second record and a conflict instead of closing the day.
 * Direction now comes from the terminal's `attendanceStatus`, and a device
 * that states none is `auto`: it closes the day's open record or opens one.
 */
function dirTenant(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 17:30:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'serial_number' => 'HIK-DIR-1', 'webhook_token' => 'hik-dir-token',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => '1001']);

    return [$tenant, $device, $employee];
}

function hikPush(Tenant $tenant, array $body, string $token = 'hik-dir-token'): TestResponse
{
    return test()->withHeader('X-Webhook-Token', $token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", $body);
}

/** The notification shape Hikvision terminals send: event inside, time on the envelope. */
function hikEvent(string $badge, string $localTime, string $status): array
{
    return [
        'dateTime' => "2026-10-14T{$localTime}+03:00",
        'eventType' => 'AccessControllerEvent',
        'AccessControllerEvent' => [
            'majorEventType' => 5, 'subEventType' => 75,
            'employeeNoString' => $badge,
            'attendanceStatus' => $status,
        ],
    ];
}

afterEach(fn () => Carbon::setTestNow());

describe('Hikvision push', function () {
    it('closes the morning record with the evening check-out instead of opening a second one', function () {
        [$tenant, , $employee] = dirTenant();

        hikPush($tenant, hikEvent('1001', '08:02:00', 'checkIn'))->assertOk()->assertJsonPath('status', 'processed');
        hikPush($tenant, hikEvent('1001', '17:05:00', 'checkOut'))->assertOk()->assertJsonPath('status', 'processed');

        $records = AttendanceRecord::where('employee_id', $employee->id)->get();
        expect($records)->toHaveCount(1);
        expect($records->first()->check_out)->not->toBeNull();
        expect(AttendanceConflict::count())->toBe(0);
    });

    it('lets a terminal in access mode work the day out by itself', function () {
        [$tenant, , $employee] = dirTenant();

        hikPush($tenant, hikEvent('1001', '08:00:00', 'undefined'))->assertOk();
        hikPush($tenant, hikEvent('1001', '08:01:30', 'undefined'))->assertOk();
        hikPush($tenant, hikEvent('1001', '17:00:00', 'undefined'))->assertOk();

        $records = AttendanceRecord::where('employee_id', $employee->id)->get();
        expect($records)->toHaveCount(1);
        expect($records->first()->check_in->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('08:00');
        expect($records->first()->check_out->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('17:00');
    });

    it('reads the multipart form most firmware sends, with the time from the envelope', function () {
        [$tenant, , $employee] = dirTenant();

        test()->withHeader('X-Webhook-Token', 'hik-dir-token')
            ->post("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
                'event_log' => json_encode(hikEvent('1001', '07:55:00', 'checkIn')),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        expect($record->check_in->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('07:55');
    });

    it('acknowledges heartbeats and does not record a punch for an unregistered card', function () {
        [$tenant] = dirTenant();

        hikPush($tenant, ['dateTime' => '2026-10-14T09:00:00+03:00', 'eventType' => 'heartBeat'])
            ->assertOk()->assertJsonPath('status', 'ignored');

        $unregistered = hikEvent('', '09:00:00', 'checkIn');
        $unregistered['AccessControllerEvent']['cardNo'] = '1001';
        unset($unregistered['AccessControllerEvent']['employeeNoString']);
        hikPush($tenant, $unregistered)->assertOk()->assertJsonPath('status', 'no_badge');

        expect(AttendanceRecord::count())->toBe(0);
    });

    it('resolves a device user id mapped earlier, as the pull path does', function () {
        [$tenant, $device] = dirTenant();
        $mapped = Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => 'B-9', 'employee_code' => 'E-9']);

        app(IdentityResolver::class)->link($mapped, new IdentitySignals(
            sourceType: 'hikvision',
            sourceRef: 'device:'.$device->id,
            identifierType: 'device_user_id',
            identifierValue: '77',
        ), 1.0, verified: true);

        hikPush($tenant, hikEvent('77', '08:10:00', 'checkIn'))->assertOk()->assertJsonPath('status', 'processed');

        expect(AttendanceRecord::where('employee_id', $mapped->id)->count())->toBe(1);
    });
});

describe('ZKTeco push', function () {
    it('closes the day when the terminal sends no punch state', function () {
        [$tenant, $hik, $employee] = dirTenant();
        Device::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $hik->branch_id, 'adapter_type' => 'zkteco',
            'serial_number' => 'ZK-DIR-1', 'webhook_token' => 'zk-dir-token',
        ]);

        test()->withHeader('X-Webhook-Token', 'zk-dir-token')
            ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
                'records' => [
                    ['pin' => '1001', 'timestamp' => '2026-10-14 08:00:00'],
                    ['pin' => '1001', 'timestamp' => '2026-10-14 17:00:00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('count', 2);

        $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
        expect($record->check_out)->not->toBeNull();
    });

    it('maps the six ZKTeco punch states and leaves anything else to the engine', function (mixed $state, string $direction) {
        expect(ZktecoAdapter::directionFor($state))->toBe($direction);
    })->with([
        [0, 'check_in'], [1, 'check_out'], [2, 'check_out'], [3, 'check_in'], [4, 'check_in'], [5, 'check_out'],
        [255, 'auto'], [null, 'auto'], ['', 'auto'],
    ]);
});

describe('pulling from devices', function () {
    it('pages a Hikvision search and reads every authentication type, not only face', function () {
        $device = Device::factory()->make(['connection_config' => ['ip' => '10.0.0.5', 'port' => 80, 'username' => 'admin', 'password' => 'x']]);
        config(['devices.allow_private_hosts' => true]);

        Http::fakeSequence('10.0.0.5/ISAPI/AccessControl/AcsEvent*')
            ->push(['AcsEvent' => ['responseStatusStrg' => 'MORE', 'InfoList' => [
                ['major' => 5, 'minor' => 1, 'employeeNoString' => '7', 'time' => '2026-10-14T08:00:00+03:00', 'attendanceStatus' => 'checkIn'],
                ['major' => 5, 'minor' => 9, 'cardNo' => '99999', 'time' => '2026-10-14T08:01:00+03:00'],
            ]]])
            ->push(['AcsEvent' => ['responseStatusStrg' => 'OK', 'InfoList' => [
                ['major' => 5, 'minor' => 38, 'employeeNoString' => '7', 'time' => '2026-10-14T17:00:00+03:00', 'attendanceStatus' => 'undefined'],
            ]]]);

        $events = app(HikvisionAdapter::class)->pullEvents($device);

        expect(array_column($events, 'type'))->toBe(['check_in', 'auto']);
        Http::assertSent(fn (HttpRequest $r) => ($r['AcsEventCond']['minor'] ?? null) === 0
            && ($r['AcsEventCond']['searchResultPosition'] ?? null) === 100);
    });

    it('records a generic middleware event with no direction as auto, not as a check-out', function () {
        $device = Device::factory()->make([
            'adapter_type' => 'generic',
            'connection_config' => ['base_url' => 'https://middleware.example.com'],
        ]);
        Http::fake(['middleware.example.com/events*' => Http::response([
            ['badge' => '7', 'timestamp' => '2026-10-14T08:00:00+03:00', 'type' => ''],
            ['badge' => '7', 'timestamp' => '2026-10-14T17:00:00+03:00', 'type' => 'out'],
        ])]);

        $events = app(GenericHttpAdapter::class)->pullEvents($device);

        expect(array_column($events, 'type'))->toBe(['auto', 'check_out']);
    });
});

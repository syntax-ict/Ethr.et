<?php

declare(strict_types=1);

/**
 * Protocol-level coverage for the three device adapters that speak a real
 * vendor API (Hikvision ISAPI, Suprema BioStar 2, ZKTeco) — previously only
 * covered by "does the factory resolve the right class" tests, unlike
 * GenericHttpAdapter which already had this shape of test. None of these can
 * be verified against real hardware in this environment; Http::fake proves
 * the request shape and response parsing are correct, which is the ceiling
 * of what's verifiable without a physical device.
 */

use App\Exceptions\DeviceRequestFailed;
use App\Jobs\PullDeviceEventsJob;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Device\DeviceHost;
use App\Services\Device\DeviceManager;
use App\Services\Device\GenericHttpAdapter;
use App\Services\Device\HikvisionAdapter;
use App\Services\Device\SupremaAdapter;
use App\Services\Device\ZktecoAdapter;
use App\Services\Identity\IdentityResolver;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function hikvisionDevice(): Device
{
    return Device::factory()->make([
        'connection_config' => [
            'ip' => '10.0.0.5',
            'port' => 80,
            'username' => 'admin',
            'password' => 'secret',
        ],
    ]);
}

function supremaDevice(): Device
{
    return Device::factory()->make([
        'connection_config' => [
            'ip' => 'biostar.example.com',
            'port' => 443,
            'api_key' => 'test-key',
            'device_id' => 'D-1',
        ],
    ]);
}

function zktecoDevice(): Device
{
    return Device::factory()->make([
        'connection_config' => [
            'ip' => '10.0.0.9',
            'port' => 8080,
            'api_key' => 'zk-token',
        ],
    ]);
}

describe('HikvisionAdapter', function () {
    it('connects by requesting the ISAPI device info endpoint', function () {
        $device = hikvisionDevice();
        Http::fake(['10.0.0.5/ISAPI/System/deviceInfo' => Http::response('<x/>', 200)]);

        expect(app(HikvisionAdapter::class)->connect($device))->toBeTrue();

        // Default-port URIs (:80 here) are normalized out by the HTTP client,
        // so this checks the host+path rather than assuming the port survives.
        Http::assertSent(fn ($r) => str_contains($r->url(), '10.0.0.5')
            && str_ends_with($r->url(), '/ISAPI/System/deviceInfo'));
    });

    it('pulls attendance events from the ISAPI access-control search endpoint', function () {
        $device = hikvisionDevice();
        Http::fake([
            '10.0.0.5/ISAPI/AccessControl/AcsEvent*' => Http::response([
                'AcsEvent' => [
                    'InfoList' => [
                        ['employeeNoString' => '42', 'time' => '2026-08-04T08:00:00+03:00', 'eventType' => 1],
                        ['employeeNoString' => '42', 'time' => '2026-08-04T17:00:00+03:00', 'eventType' => 2],
                    ],
                ],
            ]),
        ]);

        $events = app(HikvisionAdapter::class)->pullEvents($device);

        expect($events)->toHaveCount(2);
        expect($events[0])->toMatchArray([
            'employee_badge' => '42',
            'type' => 'check_in',
        ]);
        expect($events[1]['type'])->toBe('check_out');
    });

    it('pulls enrolled users from the ISAPI user-info search endpoint', function () {
        $device = hikvisionDevice();
        Http::fake([
            '10.0.0.5/ISAPI/AccessControl/UserInfo/Search*' => Http::response([
                'UserInfoSearch' => [
                    'UserInfo' => [
                        ['employeeNo' => '7', 'name' => 'Abebe Kebede', 'cardNo' => 'C7', 'numOfFP' => 2, 'numOfFace' => 1],
                    ],
                ],
            ]),
        ]);

        $enrollments = app(HikvisionAdapter::class)->pullEnrollments($device);

        expect($enrollments)->toHaveCount(1);
        expect($enrollments[0])->toMatchArray([
            'device_user_id' => '7',
            'name' => 'Abebe Kebede',
            'card_number' => 'C7',
            'fingerprint_count' => 2,
            'face_registered' => true,
        ]);
    });

    it('throws on an unreachable device when pulling events, and reports false from connect', function () {
        $device = hikvisionDevice();
        Http::fake(['10.0.0.5/*' => fn () => throw new ConnectionException('timed out')]);

        // An empty list here is what an idle device returns, so the sync took a
        // failed read for "nothing new" and moved its cursor past it.
        expect(fn () => app(HikvisionAdapter::class)->pullEvents($device))->toThrow(DeviceRequestFailed::class);
        expect(app(HikvisionAdapter::class)->connect($device))->toBeFalse();
    });
});

describe('SupremaAdapter', function () {
    it('authenticates every request with the configured X-API-Key header', function () {
        $device = supremaDevice();
        Http::fake(['biostar.example.com/*' => Http::response(['status' => 'NORMAL'])]);

        app(SupremaAdapter::class)->getStatus($device);

        // :443 is the HTTPS default and is normalized out of the URL string,
        // so this checks the scheme+host rather than assuming the port survives.
        Http::assertSent(fn ($r) => $r->hasHeader('X-API-Key', 'test-key')
            && str_starts_with($r->url(), 'https://biostar.example.com/'));
    });

    it('reports online only when BioStar reports NORMAL status', function () {
        $device = supremaDevice();
        Http::fake(['biostar.example.com/*' => Http::response(['status' => 'DISCONNECTED'])]);

        expect(app(SupremaAdapter::class)->getStatus($device)['online'])->toBeFalse();
    });

    it('pulls events from the BioStar events endpoint and classifies check-out by event code', function () {
        $device = supremaDevice();
        Http::fake([
            'biostar.example.com/api/events*' => Http::response([
                'records' => [
                    ['user_id' => '9', 'datetime' => '2026-08-04T08:00:00Z', 'event_type_id' => 0x1010],
                    ['user_id' => '9', 'datetime' => '2026-08-04T17:00:00Z', 'event_type_id' => 0x2010],
                ],
            ]),
        ]);

        $events = app(SupremaAdapter::class)->pullEvents($device);

        expect($events[0]['type'])->toBe('check_in');
        expect($events[1]['type'])->toBe('check_out');
    });

    it('pulls enrolled users from the BioStar users endpoint', function () {
        $device = supremaDevice();
        Http::fake([
            'biostar.example.com/api/users*' => Http::response([
                'records' => [
                    ['user_id' => '9', 'name' => 'Sara Bekele', 'cards' => [['card_id' => 'CARD-9']], 'fingerprint_templates' => 2],
                ],
            ]),
        ]);

        $enrollments = app(SupremaAdapter::class)->pullEnrollments($device);

        expect($enrollments[0])->toMatchArray([
            'device_user_id' => '9',
            'name' => 'Sara Bekele',
            'card_number' => 'CARD-9',
            'fingerprint_count' => 2,
        ]);
    });
});

describe('ZktecoAdapter', function () {
    it('authenticates with a bearer token when an api_key is configured', function () {
        $device = zktecoDevice();
        Http::fake(['10.0.0.9:8080/*' => Http::response(['logs' => []])]);

        app(ZktecoAdapter::class)->pullEvents($device);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer zk-token'));
    });

    it('maps punch codes to check-in / check-out', function () {
        $device = zktecoDevice();
        Http::fake([
            '10.0.0.9:8080/api/attendance/logs*' => Http::response([
                'logs' => [
                    ['pin' => '5', 'timestamp' => '2026-08-04T08:00:00Z', 'punch' => 0],
                    ['pin' => '5', 'timestamp' => '2026-08-04T17:00:00Z', 'punch' => 1],
                ],
            ]),
        ]);

        $events = app(ZktecoAdapter::class)->pullEvents($device);

        expect($events[0])->toMatchArray(['employee_badge' => '5', 'type' => 'check_in']);
        expect($events[1]['type'])->toBe('check_out');
    });

    it('pulls enrolled users from the ZKTeco users endpoint', function () {
        $device = zktecoDevice();
        Http::fake([
            '10.0.0.9:8080/api/users*' => Http::response([
                'users' => [
                    ['pin' => '5', 'name' => 'Mulu Alem', 'card' => 'C5', 'dept_name' => 'Finance'],
                ],
            ]),
        ]);

        $enrollments = app(ZktecoAdapter::class)->pullEnrollments($device);

        expect($enrollments[0])->toMatchArray([
            'device_user_id' => '5',
            'name' => 'Mulu Alem',
            'card_number' => 'C5',
            'department' => 'Finance',
        ]);
    });

    it('throws on a non-200 response when pulling events; enrollment discovery still answers empty', function () {
        $device = zktecoDevice();
        Http::fake(['10.0.0.9:8080/*' => Http::response(null, 500)]);

        expect(fn () => app(ZktecoAdapter::class)->pullEvents($device))->toThrow(DeviceRequestFailed::class);
        // Discovery moves no cursor, so an empty roster loses nothing. It is
        // unchanged here; see DEVICE_INTEGRATION.md.
        expect(app(ZktecoAdapter::class)->pullEnrollments($device))->toBe([]);
    });
});

// An IPv6 device address is accepted by DeviceConnectionConfig, because
// DeviceHost treats any valid IP as well-formed. But the three vendor adapters
// interpolated it unbracketed, `http://2606:4700:4700::1111:80/...`, which curl
// rejects outright ("URL rejected: Port number was not a decimal number"). So a
// device saved with a public IPv6 address passed validation and then failed
// every request. RFC 3986 §3.2.2: an IPv6 literal in a URL goes in brackets.
function genericDevice(): Device
{
    return Device::factory()->make([
        'adapter_type' => 'generic',
        'connection_config' => ['base_url' => 'https://mw.local'],
    ]);
}

/**
 * Each adapter, with a device for it and the body an idle device answers with:
 * a successful reply that carries no events.
 *
 * @return array<string, array{0: Closure(): array{0: class-string, 1: Device}, 1: mixed}>
 */
function eventReaders(): array
{
    return [
        'Hikvision' => [fn () => [HikvisionAdapter::class, hikvisionDevice()], ['AcsEvent' => ['numOfMatches' => 0]]],
        'Suprema' => [fn () => [SupremaAdapter::class, supremaDevice()], ['records' => []]],
        'ZKTeco' => [fn () => [ZktecoAdapter::class, zktecoDevice()], ['logs' => []]],
        'generic' => [fn () => [GenericHttpAdapter::class, genericDevice()], []],
    ];
}

describe('a failed event read is not an empty one', function () {
    it('throws when the device cannot be reached', function (Closure $reader) {
        [$class, $device] = $reader();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 for https://user:pass@mw.local/events')]);

        expect(fn () => app($class)->pullEvents($device))
            ->toThrow(DeviceRequestFailed::class, 'unreachable');
    })->with(fn () => array_map(fn (array $row) => [$row[0]], eventReaders()));

    it('does not quote the transport error, which can carry the URL and its credentials', function () {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 for https://user:pass@mw.local/events')]);

        try {
            app(GenericHttpAdapter::class)->pullEvents(genericDevice());
            $this->fail('expected DeviceRequestFailed');
        } catch (DeviceRequestFailed $e) {
            expect($e->getMessage())->not->toContain('pass')
                ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
        }
    });

    it('throws on a non-2xx answer', function (Closure $reader) {
        [$class, $device] = $reader();
        Http::fake(['*' => Http::response(['error' => 'busy'], 503)]);

        expect(fn () => app($class)->pullEvents($device))
            ->toThrow(DeviceRequestFailed::class, 'HTTP 503');
    })->with(fn () => array_map(fn (array $row) => [$row[0]], eventReaders()));

    it('throws on a 2xx answer that is not JSON', function (Closure $reader) {
        [$class, $device] = $reader();
        // A captive portal or a proxy error page, served with a 200.
        Http::fake(['*' => Http::response('<html><body>Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

        expect(fn () => app($class)->pullEvents($device))
            ->toThrow(DeviceRequestFailed::class, 'not JSON');
    })->with(fn () => array_map(fn (array $row) => [$row[0]], eventReaders()));

    it('still answers an empty list for a device that is simply idle', function (Closure $reader, mixed $idleBody) {
        [$class, $device] = $reader();
        Http::fake(['*' => Http::response($idleBody, 200)]);

        expect(app($class)->pullEvents($device))->toBe([]);
    })->with(fn () => eventReaders());

    it('leaves the sync cursor where it was when the event read fails', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = Device::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'adapter_type' => 'hikvision',
            'status' => 'online',
            'connection_config' => ['ip' => '10.0.0.5', 'port' => 80, 'username' => 'admin', 'password' => 'secret'],
            'last_sync_at' => Carbon::parse('2026-07-31 08:55:00'),
        ]);

        // The device is up, so the status check passes; the event search fails.
        Http::fake([
            '10.0.0.5/ISAPI/System/status' => Http::response(['DeviceStatus' => []], 200),
            '10.0.0.5/ISAPI/AccessControl/*' => Http::response(null, 500),
        ]);

        expect(fn () => (new PullDeviceEventsJob($device))->handle(
            app(DeviceManager::class),
            app(AttendanceEngine::class),
            app(IdentityResolver::class),
        ))->toThrow(DeviceRequestFailed::class);

        $device->refresh();
        $log = DeviceSyncLog::where('device_id', $device->id)->latest('id')->first();

        // Before: last_sync_at moved to 09:00 and the five minutes since 08:55
        // were never read again. Now the next attempt reads from 08:55.
        expect($device->last_sync_at->toDateTimeString())->toBe('2026-07-31 08:55:00')
            ->and($device->status)->toBe('error')
            ->and($log->status)->toBe('failed')
            ->and($log->error_message)->toContain('HTTP 500');

        Carbon::setTestNow();
    });
});

describe('IPv6 device addresses', function () {
    it('is an address DeviceConnectionConfig accepts', function () {
        expect(DeviceHost::refusal('2606:4700:4700::1111'))->toBeNull();
    });

    it('brackets an IPv6 host in the request URL', function (string $adapterClass, int $port, string $scheme) {
        $device = Device::factory()->make([
            'connection_config' => [
                'ip' => '2606:4700:4700::1111',
                'port' => $port,
                'api_key' => 'k',
                'device_id' => 'D-1',
            ],
        ]);
        Http::fake();

        app($adapterClass)->connect($device);

        // Asserted on the parsed URI, because Guzzle drops a scheme's default
        // port (80, 443) from the string form.
        Http::assertSent(function ($request) use ($scheme, $port) {
            $uri = $request->toPsrRequest()->getUri();

            return $uri->getScheme() === $scheme
                && $uri->getHost() === '[2606:4700:4700::1111]'
                && ($uri->getPort() ?? ($scheme === 'https' ? 443 : 80)) === $port;
        });
    })->with([
        'hikvision' => [HikvisionAdapter::class, 80, 'http'],
        'zkteco' => [ZktecoAdapter::class, 4370, 'http'],
        'suprema' => [SupremaAdapter::class, 443, 'https'],
    ]);

    it('formats an IPv6 base URL with brackets', function () {
        expect(DeviceHost::baseUrl('http', '2606:4700:4700::1111', '80'))
            ->toBe('http://[2606:4700:4700::1111]:80');
    });

    it('leaves IPv4 and DNS hosts as they were', function () {
        expect(DeviceHost::baseUrl('http', '203.0.113.5', '80'))->toBe('http://203.0.113.5:80')
            ->and(DeviceHost::baseUrl('https', 'biostar.example.com', '443'))->toBe('https://biostar.example.com:443');
    });
});

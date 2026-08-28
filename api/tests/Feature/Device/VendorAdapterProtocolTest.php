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

use App\Models\Device;
use App\Services\Device\HikvisionAdapter;
use App\Services\Device\SupremaAdapter;
use App\Services\Device\ZktecoAdapter;
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

    it('returns an empty list rather than throwing when the device is unreachable', function () {
        $device = hikvisionDevice();
        Http::fake(['10.0.0.5/*' => fn () => throw new ConnectionException('timed out')]);

        expect(app(HikvisionAdapter::class)->pullEvents($device))->toBe([]);
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

    it('returns an empty list on a non-200 response rather than throwing', function () {
        $device = zktecoDevice();
        Http::fake(['10.0.0.9:8080/*' => Http::response(null, 500)]);

        expect(app(ZktecoAdapter::class)->pullEvents($device))->toBe([]);
        expect(app(ZktecoAdapter::class)->pullEnrollments($device))->toBe([]);
    });
});

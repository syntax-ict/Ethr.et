<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\AcceptsDeviceCommands;
use App\Contracts\DeviceAdapter;
use App\Enums\DeviceCommandType;
use App\Exceptions\DeviceCommandRejected;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Device\Concerns\ReadsFromDevice;
use App\Support\DeviceTime;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class HikvisionAdapter implements AcceptsDeviceCommands, DeviceAdapter
{
    use ReadsFromDevice;

    public function connect(Device $device): bool
    {
        try {
            $response = $this->request($device, 'GET', '/ISAPI/System/deviceInfo');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Hikvision connect failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getStatus(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/ISAPI/System/status');

            if ($response->successful()) {
                return [
                    'online' => true,
                    'response_time_ms' => $response->handlerStats()['total_time_us'] ?? null,
                ];
            }

            return ['online' => false, 'error' => 'Non-200 response'];
        } catch (\Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    public function getDeviceInfo(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/ISAPI/System/deviceInfo');

            if ($response->successful()) {
                $body = $response->body();

                return [
                    'model' => $this->parseXmlField($body, 'model'),
                    'serial_number' => $this->parseXmlField($body, 'serialNumber'),
                    'firmware_version' => $this->parseXmlField($body, 'firmwareVersion'),
                    'device_name' => $this->parseXmlField($body, 'deviceName'),
                ];
            }

            return [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Pages per pull: 10 × 100 events. The job pulls again from the last timestamp. */
    private const MAX_PAGES = 10;

    private const PAGE_SIZE = 100;

    /**
     * Every access event since `$since` that names an enrolled person,
     * paged with `searchResultPosition` while the device answers `MORE`.
     * The search used to ask for one minor type (75, face pass) and one page,
     * so card and fingerprint punches were never pulled and a busy morning
     * stopped at 100.
     */
    public function pullEvents(Device $device, ?string $since = null): array
    {
        $searchId = (string) Str::uuid();
        $events = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $searchBody = $this->buildEventSearchBody($since, $searchId, $page * self::PAGE_SIZE);
            $response = $this->readDevice('Hikvision', fn () => $this->request(
                $device, 'POST', '/ISAPI/AccessControl/AcsEvent?format=json', $searchBody,
            ));

            $data = $response->json('AcsEvent') ?? [];

            // No InfoList is how ISAPI says nothing matched.
            foreach ($data['InfoList'] ?? [] as $event) {
                $badge = is_array($event) ? HikvisionEvent::badge($event) : null;
                if ($badge === null) {
                    continue;
                }

                $events[] = [
                    'employee_badge' => $badge,
                    'timestamp' => $event['time'] ?? '',
                    'type' => HikvisionEvent::direction($event),
                    'raw' => $event,
                ];
            }

            if (($data['responseStatusStrg'] ?? 'OK') !== 'MORE') {
                break;
            }
        }

        return $events;
    }

    public function pullEnrollments(Device $device): array
    {
        $body = [
            'UserInfoSearchCond' => [
                'searchID' => (string) Str::uuid(),
                'searchResultPosition' => 0,
                'maxResults' => 200,
            ],
        ];

        $response = $this->readDevice('Hikvision', fn () => $this->request(
            $device, 'POST', '/ISAPI/AccessControl/UserInfo/Search?format=json', $body,
        ));

        $enrollments = [];
        foreach ($response->json('UserInfoSearch.UserInfo') ?? [] as $user) {
            $enrollments[] = [
                'device_user_id' => (string) ($user['employeeNo'] ?? ''),
                'name' => $user['name'] ?? null,
                'card_number' => $user['cardNo'] ?? null,
                'department' => $user['belongGroup'] ?? null,
                'fingerprint_count' => isset($user['numOfFP']) ? (int) $user['numOfFP'] : null,
                'face_registered' => isset($user['numOfFace']) ? ((int) $user['numOfFace']) > 0 : null,
            ];
        }

        return $enrollments;
    }

    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        try {
            $body = [
                'HttpHostNotification' => [
                    'id' => '1',
                    'url' => $callbackUrl,
                    'protocolType' => 'HTTP',
                    'parameterFormatType' => 'JSON',
                    'addressingFormatType' => 'ipaddress',
                    'httpAuthenticationMethod' => 'none',
                ],
            ];

            $response = $this->request($device, 'PUT', '/ISAPI/Event/notification/httpHosts/1', $body);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Hikvision pushEventUrl failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Carry out a command through ISAPI. The calls follow Hikvision's published
     * ISAPI access-control specification; they have not yet been run against a
     * real terminal (field test O1), so the first one should be watched.
     *
     * - set_time: PUT /ISAPI/System/time, the device's zone as a POSIX string
     * - upsert_user: POST /ISAPI/AccessControl/UserInfo/SetUp (add or modify)
     * - delete_user: PUT /ISAPI/AccessControl/UserInfo/Delete
     */
    public function execute(Device $device, DeviceCommand $command): void
    {
        $payload = $command->payload ?? [];

        $response = match ($command->type) {
            DeviceCommandType::SET_TIME => $this->setTime($device),
            DeviceCommandType::UPSERT_USER => $this->request($device, 'POST', '/ISAPI/AccessControl/UserInfo/SetUp?format=json', [
                'UserInfo' => [
                    'employeeNo' => (string) $payload['device_user_id'],
                    'name' => (string) ($payload['name'] ?? ''),
                    'userType' => 'normal',
                    'Valid' => [
                        'enable' => true,
                        'beginTime' => '2020-01-01T00:00:00',
                        'endTime' => '2037-12-31T23:59:59',
                        'timeType' => 'local',
                    ],
                ],
            ]),
            DeviceCommandType::DELETE_USER => $this->request($device, 'PUT', '/ISAPI/AccessControl/UserInfo/Delete?format=json', [
                'UserInfoDelCond' => [
                    'EmployeeNoList' => [['employeeNo' => (string) $payload['device_user_id']]],
                ],
            ]),
            // Hikvision reads its roster directly (pullEnrollments); this is for
            // devices that report it later, and is never queued for this one.
            DeviceCommandType::QUERY_USERS => throw new DeviceCommandRejected('This device is read directly; it takes no roster request.'),
        };

        if ($response->successful()) {
            return;
        }

        // Removing someone the device does not have has the outcome asked for.
        if ($command->type === DeviceCommandType::DELETE_USER && str_contains($response->body(), 'NotExist')) {
            return;
        }

        throw new DeviceCommandRejected(sprintf(
            'HTTP %d: %s',
            $response->status(),
            mb_substr(trim(strip_tags($response->body())), 0, 300),
        ));
    }

    private function setTime(Device $device): Response
    {
        $zone = DeviceTime::zone($device);
        $now = Carbon::now($zone);

        // ISAPI takes a POSIX zone, whose sign is the reverse of the offset:
        // UTC+3 (Addis Ababa) is "CST-3:00:00".
        $offset = $now->getOffset();
        $posix = sprintf('CST%s%d:%02d:00', $offset >= 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Time><timeMode>manual</timeMode>'
            .'<localTime>'.$now->format('Y-m-d\TH:i:s').'</localTime>'
            .'<timeZone>'.$posix.'</timeZone></Time>';

        [$client, $baseUrl] = $this->client($device);

        return $client->withBody($xml, 'application/xml')->put("{$baseUrl}/ISAPI/System/time");
    }

    private function request(Device $device, string $method, string $path, ?array $body = null): Response
    {
        [$request, $baseUrl] = $this->client($device);

        if ($body !== null) {
            return $request->$method("{$baseUrl}{$path}", $body);
        }

        return $request->$method("{$baseUrl}{$path}");
    }

    /** @return array{0: PendingRequest, 1: string} */
    private function client(Device $device): array
    {
        // encrypted:array, but typed as string without a model @property; narrow
        // it the way GenericHttpAdapter::config() does.
        $config = $device->getAttribute('connection_config');
        $config = is_array($config) ? $config : [];
        DeviceHost::assertAllowed((string) ($config['ip'] ?? ''));
        $baseUrl = DeviceHost::baseUrl('http', (string) $config['ip'], (string) $config['port']);

        // connectTimeout bounds the TCP connect phase so an unreachable device fails fast
        // (Http::timeout only caps the request once connected) — keeps status checks from
        // blocking a worker for the full read timeout on an offline device.
        $request = Http::connectTimeout(2)->timeout(10)
            ->withBasicAuth($config['username'] ?? 'admin', $config['password'] ?? '');

        return [$request, $baseUrl];
    }

    /**
     * Major 5 is "event" (authentication and door activity); minor 0 asks for
     * every kind within it, and pullEvents() keeps only events that name an
     * enrolled person.
     */
    private function buildEventSearchBody(?string $since, string $searchId, int $position): array
    {
        $searchBody = [
            'AcsEventCond' => [
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => self::PAGE_SIZE,
                'major' => 5,
                'minor' => 0,
            ],
        ];

        if ($since) {
            $searchBody['AcsEventCond']['startTime'] = $since;
            $searchBody['AcsEventCond']['endTime'] = now()->format('Y-m-d\TH:i:sP');
        }

        return $searchBody;
    }

    private function parseXmlField(string $xml, string $field): ?string
    {
        if (preg_match("/<{$field}>(.*?)<\/{$field}>/", $xml, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

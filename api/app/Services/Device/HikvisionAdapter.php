<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class HikvisionAdapter implements DeviceAdapter
{
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

    public function pullEvents(Device $device, ?string $since = null): array
    {
        try {
            $searchBody = $this->buildEventSearchBody($since);
            $response = $this->request($device, 'POST', '/ISAPI/AccessControl/AcsEvent?format=json', $searchBody);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $events = [];

            foreach ($data['AcsEvent']['InfoList'] ?? [] as $event) {
                $events[] = [
                    'employee_badge' => (string) ($event['employeeNoString'] ?? $event['cardNo'] ?? ''),
                    'timestamp' => $event['time'] ?? '',
                    'type' => $this->mapEventType($event['eventType'] ?? 0),
                    'raw' => $event,
                ];
            }

            return $events;
        } catch (\Throwable $e) {
            Log::error('Hikvision pullEvents failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function pullEnrollments(Device $device): array
    {
        try {
            $body = [
                'UserInfoSearchCond' => [
                    'searchID' => (string) Str::uuid(),
                    'searchResultPosition' => 0,
                    'maxResults' => 200,
                ],
            ];

            $response = $this->request($device, 'POST', '/ISAPI/AccessControl/UserInfo/Search?format=json', $body);

            if (! $response->successful()) {
                return [];
            }

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
        } catch (\Throwable $e) {
            Log::error('Hikvision pullEnrollments failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

    private function request(Device $device, string $method, string $path, ?array $body = null): Response
    {
        $config = $device->connection_config;
        $baseUrl = "http://{$config['ip']}:{$config['port']}";

        // connectTimeout bounds the TCP connect phase so an unreachable device fails fast
        // (Http::timeout only caps the request once connected) — keeps status checks from
        // blocking a worker for the full read timeout on an offline device.
        $request = Http::connectTimeout(2)->timeout(10)
            ->withBasicAuth($config['username'] ?? 'admin', $config['password'] ?? '');

        if ($body !== null) {
            return $request->$method("{$baseUrl}{$path}", $body);
        }

        return $request->$method("{$baseUrl}{$path}");
    }

    private function buildEventSearchBody(?string $since): array
    {
        $searchBody = [
            'AcsEventCond' => [
                'searchID' => (string) Str::uuid(),
                'searchResultPosition' => 0,
                'maxResults' => 100,
                'major' => 5,
                'minor' => 75,
            ],
        ];

        if ($since) {
            $searchBody['AcsEventCond']['startTime'] = $since;
            $searchBody['AcsEventCond']['endTime'] = now()->format('Y-m-d\TH:i:sP');
        }

        return $searchBody;
    }

    private function mapEventType(int $eventType): string
    {
        return match ($eventType) {
            1 => 'check_in',
            2 => 'check_out',
            default => 'check_in',
        };
    }

    private function parseXmlField(string $xml, string $field): ?string
    {
        if (preg_match("/<{$field}>(.*?)<\/{$field}>/", $xml, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

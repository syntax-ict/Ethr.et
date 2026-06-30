<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Suprema BioStar 2 G-SDK adapter.
 *
 * Targets the BioStar 2 cloud API for device management. Specific endpoints
 * follow the BioStar 2 REST conventions documented at
 * https://www.supremainc.com/biostar2-api-docs/.
 *
 * Connection config (stored on device.connection_config):
 *   - ip:        BioStar 2 server host
 *   - port:      Default 443
 *   - api_key:   X-API-Key issued by BioStar 2
 *   - device_id: Suprema device identifier on the server
 */
final class SupremaAdapter implements DeviceAdapter
{
    public function connect(Device $device): bool
    {
        try {
            $response = $this->request($device, 'GET', '/api/devices/'.$this->deviceId($device));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Suprema connect failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getStatus(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/api/devices/'.$this->deviceId($device).'/status');

            if (! $response->successful()) {
                return ['online' => false, 'error' => 'Non-200 response'];
            }

            $data = $response->json();

            return [
                'online' => ($data['status'] ?? null) === 'NORMAL',
                'response_time_ms' => $response->handlerStats()['total_time_us'] ?? null,
                'last_heartbeat' => $data['last_communication'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    public function getDeviceInfo(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/api/devices/'.$this->deviceId($device));

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();

            return [
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial'] ?? null,
                'firmware_version' => $data['firmware_version'] ?? null,
                'device_name' => $data['name'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function pullEvents(Device $device, ?string $since = null): array
    {
        try {
            $query = ['limit' => 200, 'device_id' => $this->deviceId($device)];
            if ($since) {
                $query['start_datetime'] = $since;
            }

            $response = $this->request($device, 'GET', '/api/events?'.http_build_query($query));

            if (! $response->successful()) {
                return [];
            }

            $events = [];
            foreach (($response->json('records') ?? []) as $event) {
                $events[] = [
                    'employee_badge' => (string) ($event['user_id'] ?? $event['user']['user_id'] ?? ''),
                    'timestamp' => $event['datetime'] ?? '',
                    'type' => $this->mapEventType((int) ($event['event_type_id'] ?? 0)),
                    'raw' => $event,
                ];
            }

            return $events;
        } catch (\Throwable $e) {
            Log::error('Suprema pullEvents failed', [
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
                'webhook' => [
                    'url' => $callbackUrl,
                    'method' => 'POST',
                    'content_type' => 'application/json',
                    'events' => ['identify_success', 'identify_fail', 'access_granted', 'access_denied'],
                ],
            ];

            $response = $this->request($device, 'POST', '/api/webhooks', $body);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Suprema pushEventUrl failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function request(Device $device, string $method, string $path, ?array $body = null): \Illuminate\Http\Client\Response
    {
        $config = $device->connection_config;
        $port = $config['port'] ?? 443;
        $scheme = $port === 443 ? 'https' : 'http';
        $baseUrl = "{$scheme}://{$config['ip']}:{$port}";

        $request = Http::timeout(10)
            ->withHeaders([
                'X-API-Key' => $config['api_key'] ?? '',
                'Accept' => 'application/json',
            ]);

        if ($body !== null) {
            return $request->$method("{$baseUrl}{$path}", $body);
        }

        return $request->$method("{$baseUrl}{$path}");
    }

    private function deviceId(Device $device): string
    {
        return (string) ($device->connection_config['device_id'] ?? $device->serial_number ?? $device->id);
    }

    private function mapEventType(int $eventType): string
    {
        // BioStar 2 codes — 0x1000 family = entry, 0x2000 family = exit
        if ($eventType >= 0x2000 && $eventType < 0x3000) {
            return 'check_out';
        }

        return 'check_in';
    }
}

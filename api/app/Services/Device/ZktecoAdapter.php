<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;
use App\Services\Device\Concerns\ReadsFromDevice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ZktecoAdapter implements DeviceAdapter
{
    use ReadsFromDevice;

    public function connect(Device $device): bool
    {
        try {
            $response = $this->request($device, 'GET', '/api/status');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('ZKTeco connect failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getStatus(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/api/status');

            if ($response->successful()) {
                return ['online' => true];
            }

            return ['online' => false, 'error' => 'Non-200 response'];
        } catch (\Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    public function getDeviceInfo(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/api/device/info');

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            return [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function pullEvents(Device $device, ?string $since = null): array
    {
        $params = ['limit' => 100];
        if ($since) {
            $params['since'] = $since;
        }

        $response = $this->readDevice('ZKTeco', fn () => $this->request($device, 'GET', '/api/attendance/logs', $params));

        $data = $response->json();
        $events = [];

        foreach ($data['logs'] ?? $data as $log) {
            $events[] = [
                'employee_badge' => (string) ($log['pin'] ?? $log['user_id'] ?? ''),
                'timestamp' => $log['timestamp'] ?? $log['datetime'] ?? '',
                'type' => self::directionFor($log['punch'] ?? $log['status'] ?? null),
                'raw' => $log,
            ];
        }

        return $events;
    }

    public function pullEnrollments(Device $device): array
    {
        $response = $this->readDevice('ZKTeco', fn () => $this->request($device, 'GET', '/api/users', ['limit' => 500]));

        $data = $response->json();
        $enrollments = [];

        foreach ($data['users'] ?? $data as $user) {
            $enrollments[] = [
                'device_user_id' => (string) ($user['pin'] ?? $user['user_id'] ?? ''),
                'name' => $user['name'] ?? null,
                'card_number' => isset($user['card']) ? (string) $user['card'] : null,
                'department' => $user['dept_name'] ?? $user['department'] ?? null,
                'fingerprint_count' => isset($user['fp_count']) ? (int) $user['fp_count'] : null,
                'face_registered' => isset($user['face']) ? (bool) $user['face'] : null,
            ];
        }

        return $enrollments;
    }

    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        try {
            $response = $this->request($device, 'POST', '/api/webhook/configure', [
                'url' => $callbackUrl,
                'events' => ['attendance'],
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('ZKTeco pushEventUrl failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function request(Device $device, string $method, string $path, ?array $params = null): Response
    {
        // encrypted:array, but typed as string without a model @property; narrow
        // it the way GenericHttpAdapter::config() does.
        $config = $device->getAttribute('connection_config');
        $config = is_array($config) ? $config : [];
        $pin = DeviceHost::connectOptions((string) ($config['ip'] ?? ''), (string) ($config['port'] ?? 80));
        $baseUrl = DeviceHost::baseUrl('http', (string) $config['ip'], (string) $config['port']);

        // connectTimeout bounds the TCP connect phase so an unreachable device fails fast.
        $request = Http::connectTimeout(2)->timeout(10)->withOptions($pin);

        if (! empty($config['api_key'])) {
            $request = $request->withHeaders(['Authorization' => "Bearer {$config['api_key']}"]);
        } elseif (! empty($config['username'])) {
            $request = $request->withBasicAuth($config['username'], $config['password'] ?? '');
        }

        if ($method === 'GET' && $params) {
            return $request->get("{$baseUrl}{$path}", $params);
        }

        if ($params !== null) {
            return $request->$method("{$baseUrl}{$path}", $params);
        }

        return $request->$method("{$baseUrl}{$path}");
    }

    /**
     * ZKTeco punch states: 0 check-in, 1 check-out, 2 break-out, 3 break-in,
     * 4 overtime-in, 5 overtime-out. A terminal with no state key, or 255
     * ("undefined") on newer firmware, states no direction — `auto` lets the
     * engine close the day's open record or open one. Unknown states used to
     * default to check-in, so a terminal without state keys never recorded a
     * check-out. Shared with the ZKTeco webhook (DeviceController).
     */
    public static function directionFor(mixed $state): string
    {
        if ($state === null || $state === '' || ! is_numeric($state)) {
            return 'auto';
        }

        return match ((int) $state) {
            0, 3, 4 => 'check_in',
            1, 2, 5 => 'check_out',
            default => 'auto',
        };
    }
}

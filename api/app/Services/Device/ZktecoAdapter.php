<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ZktecoAdapter implements DeviceAdapter
{
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
        try {
            $params = ['limit' => 100];
            if ($since) {
                $params['since'] = $since;
            }

            $response = $this->request($device, 'GET', '/api/attendance/logs', $params);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $events = [];

            foreach ($data['logs'] ?? $data ?? [] as $log) {
                $events[] = [
                    'employee_badge' => (string) ($log['pin'] ?? $log['user_id'] ?? ''),
                    'timestamp' => $log['timestamp'] ?? $log['datetime'] ?? '',
                    'type' => $this->mapPunchType($log['punch'] ?? $log['status'] ?? 0),
                    'raw' => $log,
                ];
            }

            return $events;
        } catch (\Throwable $e) {
            Log::error('ZKTeco pullEvents failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function pullEnrollments(Device $device): array
    {
        try {
            $response = $this->request($device, 'GET', '/api/users', ['limit' => 500]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $enrollments = [];

            foreach ($data['users'] ?? $data ?? [] as $user) {
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
        } catch (\Throwable $e) {
            Log::error('ZKTeco pullEnrollments failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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
        $config = $device->connection_config;
        $baseUrl = "http://{$config['ip']}:{$config['port']}";

        // connectTimeout bounds the TCP connect phase so an unreachable device fails fast.
        $request = Http::connectTimeout(2)->timeout(10);

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

    private function mapPunchType(int|string $punchType): string
    {
        return match ((int) $punchType) {
            0, 4 => 'check_in',
            1, 5 => 'check_out',
            default => 'check_in',
        };
    }
}

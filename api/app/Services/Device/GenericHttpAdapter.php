<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A configuration-driven adapter for any device or middleware that exposes a
 * JSON HTTP API. Covers the long tail of vendors ETHR has no native adapter for
 * (Anviz, FingerTec, ESSL, Matrix, Ronald Jack, and bespoke middleware) without
 * a code change per vendor — see ONBOARDING_V2.md decision D7.
 *
 * All shape knowledge lives in `connection_config`:
 *
 *   base_url          e.g. https://middleware.local:8080
 *   auth              { type: none|basic|bearer, username, password, token }
 *   events_path       default /events
 *   enrollments_path  default /users
 *   status_path       default /status
 *   mapping           dot-notated field names, e.g.
 *                     { badge: 'userId', timestamp: 'time', type: 'direction',
 *                       user_id: 'userId', name: 'fullName', card: 'card',
 *                       events_root: 'data', enrollments_root: 'data' }
 *   check_in_values   values of the `type` field that mean check-in (default ['in','check_in','0'])
 */
final class GenericHttpAdapter implements DeviceAdapter
{
    public function connect(Device $device): bool
    {
        try {
            return $this->client($device)->get($this->path($device, 'status_path', '/status'))->successful();
        } catch (\Throwable $e) {
            Log::warning('Generic device connect failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getStatus(Device $device): array
    {
        try {
            $response = $this->client($device)->get($this->path($device, 'status_path', '/status'));

            return ['online' => $response->successful()];
        } catch (\Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    public function getDeviceInfo(Device $device): array
    {
        return [
            'model' => 'Generic HTTP Device',
            'serial_number' => $device->serial_number,
            'device_name' => $device->name,
        ];
    }

    public function pullEvents(Device $device, ?string $since = null): array
    {
        try {
            $query = $since ? ['since' => $since] : [];
            $response = $this->client($device)->get($this->path($device, 'events_path', '/events'), $query);

            if (! $response->successful()) {
                return [];
            }

            $map = $this->mapping($device);
            $rows = $this->rows($response, $map['events_root'] ?? null);
            $checkInValues = $this->checkInValues($device);

            $events = [];
            foreach ($rows as $row) {
                $type = (string) Arr::get($row, $map['type'] ?? 'type', '');
                $events[] = [
                    'employee_badge' => (string) Arr::get($row, $map['badge'] ?? 'badge', ''),
                    'timestamp' => (string) Arr::get($row, $map['timestamp'] ?? 'timestamp', ''),
                    'type' => in_array(mb_strtolower($type), $checkInValues, true) ? 'check_in' : 'check_out',
                    'raw' => $row,
                ];
            }

            return $events;
        } catch (\Throwable $e) {
            Log::error('Generic device pullEvents failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function pullEnrollments(Device $device): array
    {
        try {
            $response = $this->client($device)->get($this->path($device, 'enrollments_path', '/users'));

            if (! $response->successful()) {
                return [];
            }

            $map = $this->mapping($device);
            $rows = $this->rows($response, $map['enrollments_root'] ?? null);

            $enrollments = [];
            foreach ($rows as $row) {
                $enrollments[] = [
                    'device_user_id' => (string) Arr::get($row, $map['user_id'] ?? 'user_id', ''),
                    'name' => $this->stringOrNull(Arr::get($row, $map['name'] ?? 'name')),
                    'card_number' => $this->stringOrNull(Arr::get($row, $map['card'] ?? 'card')),
                    'department' => $this->stringOrNull(Arr::get($row, $map['department'] ?? 'department')),
                    'fingerprint_count' => null,
                    'face_registered' => null,
                ];
            }

            return $enrollments;
        } catch (\Throwable $e) {
            Log::error('Generic device pullEnrollments failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        // Push registration is vendor-specific; generic devices are polled.
        return false;
    }

    private function client(Device $device): PendingRequest
    {
        $auth = $this->config($device)['auth'] ?? [];
        $auth = is_array($auth) ? $auth : [];

        $request = Http::connectTimeout(3)->timeout(15)->acceptJson();

        return match ($auth['type'] ?? 'none') {
            'basic' => $request->withBasicAuth((string) ($auth['username'] ?? ''), (string) ($auth['password'] ?? '')),
            'bearer' => $request->withToken((string) ($auth['token'] ?? '')),
            default => $request,
        };
    }

    private function path(Device $device, string $key, string $default): string
    {
        $config = $this->config($device);
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $path = (string) ($config[$key] ?? $default);

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * The device's connection_config as a plain array. Read via getAttribute so
     * the `encrypted:array` cast's loose static type doesn't defeat analysis.
     *
     * @return array<string, mixed>
     */
    private function config(Device $device): array
    {
        $config = $device->getAttribute('connection_config');

        return is_array($config) ? $config : [];
    }

    /** @return array<string, string> */
    private function mapping(Device $device): array
    {
        $raw = $this->config($device)['mapping'] ?? [];
        $map = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key)) {
                    $map[$key] = (string) $value;
                }
            }
        }

        return $map;
    }

    /**
     * Extract the list of records from a response, optionally nested under a
     * configured root key (e.g. `data`, `results`).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(Response $response, ?string $root): array
    {
        $data = $root ? $response->json($root) : $response->json();

        if (! is_array($data)) {
            return [];
        }

        // A bare object (single record or keyed map) is normalized to a list.
        return array_is_list($data) ? $data : [$data];
    }

    /** @return array<int, string> */
    private function checkInValues(Device $device): array
    {
        $values = $this->config($device)['check_in_values'] ?? ['in', 'check_in', '0', 'i'];

        return array_map(static fn ($v): string => mb_strtolower((string) $v), (array) $values);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

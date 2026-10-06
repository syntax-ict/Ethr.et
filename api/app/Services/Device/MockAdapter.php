<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\DeviceAdapter;
use App\Models\Device;
use App\Models\Employee;

final class MockAdapter implements DeviceAdapter
{
    public function connect(Device $device): bool
    {
        return true;
    }

    public function getStatus(Device $device): array
    {
        return [
            'online' => true,
            'uptime' => '7d 4h 32m',
            'firmware' => 'mock-1.0.0',
            'memory_usage' => '42%',
        ];
    }

    public function getDeviceInfo(Device $device): array
    {
        return [
            'model' => 'ETHR Mock Device',
            'serial_number' => $device->serial_number ?? 'MOCK-000000',
            'firmware_version' => 'mock-1.0.0',
            'device_name' => $device->name,
            'manufacturer' => 'ETHR (Simulator)',
            'capacity' => 1000,
            'registered_users' => 0,
        ];
    }

    /**
     * Generate simulated attendance events from real employees.
     *
     * @return array<int, array{employee_badge: string, timestamp: string, type: string}>
     */
    public function pullEvents(Device $device, ?string $since = null): array
    {
        $employees = Employee::where('tenant_id', $device->tenant_id)
            ->whereNotNull('employee_code')
            ->inRandomOrder()
            ->limit(10)
            ->get(['id', 'employee_code', 'badge_number']);

        if ($employees->isEmpty()) {
            return [];
        }

        $events = [];
        $baseTime = $since ? new \DateTimeImmutable($since) : new \DateTimeImmutable('today 08:00');

        foreach ($employees as $employee) {
            $badge = $employee->badge_number ?? $employee->employee_code;
            $minuteOffset = random_int(0, 30);

            $events[] = [
                'employee_badge' => $badge,
                'timestamp' => $baseTime->modify("+{$minuteOffset} minutes")->format('Y-m-d\TH:i:s'),
                'type' => 'check_in',
            ];

            if (random_int(0, 1)) {
                $outOffset = random_int(480, 570);
                $events[] = [
                    'employee_badge' => $badge,
                    'timestamp' => $baseTime->modify("+{$outOffset} minutes")->format('Y-m-d\TH:i:s'),
                    'type' => 'check_out',
                ];
            }
        }

        usort($events, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        return $events;
    }

    /**
     * Present the tenant's employees as if they were enrolled on the device,
     * so onboarding discovery and identity matching can be exercised end-to-end
     * without real hardware.
     *
     * @return array<int, array{device_user_id: string, name: ?string, card_number: ?string, department: ?string, fingerprint_count: ?int, face_registered: ?bool}>
     */
    public function pullEnrollments(Device $device): array
    {
        return Employee::where('tenant_id', $device->tenant_id)
            ->whereNotNull('employee_code')
            ->limit(500)
            ->get(['employee_code', 'badge_number', 'name'])
            ->map(fn (Employee $employee): array => [
                'device_user_id' => (string) ($employee->badge_number ?? $employee->employee_code),
                'name' => $employee->name,
                'card_number' => $employee->badge_number,
                'department' => null,
                'fingerprint_count' => 1,
                'face_registered' => false,
            ])
            ->all();
    }

    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        return true;
    }
}

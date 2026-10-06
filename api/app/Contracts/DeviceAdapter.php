<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Device;

interface DeviceAdapter
{
    public function connect(Device $device): bool;

    public function getStatus(Device $device): array;

    public function getDeviceInfo(Device $device): array;

    /**
     * @return array<int, array{employee_badge: string, timestamp: string, type: string}>
     */
    public function pullEvents(Device $device, ?string $since = null): array;

    /**
     * Read the people enrolled on the device (not their punches) so onboarding
     * can match them to internal employees before importing any attendance. See
     * ONBOARDING_V2.md decision D4 (workforce discovery, not re-creation).
     *
     * @return array<int, array{
     *     device_user_id: string,
     *     name: ?string,
     *     card_number: ?string,
     *     department: ?string,
     *     fingerprint_count: ?int,
     *     face_registered: ?bool
     * }>
     */
    public function pullEnrollments(Device $device): array;

    public function pushEventUrl(Device $device, string $callbackUrl): bool;
}

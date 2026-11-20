<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Enums\AttendanceSource;
use App\Exceptions\AttendanceRefused;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Support\DeviceClock;
use App\Support\DeviceTime;

/**
 * The steps every pushed punch shares, whichever way it arrived — a vendor
 * webhook (DeviceController) or a ZKTeco checking in over ADMS
 * (AdmsController): find the badge holder inside the device's tenant, read the
 * time in the device's zone, note what it says about the device's clock, and
 * hand the punch to the engine.
 *
 * The device must already be authenticated; nothing here decides whether a
 * caller may speak for it.
 */
final class DevicePunchRecorder
{
    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly IdentityResolver $resolver,
    ) {}

    /**
     * The employee a device badge belongs to, inside the device's own tenant.
     *
     * The same IdentityResolver the pull path uses (PullDeviceEventsJob), so a
     * device user id mapped once — by an earlier match or by the workforce
     * migration — resolves the same whether the event was pushed or pulled.
     * The resolver states `tenant_id` on every query, taken here from the
     * authenticated device, so a badge can only name an employee of the
     * device's own tenant. An ambiguous or unknown badge is not guessed at.
     */
    public function employee(Device $device, string $badge): ?Employee
    {
        $signals = IdentitySignals::forDeviceUser($device, $badge);

        $match = $this->resolver->resolve($device->tenant_id, $signals);

        if (! $match->isActionable() || $match->employee === null) {
            return null;
        }

        $this->resolver->link($match->employee, $signals, $match->confidence);

        return $match->employee;
    }

    /**
     * Hand one punch to the engine, dated by the event's own time: a push is
     * delayed ingestion, so `occurredAt` is required (see AttendanceInput).
     *
     * @throws AttendanceRefused when the time cannot be read or is in the future
     */
    public function record(Device $device, Employee $employee, string $type, string $idempotencyKey, mixed $occurredAt): void
    {
        // Read in the device's zone when the device states none (see
        // DeviceTime), and refused when the device clock runs ahead: a punch
        // cannot be dated after it reached us. The clock is noted first, so a
        // refused punch still records how far ahead the device is.
        $idempotencyKey = DevicePunchKey::fit($idempotencyKey);
        $moment = DeviceTime::normalize((string) $occurredAt, $device);
        $offset = $moment === null ? null : DeviceClock::observe($device, $moment);

        if ($moment === null) {
            throw new AttendanceRefused(__('attendance.device_clock_invalid'), 'no_time');
        }

        if (DeviceTime::isInFuture($moment)) {
            throw new AttendanceRefused(__('attendance.device_clock_invalid'), 'clock_ahead');
        }

        $this->engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $device->tenant_id,
            source: AttendanceSource::BIOMETRIC,
            type: $type,
            idempotencyKey: $idempotencyKey,
            deviceId: $device->id,
            occurredAt: $moment,
            metadata: $offset === null ? [] : ['device_clock_offset' => $offset],
        ));
    }
}

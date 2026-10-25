<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Attendance\Device\DeviceVerdict;

/**
 * A punch refused because it did not come from the employee's registered
 * phone, under the organisation's `device_binding: enforce`. Rendered as
 * problem+json with a stable type per reason (bootstrap/app.php), so the
 * phone can say exactly what to do: register, wait for approval, use your
 * own phone, or open the organisation's own address.
 */
final class DeviceBindingRefused extends AttendanceRefused
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $canonicalUrl = null,
    ) {
        parent::__construct(__('attendance.'.str_replace('-', '_', $type)));
    }

    public static function from(DeviceVerdict $verdict): self
    {
        return new self($verdict->refusal() ?? 'device-proof-invalid', $verdict->canonicalUrl);
    }
}

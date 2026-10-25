<?php

declare(strict_types=1);

namespace App\Services\Attendance\Device;

use App\Models\AttendanceDevice;

/**
 * What the device gate found for one punch. `verified` is a fresh signature
 * from the employee's active passkey; `off`, `exempt` and `office_pc` are
 * allowed without one by the organisation's own rules; everything else is a
 * punch from a phone or browser that is not the employee's registered device.
 */
final readonly class DeviceVerdict
{
    public const OFF = 'off';

    public const VERIFIED = 'verified';

    public const EXEMPT = 'exempt';

    public const OFFICE_PC = 'office_pc';

    public const UNREGISTERED = 'unregistered';

    public const PENDING = 'pending';

    public const FOREIGN = 'foreign';

    public const INVALID = 'invalid';

    public const WRONG_HOST = 'wrong_host';

    public function __construct(
        public string $status,
        public string $binding = 'off',
        public ?AttendanceDevice $device = null,
        public bool $installChanged = false,
        public ?string $canonicalUrl = null,
    ) {}

    /** Allowed to punch without refusal under every binding. */
    public function allowed(): bool
    {
        return in_array($this->status, [self::OFF, self::VERIFIED, self::EXEMPT, self::OFFICE_PC], true);
    }

    /** The refusal this verdict becomes under `enforce`, null when it is allowed. */
    public function refusal(): ?string
    {
        return match ($this->status) {
            self::UNREGISTERED => 'device-not-registered',
            self::PENDING => 'device-pending-approval',
            self::FOREIGN => 'device-belongs-to-another',
            self::INVALID => 'device-proof-invalid',
            self::WRONG_HOST => 'device-wrong-host',
            default => null,
        };
    }

    /**
     * What is kept on the punch: the verdict, and whether the passkey came
     * from a different install than it was registered on.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        if ($this->status === self::OFF) {
            return [];
        }

        return array_filter([
            'device_verdict' => $this->status,
            'device_public_id' => $this->device?->public_id,
            'device_install_changed' => $this->installChanged ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

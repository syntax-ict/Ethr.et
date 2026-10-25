<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Services\Attendance\Device\DeviceVerdict;

final readonly class AttendanceInput
{
    public function __construct(
        public int $employeeId,
        public int $tenantId,
        public AttendanceSource $source,
        public string $type,
        public ?string $idempotencyKey = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $photoPath = null,
        public ?int $deviceId = null,
        public ?string $offlineToken = null,
        public ?string $ipAddress = null,
        public array $metadata = [],
        /**
         * When the punch actually happened. Null means "now" — the correct
         * default for a live check-in. Delayed-ingestion paths (device polling,
         * webhooks, offline sync) MUST pass the real event time so the record is
         * dated when it occurred, not when it reached the server. A parseable
         * timestamp string; the engine resolves it via Carbon.
         */
        public ?string $occurredAt = null,
        /**
         * What the device gate found for a self-service punch: whether it came
         * from the employee's registered phone (AttendanceDeviceGate). Null for
         * sources that never carry one.
         */
        public ?DeviceVerdict $device = null,
    ) {}

    /** The same punch with extra metadata. */
    public function withMetadata(array $extra): self
    {
        return new self(
            $this->employeeId, $this->tenantId, $this->source, $this->type, $this->idempotencyKey,
            $this->latitude, $this->longitude, $this->photoPath, $this->deviceId, $this->offlineToken,
            $this->ipAddress, [...$this->metadata, ...$extra], $this->occurredAt, $this->device,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;

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
    ) {}
}

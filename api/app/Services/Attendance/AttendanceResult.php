<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;

final readonly class AttendanceResult
{
    public function __construct(
        public AttendanceRecord $record,
        public bool $wasDuplicate = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AttendanceRecord $record,
    ) {}
}

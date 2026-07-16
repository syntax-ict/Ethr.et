<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AttendanceRecord;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceRecorded implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AttendanceRecord $record,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->record->tenant_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'employee_id' => $this->record->employee?->public_id,
            'date' => $this->record->date,
            'status' => $this->record->status,
        ];
    }
}

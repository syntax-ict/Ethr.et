<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Employee;
use App\Models\EmployeeTransition;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmployeeTransitioned implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Employee $employee,
        public readonly EmployeeTransition $transition,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->employee->tenant_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'employee_id' => $this->employee->public_id,
            'from_status' => $this->transition->from_status,
            'to_status' => $this->transition->to_status,
        ];
    }
}

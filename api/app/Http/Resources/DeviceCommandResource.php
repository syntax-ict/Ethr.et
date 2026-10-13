<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeviceCommand */
class DeviceCommandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'device_user_id' => $this->payload['device_user_id'] ?? null,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee === null ? null : [
                'public_id' => $this->employee->public_id,
                'name' => $this->employee->name,
            ]),
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}

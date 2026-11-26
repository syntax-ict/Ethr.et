<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeviceSyncLog;
use App\Support\DeviceErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeviceSyncLog */
class DeviceSyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'events_found' => $this->events_found,
            'events_processed' => $this->events_processed,
            'events_failed' => $this->events_failed,
            'error_message' => $this->error_message,
            'error_code' => $this->status === 'success' ? null : DeviceErrorCode::from($this->error_message, $this->adapterType()),
            'duration_ms' => $this->duration_ms,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The device's adapter, when the caller handed the device over; never a
     * query per row. Without it no code is vendor-specific.
     */
    private function adapterType(): ?string
    {
        return $this->resource->relationLoaded('device') ? $this->resource->device?->adapter_type : null;
    }
}

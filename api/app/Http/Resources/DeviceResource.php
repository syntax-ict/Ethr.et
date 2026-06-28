<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'serial_number' => $this->serial_number,
            'adapter_type' => $this->adapter_type,
            'status' => $this->status,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'branch_public_id' => $this->branch?->public_id,
            'attendance_records_count' => $this->whenCounted('attendanceRecords'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

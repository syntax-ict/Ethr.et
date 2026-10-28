<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceDevice */
class AttendanceDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => (string) $this->status,
            'label' => $this->label,
            'synced_passkey' => (bool) $this->backup_state,
            'enrolled_inside_geofence' => (bool) $this->enrolled_inside_geofence,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'replaces' => $this->when(
                $this->status === AttendanceDevice::PENDING && $this->relationLoaded('replacing'),
                fn () => $this->replacing === null ? null : [
                    'label' => $this->replacing->label,
                    'synced_passkey' => (bool) $this->replacing->backup_state,
                    'created_at' => $this->replacing->created_at?->toIso8601String(),
                    'last_used_at' => $this->replacing->last_used_at?->toIso8601String(),
                ],
            ),
            'employee' => $this->whenLoaded('employee', fn () => [
                'public_id' => (string) $this->employee->public_id,
                'name' => (string) $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ]),
        ];
    }
}

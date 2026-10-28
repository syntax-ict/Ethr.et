<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class UnregisteredEmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => (string) $this->public_id,
            'name' => (string) $this->name,
            'employee_code' => $this->employee_code,
            'branch' => $this->branch?->name,
            'has_pending_phone' => (bool) $this->getAttribute('has_pending_phone'),
            'exempt_until' => $this->attendance_device_exempt_until?->format('Y-m-d'),
        ];
    }
}

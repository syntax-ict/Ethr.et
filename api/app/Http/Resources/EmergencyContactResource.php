<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmployeeEmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeEmergencyContact */
class EmergencyContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'phone' => $this->phone,
            'email' => $this->email,
            'priority' => $this->priority,
        ];
    }
}

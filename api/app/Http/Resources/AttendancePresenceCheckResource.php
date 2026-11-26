<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendancePresenceCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendancePresenceCheck */
class AttendancePresenceCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => match ($this->status) {
                'confirmed' => 'confirmed',
                'outside' => 'outside',
                'unverified' => 'unverified',
                'missed' => 'missed',
                default => 'sent',
            },
            'asked_at' => $this->sent_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'distance_meters' => $this->distance_meters,
            'late' => (bool) ($this->answered_at !== null && $this->answered_at->gt($this->expires_at)),
        ];
    }
}

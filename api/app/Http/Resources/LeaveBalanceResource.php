<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeaveBalance;
use App\Services\Leave\SickLeaveTiers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveBalance */
class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'leave_type' => new LeaveTypeResource($this->whenLoaded('leaveType')),
            'leave_type_public_id' => $this->leaveType?->public_id,
            'year' => $this->year,
            'entitled_days' => (float) $this->entitled_days,
            'used_days' => (float) $this->used_days,
            'carried_days' => (float) $this->carried_days,
            'pending_days' => (float) $this->pending_days,
            'remaining_days' => $this->remainingDays(),
            'sick_leave' => $this->when($this->followsArt85(), fn () => $this->sickLeave()),
        ];
    }

    /**
     * An Art. 85 type is six months within twelve, not this year's
     * entitlement, so the balance says where the person stands (K-50). Needs
     * the employee loaded; lazy loading is off.
     */
    private function followsArt85(): bool
    {
        return $this->leaveType?->follows_art85 === true && $this->relationLoaded('employee');
    }

    /**
     * @return array{days_taken: int, days_allowed: int, period_start: string|null, period_end: string|null, next_tier: string}
     */
    private function sickLeave(): array
    {
        return app(SickLeaveTiers::class)->standingToday($this->employee);
    }
}

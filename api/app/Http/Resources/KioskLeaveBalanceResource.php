<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leave balance, as kiosk self-service shows it: the days, and the leave
 * type's name in both languages, without the type's configuration.
 *
 * @mixin LeaveBalance
 */
class KioskLeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->leaveType;

        return [
            'leave_type' => $type?->name,
            'leave_type_am' => $type?->name_am,
            'entitled_days' => (float) $this->entitled_days + (float) $this->carried_days,
            'used_days' => (float) $this->used_days,
            'pending_days' => (float) $this->pending_days,
            'remaining_days' => $this->remainingDays(),
        ];
    }
}

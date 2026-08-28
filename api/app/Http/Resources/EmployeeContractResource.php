<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmployeeContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin EmployeeContract */
class EmployeeContractResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $endDate = $this->end_date === null ? null : Carbon::parse($this->end_date);
        $active = $this->status->value === 'active';

        return [
            'public_id' => $this->public_id,
            'reference_number' => $this->reference_number,
            'contract_type' => $this->contract_type->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $endDate?->format('Y-m-d'),
            'salary_cents' => $this->salary_cents,
            'terms' => $this->terms,
            'status' => $this->status->value,
            'renewed_from_id' => $this->whenLoaded(
                'renewedFrom',
                fn () => $this->renewedFrom?->public_id,
            ),
            'ended_at' => $this->ended_at?->toDateString(),
            'end_notes' => $this->end_notes,
            // Same S12 badge rule as EmployeeDocumentResource's expiry fields —
            // amber within 30 days, red once past end date. Only meaningful
            // while the contract is still active; a renewed/ended one has
            // already been superseded, not "expiring soon".
            'is_expired' => $active && $endDate !== null && $endDate->isPast(),
            'expires_soon' => $active && $endDate !== null && $endDate->isFuture()
                && $endDate->lessThanOrEqualTo(now()->addDays(30)),
            'days_until_expiry' => $active && $endDate !== null
                ? (int) now()->startOfDay()->diffInDays($endDate, false)
                : null,
            // Populated only on the tenant-wide expiring watchlist, where the
            // reader needs to know whose contract is lapsing.
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee->name),
            'employee_public_id' => $this->whenLoaded('employee', fn () => $this->employee->public_id),
            'created_at' => $this->created_at,
        ];
    }
}

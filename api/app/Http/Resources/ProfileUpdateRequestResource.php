<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfileUpdateRequest */
class ProfileUpdateRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'field_name' => $this->field_name,
            // Both values are shown: a reviewer approving a bank-account change is
            // deciding on the delta, and cannot judge it from the new value alone.
            // Account numbers are masked unless the viewer holds the financial
            // permission — `employee.update` is enough to review a name change but
            // does not by itself carry the right to read bank details in full.
            'old_value' => $this->maskIfNeeded($request, $this->old_value),
            'new_value' => $this->maskIfNeeded($request, $this->new_value),
            'status' => $this->status->value,
            'employee_public_id' => $this->employee?->public_id,
            'employee_name' => $this->employee?->name,
            'requested_by_name' => $this->displayName($this->requester),
            'reviewed_by_name' => $this->displayName($this->reviewer),
            'reviewed_at' => $this->reviewed_at,
            'review_notes' => $this->review_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * `users` carries no display name of its own — every name in the product is
     * read through the linked Employee — so fall back to the login email for an
     * account with no employee record (an HR-only login, say).
     */
    private function displayName(?User $user): ?string
    {
        // loadMissing rather than a bare relation read: `preventLazyLoading` is on
        // outside production, so a caller that forgot to eager-load would 500 here
        // instead of just costing a query.
        $user?->loadMissing('employee');
        $employee = $user?->employee;

        return $employee instanceof Employee ? $employee->name : $user?->email;
    }

    /**
     * Show the last four digits only, unless the viewer is the employee whose
     * account it is or holds `employee.viewFinancial`.
     */
    private function maskIfNeeded(Request $request, ?string $value): ?string
    {
        if ($value === null || $this->field_name !== 'bank_account_number') {
            return $value;
        }

        $user = $request->user();
        // Compare the foreign key directly — no relation read, so this cannot trip
        // `preventLazyLoading` or cost a query per row.
        $isOwner = $user?->employee_id !== null
            && $user->employee_id === $this->resource->employee_id;

        if ($isOwner || $user?->hasPermission('employee.viewFinancial')) {
            return $value;
        }

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }
}

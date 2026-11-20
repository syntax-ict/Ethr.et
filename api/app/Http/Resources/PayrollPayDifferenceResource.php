<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayrollPayDifference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollPayDifference */
class PayrollPayDifferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'employee_public_id' => $this->employee?->public_id,
            'employee_name' => $this->employee?->name,
            'run_public_id' => $this->payrollRun?->public_id,
            'period_label' => $this->payrollRun?->period_label,
            'period_start' => $this->payrollRun?->period_start?->format('Y-m-d'),
            'period_end' => $this->payrollRun?->period_end?->format('Y-m-d'),
            'kind' => $this->kind,
            'gross_cents' => $this->gross_cents,
            'net_cents' => $this->net_cents,
            'paid_gross_cents' => $this->paid_gross_cents,
            'paid_net_cents' => $this->paid_net_cents,
            'due_gross_cents' => $this->due_gross_cents,
            'due_net_cents' => $this->due_net_cents,
            'status' => $this->status,
            'changed_from' => $this->changed_from->format('Y-m-d'),
            'trail_rebuilt_at_release' => (bool) ($this->detail['trail_rebuilt_at_release'] ?? false),
            'note' => $this->note,
            'settled_at' => $this->settled_at,
            'created_at' => $this->created_at,
        ];
    }
}

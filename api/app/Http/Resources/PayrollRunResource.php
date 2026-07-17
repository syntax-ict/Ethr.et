<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'period_label' => $this->period_label,
            'period_start' => $this->period_start?->format('Y-m-d'),
            'period_end' => $this->period_end?->format('Y-m-d'),
            'status' => $this->status,
            'employee_count' => $this->employee_count,
            'gross_total_cents' => $this->gross_total_cents,
            'net_total_cents' => $this->net_total_cents,
            'tax_total_cents' => $this->tax_total_cents,
            'entries' => PayrollEntryResource::collection($this->whenLoaded('entries')),
            'processed_at' => $this->processed_at,
            'approved_at' => $this->approved_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'reprocessed_from_public_id' => $this->whenLoaded(
                'reprocessedFrom',
                fn () => $this->reprocessedFrom?->public_id,
            ),
            'created_at' => $this->created_at,
        ];
    }
}

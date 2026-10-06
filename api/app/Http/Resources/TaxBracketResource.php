<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TaxBracket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxBracket */
class TaxBracketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'min_amount_cents' => (int) $this->min_amount_cents,
            // 0 is the open-ended sentinel in storage; the API exposes it as null.
            'max_amount_cents' => $this->max_amount_cents ? (int) $this->max_amount_cents : null,
            'rate' => (float) $this->rate,
            'deduction_cents' => (int) $this->deduction_cents,
            'effective_from' => $this->effective_from->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
        ];
    }
}

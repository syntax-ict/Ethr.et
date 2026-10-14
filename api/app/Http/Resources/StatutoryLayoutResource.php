<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StatutoryLayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StatutoryLayout */
class StatutoryLayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'schedule' => $this->schedule,
            'scheme' => $this->scheme,
            'agency' => $this->agency,
            'title' => $this->title,
            'columns' => $this->columns,
            'note' => $this->note,
            'effective_from' => $this->effective_from->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
        ];
    }
}

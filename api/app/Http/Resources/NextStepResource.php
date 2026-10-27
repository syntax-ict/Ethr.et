<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\NextSteps\NextStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NextStep
 */
class NextStepResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group(),
            'severity' => $this->severity(),
            'count' => $this->count,
            'total' => $this->total,
            'items' => $this->items,
            'subject' => $this->subject,
            'href' => $this->href,
            'signature' => $this->signature,
        ];
    }
}

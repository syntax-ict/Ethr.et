<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\NextSteps\NextStepsResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NextStepsResult
 */
class NextStepsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'steps' => NextStepResource::collection($this->steps),
            'setup' => $this->setup,
        ];
    }
}

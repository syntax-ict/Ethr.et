<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketingSection;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One published section of the landing page with its published rows, as
 * the public site reads it. The controller eager-loads the rows filtered to
 * published, so `$this->items` is read directly rather than through
 * `whenLoaded`, which would type it as optional in the contract.
 *
 * @mixin MarketingSection
 */
class MarketingSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->kind,
            'heading' => $this->heading,
            'heading_am' => $this->heading_am,
            'subheading' => $this->subheading,
            'subheading_am' => $this->subheading_am,
            'image' => $this->image(),
            'items' => MarketingItemResource::collection($this->items),
        ];
    }

    /**
     * @return array{url: string, srcset: string, width: int, height: int, alt: string|null, alt_am: string|null}|null
     */
    private function image(): ?array
    {
        return MarketingImage::present($this->resource);
    }
}

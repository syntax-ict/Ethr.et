<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketingSection;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A landing-page section as the console edits it: every row, published or
 * not, plus the registry entry that says what its rows may carry.
 *
 * @mixin MarketingSection
 */
class AdminMarketingSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'kind' => $this->kind,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'heading' => $this->heading,
            'heading_am' => $this->heading_am,
            'subheading' => $this->subheading,
            'subheading_am' => $this->subheading_am,
            'image' => $this->image(),
            'spec' => $this->spec(),
            'items' => AdminMarketingItemResource::collection($this->items),
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

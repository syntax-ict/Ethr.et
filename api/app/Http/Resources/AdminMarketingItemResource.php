<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketingItem;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A landing-page row as the console edits it: the public fields plus the
 * flags the public resource hides.
 *
 * @mixin MarketingItem
 */
class AdminMarketingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'slug' => $this->slug,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'icon' => $this->icon,
            'title' => $this->title,
            'title_am' => $this->title_am,
            'body' => $this->body,
            'body_am' => $this->body_am,
            'detail' => $this->detail,
            'detail_am' => $this->detail_am,
            'link_path' => $this->link_path,
            'image' => $this->image(),
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

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketingItem;
use App\Support\MarketingImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One published row of a landing-page section, as the public site reads it.
 *
 * A whitelist: no numeric id, no publication flag, no sort order. The order
 * is the order of the list.
 *
 * @mixin MarketingItem
 */
class MarketingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'slug' => $this->slug,
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

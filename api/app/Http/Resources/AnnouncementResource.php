<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Services\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category,
            'priority' => $this->priority,
            'target_type' => $this->target_type,
            'status' => $this->status(),
            'published_at' => $this->published_at,
            'expires_at' => $this->expires_at,
            'show_on_landing' => (bool) $this->show_on_landing,
            'featured' => (bool) $this->featured,
            'cover' => $this->resource->coverPresented((string) app(CurrentTenant::class)->publicId()),
            'attachments' => $this->resource->attachmentsPresented(),
            'created_at' => $this->created_at,
        ];
    }
}

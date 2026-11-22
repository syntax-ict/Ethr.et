<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\PlainExcerpt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Who wrote it, from the eager-loaded relation only. A story whose
        // author's account has since been deleted keeps the story; it just
        // has no name against it.
        //
        // `User::name` falls back to the login email for an account with no
        // employee record and no display name, which every administrator who
        // registered before 2026-10-30 still is. The whole audience reads this
        // line, and a login address is not a name, so no name is shown rather
        // than theirs.
        $author = $this->whenLoaded('publisher');
        $authorName = $author instanceof User ? trim((string) $author->name) : '';

        if ($author instanceof User && $authorName === (string) $author->email) {
            $authorName = '';
        }

        return [
            'public_id' => $this->public_id,
            'title' => $this->title,
            'body' => $this->body,
            'excerpt' => PlainExcerpt::of($this->body, 280),
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
            'author' => $authorName === '' ? null : ['name' => $authorName],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

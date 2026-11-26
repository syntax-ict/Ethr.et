<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\Story;
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
            ...Story::summary($this->resource, (string) app(CurrentTenant::class)->publicId()),
            'title' => $this->title,
            'body' => Story::body($this->resource),
            'body_source' => $request->user()?->hasPermission('announcement.manage') ? $this->body : null,
            'target_type' => $this->target_type,
            'status' => $this->status(),
            'expires_at' => $this->expires_at,
            'show_on_landing' => (bool) $this->show_on_landing,
            'attachments' => $this->resource->attachmentsPresented(),
            'author' => $authorName === '' ? null : ['name' => $authorName],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

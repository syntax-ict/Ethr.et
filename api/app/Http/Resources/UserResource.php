<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Support\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $access = app(PositionAccess::class);

        // What following the job title would do, for the Users page to say
        // before it does it. `applies` is whether the signed-in person could
        // give that role by hand, so a held change is announced as held.
        $preview = $access->previewRole($this->resource);
        $actor = $request->user();
        $titlePreview = $preview === null ? null : [
            'role' => $preview->value,
            'position' => $this->employee?->position?->title,
            'applies' => (bool) ($this->role === $preview
                || ($actor instanceof User && RoleAssignment::mayChange($actor, $this->resource, $preview))),
        ];

        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_follows_position' => (bool) $this->role_follows_position,
            'title_role' => $access->titleRole($this->resource)?->value,
            'title_preview' => $titlePreview,
            'status' => $this->status,
            'locale' => $this->locale,
            'mfa_enabled' => (bool) $this->mfa_enabled,
            'invited_at' => $this->invited_at,
            'activated_at' => $this->activated_at,
            'last_login_at' => $this->last_login_at,
            'custom_role' => $this->whenLoaded('customRole', fn () => $this->customRole ? [
                'public_id' => $this->customRole->public_id,
                'name' => $this->customRole->name,
            ] : null),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'public_id' => $this->employee->public_id,
                'name' => $this->employee->name,
            ] : null),
            'last_invitation' => $this->whenLoaded('latestInvitation', fn () => $this->latestInvitation ? [
                'channel' => $this->latestInvitation->channel,
                'status' => $this->latestInvitation->status,
                'reminder' => $this->latestInvitation->reminder,
                'queued_at' => $this->latestInvitation->queued_at,
                'sent_at' => $this->latestInvitation->sent_at,
                'held_until' => $this->latestInvitation->held_until,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'action' => $this->action,
            'auditable_type' => $this->auditable_type,
            'auditable_public_id' => $this->subjectPublicId(),
            'user' => $this->actor(),
            'organisation' => $this->organisation(),
            'data' => $this->payload,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Set by `AuditSubjects::attach()`; null when it was not called, or the
     * subject has no public id or no longer resolves.
     */
    private function subjectPublicId(): ?string
    {
        if (! $this->resource->relationLoaded('auditable')) {
            return null;
        }

        $id = $this->resource->getRelation('auditable')?->getAttribute('public_id');

        return is_string($id) ? $id : null;
    }

    /**
     * Set by `AuditSubjects::attachOrganisations()`, which only the platform
     * view calls: a tenant's own trail is all one organisation, so it is null
     * there, as it is for a platform action.
     *
     * @return array{public_id: string, name: string}|null
     */
    private function organisation(): ?array
    {
        if (! $this->resource->relationLoaded('organisation')) {
            return null;
        }

        $tenant = $this->resource->getRelation('organisation');

        return $tenant === null ? null : [
            'public_id' => (string) $tenant->public_id,
            'name' => (string) $tenant->name,
        ];
    }

    /**
     * @return array{public_id: string, name: string}|null
     */
    private function actor(): ?array
    {
        if (! $this->resource->relationLoaded('user')) {
            return null;
        }

        $user = $this->resource->getRelation('user');

        return $user === null ? null : [
            'public_id' => (string) $user->public_id,
            'name' => (string) $user->name,
        ];
    }
}

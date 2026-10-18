<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One organisation on ETHR's "Trusted by" list: its name, its uploaded logo
 * and where its public page is. Nothing else about it is published.
 *
 * @mixin Tenant
 */
class TrustedOrganisationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'logo_url' => $this->logoUrl(),
            'url' => $this->publicUrl(),
        ];
    }

    /**
     * The organisation's public page, as PortalHead publishes it: its entry
     * address, ethr.et/{slug} on the shared host or its own host.
     */
    private function publicUrl(): string
    {
        return FrontendUrl::at($this->resource, 'login');
    }
}

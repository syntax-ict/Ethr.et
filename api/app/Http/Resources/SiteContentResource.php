<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the public marketing site is allowed to know about ETHR.
 *
 * @mixin PlatformSetting
 */
class SiteContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // A projection, not the row. `platform_settings` also holds the bank
        // account every tenant pays into, and this endpoint is unauthenticated
        // — so the fields are listed here rather than excluded elsewhere,
        // because a whitelist fails closed when a column is added and a
        // blacklist does not.
        //
        // The metrics are null until an operator publishes one. The landing
        // page claims "500+ organisations" and "99.9% uptime" today and nothing
        // can substantiate either; null renders nothing, which is the honest
        // state and the reason these became columns.
        return [
            'platform_name' => $this->platform_name,
            'platform_name_am' => $this->platform_name_am,
            'tagline' => $this->tagline,
            'tagline_am' => $this->tagline_am,
            'logo_url' => $this->logo_url,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'office_address' => $this->office_address,
            'office_address_am' => $this->office_address_am,
            'social_linkedin' => $this->social_linkedin,
            'social_x' => $this->social_x,
            'social_facebook' => $this->social_facebook,
            'metric_organisations' => $this->metric_organisations,
            'metric_employees' => $this->metric_employees,
            'metric_uptime_note' => $this->metric_uptime_note,
            'metric_uptime_note_am' => $this->metric_uptime_note_am,
        ];
    }
}

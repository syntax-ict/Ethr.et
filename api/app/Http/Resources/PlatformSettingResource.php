<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformSetting
 */
class PlatformSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'bank_name' => $this->bank_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_account_name' => $this->bank_account_name,
            'payment_instructions' => $this->payment_instructions,
            'payment_instructions_am' => $this->payment_instructions_am,
            'is_configured' => $this->hasPaymentDetails(),

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
            'testimonial_quote' => $this->testimonial_quote,
            'testimonial_quote_am' => $this->testimonial_quote_am,
            'testimonial_author' => $this->testimonial_author,
            'testimonial_role' => $this->testimonial_role,
            'testimonial_role_am' => $this->testimonial_role_am,
            'testimonial_organisation' => $this->testimonial_organisation,
            // Published here and not by SiteContentResource: the operator has to
            // see and edit the consent date, a visitor has no use for it, and
            // this endpoint is behind admin.manage while that one is open.
            'testimonial_consented_on' => $this->testimonial_consented_on,
            'has_published_metrics' => $this->hasPublishedMetrics(),

            'updated_at' => $this->updated_at,
        ];
    }
}

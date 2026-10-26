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
            ...$this->testimonial(),
            ...$this->productMark(),
        ];
    }

    /**
     * The "Powered by ETHR" mark every organisation's sidebar carries.
     *
     * Published here, on the endpoint every host already reads, because it is
     * platform data that each organisation displays and none of them owns: the
     * operator decides it in the console, and nothing on a tenant's own
     * settings can write these columns. A null label or link means the
     * built-in wording and the public site, resolved by the client so that
     * the wording follows the reader's language.
     *
     * @return array{product_mark_enabled: bool, product_mark_label: string|null, product_mark_label_am: string|null, product_mark_url: string|null}
     */
    private function productMark(): array
    {
        return [
            'product_mark_enabled' => (bool) $this->product_mark_enabled,
            'product_mark_label' => $this->product_mark_label,
            'product_mark_label_am' => $this->product_mark_label_am,
            'product_mark_url' => $this->product_mark_url,
        ];
    }

    /**
     * The customer quote, or five nulls.
     *
     * All or nothing, decided by the model rather than by the caller: a quote
     * with no attribution is an anonymous claim, and a quote nobody recorded
     * consent for is one that cannot be shown to have been given. The landing
     * page renders this section only when the quote is present, so refusing to
     * publish a half-filled one here is what keeps a partially-entered draft
     * off the public site.
     *
     * `testimonial_consented_on` is deliberately absent from the payload. It is
     * provenance, not content — a visitor has no use for it, and this endpoint
     * is unauthenticated, so anything that does not need to be public is not.
     *
     * @return array<string, string|null>
     */
    private function testimonial(): array
    {
        $publishable = $this->hasPublishedTestimonial();

        return [
            'testimonial_quote' => $publishable ? $this->testimonial_quote : null,
            'testimonial_quote_am' => $publishable ? $this->testimonial_quote_am : null,
            'testimonial_author' => $publishable ? $this->testimonial_author : null,
            'testimonial_role' => $publishable ? $this->testimonial_role : null,
            'testimonial_role_am' => $publishable ? $this->testimonial_role_am : null,
            'testimonial_organisation' => $publishable ? $this->testimonial_organisation : null,
        ];
    }
}

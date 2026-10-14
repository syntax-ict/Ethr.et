<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\CurrentTenant;
use App\Support\TenantLanding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The organisation's portal: its own words in both languages, what it says
 * about itself, how to reach it, its links, which sections show and in what
 * order, and whether search engines may list the page. Everything here is
 * shown publicly, before sign-in.
 *
 * The photo background draws the cover the organisation uploaded through
 * `POST /settings/branding/assets/cover`; choosing it before a cover exists
 * is refused in withValidator(). The network links are checked against the
 * network's own host there too, with TenantLanding::linkUrl(), the same test
 * the public side applies before serving. Scramble does not read
 * withValidator(), so the contract stays a plain rule set.
 */
class UpdateLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:80'],
            'headline_am' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:280'],
            'message_am' => ['nullable', 'string', 'max:280'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'support_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,24}$/'],
            'background' => ['required', 'string', 'in:brand,gradient,pattern,image'],
            'about' => ['nullable', 'string', 'max:600'],
            'about_am' => ['nullable', 'string', 'max:600'],
            'founded_year' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
            'sector' => ['nullable', 'string', 'in:'.implode(',', TenantLanding::SECTORS)],
            'staff_band' => ['nullable', 'string', 'in:'.implode(',', TenantLanding::STAFF_BANDS)],
            'show_branch_count' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:200'],
            'address_am' => ['nullable', 'string', 'max:200'],
            'hours' => ['nullable', 'string', 'max:120'],
            'hours_am' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'show_branches' => ['sometimes', 'boolean'],
            'links' => ['sometimes', 'array:'.implode(',', TenantLanding::LINKS)],
            'links.website' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.linkedin' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.facebook' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.x' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.telegram' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.youtube' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.privacy' => ['nullable', 'string', 'url:https', 'max:500'],
            'links.terms' => ['nullable', 'string', 'url:https', 'max:500'],
            'sections' => ['sometimes', 'array:'.implode(',', array_keys(TenantLanding::SECTIONS))],
            'sections.about' => ['sometimes', 'boolean'],
            'sections.announcements' => ['sometimes', 'boolean'],
            'sections.vacancies' => ['sometimes', 'boolean'],
            'sections.holidays' => ['sometimes', 'boolean'],
            'sections.contact' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'array', 'max:5'],
            'order.*' => ['string', 'distinct', 'in:'.implode(',', TenantLanding::ORDER)],
            'listed' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('background') === 'image'
                && ! $validator->errors()->has('background')
                && app(CurrentTenant::class)->get()->brandAsset('cover') === null) {
                $validator->errors()->add('background', __('validation.landing_cover_missing'));
            }

            foreach (TenantLanding::LINK_HOSTS as $link => $hosts) {
                $value = $this->input("links.{$link}");
                if (is_string($value) && $value !== '' && ! $validator->errors()->has("links.{$link}")
                    && TenantLanding::linkUrl($link, $value) === null) {
                    $validator->errors()->add("links.{$link}", __('validation.landing_link_host', ['hosts' => implode(', ', $hosts)]));
                }
            }
        });
    }
}

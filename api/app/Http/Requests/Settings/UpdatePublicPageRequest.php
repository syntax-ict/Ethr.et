<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\TenantPublicProfile;
use App\Rules\PublicUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a tenant's public landing page content.
 *
 * Stricter than the columns' storage types, because everything here is rendered
 * to anonymous visitors. Two rules carry the weight:
 *
 *  - every URL goes through PublicUrl, which admits only http(s) and refuses
 *    anything resolving to a private address. A `javascript:` value in
 *    `website_url` would otherwise become a link the page's own visitors click.
 *  - `social_links` is a fixed key set with a per-platform host allow-list, so
 *    a "social link" cannot be an arbitrary outbound URL wearing a familiar
 *    label.
 *
 * Authorization is not done here. SettingsController calls
 * Gate::authorize('settings.manage'), the same gate every other settings
 * endpoint uses, so the rule lives in one place rather than two.
 */
class UpdatePublicPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'is_published' => ['sometimes', 'boolean'],
            'is_indexable' => ['sometimes', 'boolean'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            // Plain text. There is no rich-text editor and the template renders
            // paragraphs rather than markup — a cap generous for prose and well
            // short of somewhere to paste a document.
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:255', new PublicUrl],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'social_links' => ['sometimes', 'nullable', 'array', $this->rejectUnknownPlatforms()],
        ];

        foreach (TenantPublicProfile::SOCIAL_PLATFORMS as $platform => $hosts) {
            $rules["social_links.{$platform}"] = [
                'nullable', 'string', 'max:255', new PublicUrl($hosts),
            ];
        }

        return $rules;
    }

    /**
     * Reject an unrecognised platform key rather than dropping it.
     *
     * Laravel would simply not validate `social_links.myspace`, and the
     * controller only persists known keys — so the value would vanish with no
     * error and an administrator would watch their link fail to save with no
     * explanation. Failing loudly is the kinder behaviour and the safer one.
     */
    private function rejectUnknownPlatforms(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $unknown = array_diff(
                array_keys($value),
                array_keys(TenantPublicProfile::SOCIAL_PLATFORMS)
            );

            if ($unknown !== []) {
                $fail(__('Unsupported social platform: :platforms.', [
                    'platforms' => implode(', ', $unknown),
                ]));
            }
        };
    }

    /**
     * Whether this request asks to change the published state.
     *
     * Publishing is the one field here with a consequence outside the
     * application — everything else changes a page only its own administrators
     * can see until this flag is true. The controller uses this to write a
     * distinct audit entry for the transition rather than folding it into a
     * generic "public page updated".
     */
    public function changesPublication(): bool
    {
        return $this->has('is_published');
    }

    public function wantsPublished(): bool
    {
        return $this->boolean('is_published');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    /**
     * Validation is the registry here: there is no key/value store to guard, so
     * what these rules accept is the whole of what an operator can put on the
     * public site.
     *
     * Notes are kept out of the rule array because Scramble turns a comment
     * beside a rule into that field's published API description:
     *
     *   logo_url, social_*  `url:https` rather than `url`. Every consumer
     *                       renders these straight into an <img src> or an
     *                       <a href> on an HTTPS page, so an http:// value is
     *                       a mixed-content warning at best and a blocked
     *                       image at worst. The CSP already allows
     *                       `img-src https:`.
     *   metric_*            Nullable with no default. "No figure published"
     *                       and "zero customers" are different statements, and
     *                       these columns exist so the first one is
     *                       expressible — the landing page's "500+
     *                       organisations" is currently neither.
     *   metric_uptime_note  Free text, not a percentage. The honest answer is
     *                       that no SLA exists, which the terms page already
     *                       says; a number column invites 99.9 back.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['nullable', 'string', 'max:255'],
            // Ethiopian bank account numbers are digits, commonly written with
            // spaces or dashes for readability. Anything else is a typo on a field
            // that decides where customers send money, so reject it rather than
            // silently storing it.
            'bank_account_number' => ['nullable', 'string', 'max:50', 'regex:/^[0-9][0-9 \-]*[0-9]$/'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            'payment_instructions_am' => ['nullable', 'string', 'max:2000'],

            'platform_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platform_name_am' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:300'],
            'tagline_am' => ['sometimes', 'nullable', 'string', 'max:300'],

            'logo_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],

            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'office_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'office_address_am' => ['sometimes', 'nullable', 'string', 'max:500'],

            'social_linkedin' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'social_x' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'social_facebook' => ['sometimes', 'nullable', 'url:https', 'max:500'],

            'metric_organisations' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'metric_employees' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'metric_uptime_note' => ['sometimes', 'nullable', 'string', 'max:200'],
            'metric_uptime_note_am' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_account_number.regex' => __('validation.custom.bank_account_number.digits'),
        ];
    }
}

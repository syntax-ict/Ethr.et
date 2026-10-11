<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\TenantAddressMode;
use App\Http\Requests\Concerns\RejectsPlatformHosts;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The organisation's choice of address: `path`, `subdomain` or
 * `custom_domain`, and, with the last, the domain itself.
 *
 * `custom_domain` is optional: left out, the domain already assigned stays as
 * it is. Sent, it is normalised as Tenant stores it (scheme, port, path and
 * trailing dot stripped, lower-cased), must not belong to another
 * organisation nor sit under the platform's own domain, and `null` clears it.
 * Assigning one also needs the plan to include the add-on, refused with a 422
 * on this field otherwise. A new domain is stored pending until Verify finds
 * its DNS records; choosing `custom_domain` before then is kept, and links
 * keep using the fallback address until it verifies.
 */
class UpdateAddressRequest extends FormRequest
{
    use RejectsPlatformHosts;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('custom_domain')) {
            $this->merge(['custom_domain' => Tenant::normaliseDomain($this->input('custom_domain'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return [
            'mode' => ['required', Rule::enum(TenantAddressMode::class)],
            'custom_domain' => [
                'sometimes',
                'nullable',
                'string',
                'max:253',
                'regex:'.Tenant::CUSTOM_DOMAIN_REGEX,
                Rule::unique('tenants', 'custom_domain')->ignore($tenant?->id),
            ],
        ];
    }
}

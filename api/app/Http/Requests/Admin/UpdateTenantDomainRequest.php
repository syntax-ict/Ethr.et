<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\RejectsPlatformHosts;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A bare hostname such as `hr.acme.com`, or `null` to clear it.
 *
 * A scheme, port, path or trailing dot is stripped and the name lower-cased
 * before it is checked. The domain must not already belong to another
 * organisation, nor sit under the platform's own domain. The field is
 * required: send `null` explicitly to clear it.
 *
 * Assigning a domain also needs the organisation's plan to include
 * `custom_domain`, and is refused with a 422 on this field otherwise. A new
 * domain is stored pending until the Verify action finds its DNS records.
 */
class UpdateTenantDomainRequest extends FormRequest
{
    use RejectsPlatformHosts;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalised exactly as Tenant's mutator stores it, so `HR.Acme.com` and
        // `hr.acme.com` are one domain to the uniqueness rule as well as to
        // ResolveTenant. Raw input would let two tenants hold one domain in two
        // spellings until the unique index refused the second with a 500.
        if ($this->has('custom_domain')) {
            $this->merge(['custom_domain' => Tenant::normaliseDomain($this->input('custom_domain'))]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'custom_domain' => [
                'present',
                'nullable',
                'string',
                'max:253',
                'regex:'.Tenant::CUSTOM_DOMAIN_REGEX,
                'unique:tenants,custom_domain,'.$this->route('publicId').',public_id',
            ],
        ];
    }

    // The platform-host refusal is RejectsPlatformHosts, shared with the
    // organisation's own UpdateAddressRequest.
}

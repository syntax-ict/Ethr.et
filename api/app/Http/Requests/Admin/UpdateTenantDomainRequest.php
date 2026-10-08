<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Tenant;
use App\Support\TenancyDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/',
                'unique:tenants,custom_domain,'.$this->route('publicId').',public_id',
            ],
        ];
    }

    /**
     * A host this deployment already owns can never be a custom domain.
     *
     * ResolveTenant consults custom domains only for hosts outside APP_DOMAIN,
     * so `ethr.et`, `admin.ethr.et` or `habru.ethr.et` assigned here would
     * never resolve, while FrontendUrl would still put it in every link the
     * tenant is sent. A hook rather than a rule, so `rules()` and the API
     * contract Scramble derives from it stay plain.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $root = TenancyDomain::root();
            $domain = $this->input('custom_domain');

            if ($root === null || ! is_string($domain) || $validator->errors()->has('custom_domain')) {
                return;
            }

            if ($domain === $root || str_ends_with($domain, '.'.$root)) {
                $validator->errors()->add('custom_domain', __('validation.custom_domain_reserved', ['root' => $root]));
            }
        });
    }
}

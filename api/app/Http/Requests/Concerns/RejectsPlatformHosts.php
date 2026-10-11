<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\TenancyDomain;
use Illuminate\Validation\Validator;

/**
 * A host this deployment already owns can never be a custom domain.
 *
 * ResolveTenant consults custom domains only for hosts outside APP_DOMAIN,
 * so `ethr.et`, `admin.ethr.et` or `habru.ethr.et` assigned as one would
 * never resolve, while FrontendUrl would still put it in every link the
 * organisation is sent. A hook rather than a rule, so `rules()` and the API
 * contract Scramble derives from it stay plain.
 */
trait RejectsPlatformHosts
{
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

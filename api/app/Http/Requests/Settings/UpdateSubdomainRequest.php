<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new name for the organisation's address: the `{slug}` in ethr.et/{slug}
 * and {slug}.ethr.et. Lower-cased and trimmed before it is checked.
 *
 * The format and the reserved list are registration's own, so a name an
 * organisation can rename to is exactly a name it could have registered.
 * Availability is checked after the rules: not another organisation's
 * address, deleted or not, and not a name another organisation used to have.
 * A name this organisation retired itself is available to it.
 */
class UpdateSubdomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('subdomain'))) {
            $this->merge(['subdomain' => strtolower(trim($this->input('subdomain')))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'subdomain' => [
                'required', 'string', 'min:3', 'max:63',
                'regex:'.Tenant::SUBDOMAIN_REGEX,
                Rule::notIn(Tenant::RESERVED_SUBDOMAINS),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subdomain.regex' => __('validation.subdomain_format'),
            'subdomain.not_in' => __('validation.subdomain_reserved'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('subdomain')) {
                return;
            }

            $tenant = app(CurrentTenant::class)->get();
            if (! Tenant::slugAvailableFor((string) $this->input('subdomain'), $tenant)) {
                $validator->errors()->add('subdomain', __('validation.subdomain_taken'));
            }
        });
    }
}

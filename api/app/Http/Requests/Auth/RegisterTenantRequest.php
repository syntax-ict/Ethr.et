<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
use App\Rules\PasswordPolicy;
use App\Support\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Canonicalize the admin phone before validation so both the local
     * `09XXXXXXXX` / `07XXXXXXXX` form the UI collects and the E.164
     * `+2519XXXXXXXX` form resolve to a single stored representation. Without
     * this the strict `+251` rule 422'd every registration typed in the local
     * format, which is how most Ethiopian users write their number.
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('admin_phone');

        if (is_string($phone) && $phone !== '') {
            // Delegated rather than parsed inline: this used to be its own
            // regex, and a second, subtly different copy of "what is an
            // Ethiopian phone number" is exactly how the OTP lookup ended up
            // unable to find users this request had stored.
            //
            // Unparseable input is passed through untouched so the `regex` rule
            // below reports it, instead of being silently dropped here.
            $this->merge([
                'admin_phone' => EthiopianPhone::canonical($phone) ?? $phone,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:255'],
            // `not_in` is the only thing actually stopping a tenant from taking
            // a reserved slug. The availability endpoint the UI calls is advice;
            // this is enforcement. Without it `admin` registered successfully
            // and produced a tenant squatting the platform console's hostname
            // that ResolveTenant then refused to resolve.
            'subdomain' => [
                'required', 'string', 'min:3', 'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(Tenant::RESERVED_SUBDOMAINS),
                'unique:tenants,subdomain',
            ],
            'admin_name' => ['required', 'string', 'min:2', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_phone' => ['nullable', 'string', 'regex:/^\+251\d{9}$/'],
            // The tenant does not exist yet, so this falls through to
            // PasswordPolicy::DEFAULTS — identical to the previous `min:8`. What
            // it buys is drift protection: raising the platform floor in
            // PasswordPolicy now takes effect at registration too, and it means
            // every password entry point in the app enforces the same rule.
            'password' => ['required', 'string', new PasswordPolicy, 'confirmed'],
            'organization_type' => ['nullable', 'string', 'max:50'],
            'template_slug' => ['nullable', 'string', 'exists:organization_templates,slug'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'Subdomain must contain only lowercase letters, numbers, and hyphens.',
            'subdomain.not_in' => 'That subdomain is reserved. Please choose another.',
            'admin_phone.regex' => 'Enter a valid Ethiopian phone number, e.g. 0912345678 or +251912345678.',
        ];
    }
}

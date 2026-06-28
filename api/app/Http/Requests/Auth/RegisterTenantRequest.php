<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:255'],
            'subdomain' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'unique:tenants,subdomain'],
            'admin_name' => ['required', 'string', 'min:2', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_phone' => ['nullable', 'string', 'regex:/^\+251\d{9}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'organization_type' => ['nullable', 'string', 'max:50'],
            'template_slug' => ['nullable', 'string', 'exists:organization_templates,slug'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'Subdomain must contain only lowercase letters, numbers, and hyphens.',
            'admin_phone.regex' => 'Phone number must be in +251XXXXXXXXX format.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Applied immediately — keep in sync with ProfileUpdateRequest::SELF_FIELDS.
            'phone' => ['nullable', 'string', 'max:20'],
            'marital_status' => ['nullable', 'string', 'in:single,married,divorced,widowed'],
            'nationality' => ['nullable', 'string', 'max:100'],

            // Shorthand for the primary emergency contact. The full list is managed
            // through /profile/emergency-contacts.
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],

            // Gated fields — staged into profile_update_requests for HR review
            // rather than applied. Keep in sync with ProfileUpdateRequest::GATED_FIELDS;
            // a field missing a rule here is silently dropped by validated().
            'name' => ['nullable', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'date_format:Y-m-d', 'before:today'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\MaritalStatus;
use App\Rules\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $stored = $user === null ? null : ($user->employee()->value('phone') ?? $user->phone);

        return [
            // Applied immediately — keep in sync with ProfileUpdateRequest::SELF_FIELDS.
            'phone' => ['nullable', 'string', 'max:20', Phone::any()->unlessUnchanged(is_string($stored) ? $stored : null)],
            'marital_status' => ['nullable', 'string', Rule::enum(MaritalStatus::class)],
            'nationality' => ['nullable', 'string', 'max:100'],

            // Shorthand for the primary emergency contact. The full list is managed
            // through /profile/emergency-contacts.
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20', Phone::any()],
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

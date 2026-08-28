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

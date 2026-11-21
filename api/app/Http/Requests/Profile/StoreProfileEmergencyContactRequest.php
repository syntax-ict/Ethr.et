<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Rules\Phone;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfileEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', Phone::any()],
            'email' => ['nullable', 'email', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}

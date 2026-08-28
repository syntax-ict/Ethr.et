<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // `min:8` replaced by the tenant-configurable policy, whose defaults
            // are exactly the old behaviour (8 chars, no composition rules).
            'password' => ['required', 'string', new PasswordPolicy, 'confirmed', 'different:current_password'],
        ];
    }
}

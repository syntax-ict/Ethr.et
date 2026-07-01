<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class RegisterKioskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'branch_public_id' => ['required', 'string', 'exists:branches,public_id'],
            'admin_pin' => ['required', 'string', 'min:4', 'max:8', 'regex:/^\d+$/'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
        ];
    }
}

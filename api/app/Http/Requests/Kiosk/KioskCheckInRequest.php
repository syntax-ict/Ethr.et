<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class KioskCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'employee_code' => ['required', 'string'],
            'type' => ['required', 'string', 'in:check_in,check_out'],
            'pin' => ['nullable', 'string', 'max:8'],
        ];
    }
}

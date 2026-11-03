<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class ChangeKioskPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_pin' => ['required', 'string', 'max:8'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed', 'different:current_pin'],
        ];
    }
}

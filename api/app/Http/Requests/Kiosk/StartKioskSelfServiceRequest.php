<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class StartKioskSelfServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_code' => ['required', 'string', 'max:30'],
            'pin' => ['required', 'string', 'max:8'],
        ];
    }
}

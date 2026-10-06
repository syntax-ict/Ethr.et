<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class GenerateQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'branch_public_id' => ['required', 'string'],
            'shift_public_id' => ['nullable', 'string'],
            'expiry_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ];
    }
}

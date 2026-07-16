<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class EndKioskSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ended_by' => ['nullable', 'string'],
        ];
    }
}

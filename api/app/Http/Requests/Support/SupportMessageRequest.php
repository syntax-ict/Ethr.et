<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class SupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class PreviewBalanceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}

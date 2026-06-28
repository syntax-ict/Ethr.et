<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in([
                'contract', 'certificate', 'id_copy', 'academic', 'medical', 'other',
            ])],
            'expiry_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}

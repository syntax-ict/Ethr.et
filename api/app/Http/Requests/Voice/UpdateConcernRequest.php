<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use App\Models\Concern;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConcernRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', Concern::STATUSES)],
            'resolution' => ['nullable', 'required_if:status,resolved', 'string', 'max:3000'],
        ];
    }
}

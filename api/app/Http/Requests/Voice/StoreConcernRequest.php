<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use App\Models\Concern;
use Illuminate\Foundation\Http\FormRequest;

class StoreConcernRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:'.implode(',', Concern::CATEGORIES)],
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'anonymous' => ['required', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use App\Models\PulseResponse;
use Illuminate\Foundation\Http\FormRequest;

class StorePulseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'between:1,5'],
            'tags' => ['sometimes', 'array', 'max:5'],
            'tags.*' => ['string', 'in:'.implode(',', PulseResponse::TAGS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}

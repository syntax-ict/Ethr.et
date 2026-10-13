<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

/** A concern's follow-up code, sent in the body so it never sits in a URL or a log. */
class TrackConcernRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'body' => ['sometimes', 'string', 'max:3000'],
        ];
    }
}

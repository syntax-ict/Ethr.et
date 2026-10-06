<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // en + am ship complete; the rest are the architecture-supported set and
            // fall back per key. Mirrors the header language switcher.
            'locale' => ['nullable', 'string', 'in:en,am,om,ti,so,sid'],
            // Mirrors the header theme menu, high-contrast included.
            'theme' => ['nullable', 'string', 'in:light,dark,system,high-contrast'],
            'calendar' => ['nullable', 'string', 'in:gregorian,ethiopian,dual'],
        ];
    }
}

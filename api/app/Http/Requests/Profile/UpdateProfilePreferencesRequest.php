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
            // Dual shows Gregorian and Ethiopian side by side; the tenant-level
            // `ethiopian_calendar` flag decides whether the choice is offered at all.
            'calendar' => ['nullable', 'string', 'in:gregorian,ethiopian,dual'],
        ];
    }
}

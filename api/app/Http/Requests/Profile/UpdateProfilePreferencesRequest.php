<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Support\ClockPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'clock' => ['nullable', 'string', Rule::in(ClockPreference::CHOICES)],
            'punch_reminders' => ['nullable', 'boolean'],
            'auto_check_in' => ['nullable', 'boolean'],
        ];
    }
}

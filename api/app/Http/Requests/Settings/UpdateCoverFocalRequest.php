<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where in the cover photo to keep in frame when the hero crops it: a point
 * as fractions of the width and the height, 0 to 1, the centre by default.
 */
class UpdateCoverFocalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate::authorize('settings.manage') runs in the controller.
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}

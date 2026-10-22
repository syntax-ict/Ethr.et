<?php

declare(strict_types=1);

namespace App\Http\Requests\Offboarding;

use Illuminate\Foundation\Http\FormRequest;

/** Ticking or unticking one line of an exit clearance; the controller authorises who. */
class UpdateExitClearanceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'done' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}

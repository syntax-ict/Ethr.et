<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepting or rejecting an integrity flag. A rejection voids the punch and
 * the note is sent to the employee, so it is required there.
 */
class ReviewIntegrityFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:accept,reject'],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:500'],
        ];
    }
}

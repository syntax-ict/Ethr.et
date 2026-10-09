<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Four to six digits, or `null` to remove the employee's PIN. The field is
 * required, so an empty body cannot remove a PIN by accident.
 */
class UpdateKioskPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'pin' => ['present', 'nullable', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }
}

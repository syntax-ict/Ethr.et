<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refusing or revoking a phone. The reason is optional; when given it is
 * kept in the audit log and told to the employee.
 */
class DecideAttendanceDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->validated('reason'));

        return $reason === '' ? null : $reason;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Until when an employee may punch without their registered phone (a phone
 * that cannot hold a passkey, a broken phone awaiting replacement). Null ends
 * the exemption. Their punches are still flagged for review meanwhile.
 */
class UpdateAttendanceDeviceExemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'until' => ['present', 'nullable', 'date', 'after_or_equal:today', 'before:+92 days'],
        ];
    }
}

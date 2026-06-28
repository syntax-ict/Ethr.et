<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_record_public_id' => ['required', 'string', 'exists:attendance_records,public_id'],
            'reason' => ['required', 'string', 'max:1000'],
            'proposed_check_in' => ['nullable', 'date'],
            'proposed_check_out' => ['nullable', 'date'],
        ];
    }
}

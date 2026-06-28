<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class OfflineSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1', 'max:100'],
            'records.*.idempotency_key' => ['required', 'string', 'max:64'],
            'records.*.employee_public_id' => ['required', 'string'],
            'records.*.type' => ['required', 'string', 'in:check_in,check_out'],
            'records.*.timestamp' => ['required', 'date'],
            'records.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'records.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'records.*.offline_token' => ['required', 'string', 'max:128'],
        ];
    }
}

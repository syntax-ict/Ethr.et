<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Rules\Base64Image;
use App\Support\DeviceProof;
use App\Support\PunchSignals;
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
            'records.*.offline_token' => ['required', 'string', 'max:2048'],
            'records.*.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'records.*.photo' => ['nullable', 'string', new Base64Image],
            'records.*.liveness' => ['nullable', 'string', 'in:passed,failed,no_face,unavailable'],
            ...PunchSignals::rules('records.*.'),
            ...DeviceProof::rules('records.*.'),
        ];
    }
}

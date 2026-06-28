<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'adapter_type' => ['required', 'string', 'in:hikvision,zkteco'],
            'branch_public_id' => ['required', 'string', 'exists:branches,public_id'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'connection_config' => ['required', 'array'],
            'connection_config.ip' => ['required', 'string'],
            'connection_config.port' => ['required', 'integer', 'min:1', 'max:65535'],
            'connection_config.username' => ['nullable', 'string'],
            'connection_config.password' => ['nullable', 'string'],
            'connection_config.api_key' => ['nullable', 'string'],
        ];
    }
}

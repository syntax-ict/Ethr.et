<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'adapter_type' => ['sometimes', 'string', 'in:hikvision,zkteco,suprema,mock'],
            'branch_public_id' => ['sometimes', 'string', 'exists:branches,public_id'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'auto_sync' => ['sometimes', 'boolean'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'connection_config' => ['sometimes', 'array'],
            'connection_config.ip' => ['required_with:connection_config', 'string'],
            'connection_config.port' => ['required_with:connection_config', 'integer', 'min:1', 'max:65535'],
        ];
    }
}

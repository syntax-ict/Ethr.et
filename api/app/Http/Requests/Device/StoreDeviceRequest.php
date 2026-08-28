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
            'location_description' => ['nullable', 'string', 'max:500'],
            'adapter_type' => ['required', 'string', 'in:hikvision,zkteco,suprema,generic,mock'],
            'branch_public_id' => ['required', 'string', 'exists:branches,public_id'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'auto_sync' => ['sometimes', 'boolean'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'connection_config' => ['required', 'array'],
            // IP-based vendors need ip/port; the generic HTTP adapter is URL-based
            // and needs base_url instead; mock needs neither.
            'connection_config.ip' => ['required_unless:adapter_type,mock,generic', 'nullable', 'string'],
            'connection_config.port' => ['required_unless:adapter_type,mock,generic', 'nullable', 'integer', 'min:1', 'max:65535'],
            'connection_config.base_url' => ['required_if:adapter_type,generic', 'nullable', 'url'],
            'connection_config.username' => ['nullable', 'string'],
            'connection_config.password' => ['nullable', 'string'],
            'connection_config.api_key' => ['nullable', 'string'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use App\Http\Requests\Device\Concerns\ValidatesSerialUniqueness;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    // serial_number uniqueness is a withValidator() hook, not a rule: rules()
    // feeds the generated API contract. See the trait.
    use ValidatesSerialUniqueness;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'adapter_type' => ['sometimes', 'string', 'in:hikvision,zkteco,suprema,generic,mock'],
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

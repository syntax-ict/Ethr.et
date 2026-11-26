<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One address to add to a device's webhook allowlist: the one its refused
 * call came from. A single address, not a range; a range is set on the
 * device's own form, where its width can be thought about.
 */
class AllowDeviceAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ip' => ['required', 'string', 'max:45', 'ip'],
        ];
    }
}

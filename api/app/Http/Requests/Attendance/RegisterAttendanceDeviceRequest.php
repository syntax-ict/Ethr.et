<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The passkey a phone just created (the browser's RegistrationResponseJSON),
 * with where the phone is: the first phone registers by itself only at work.
 */
class RegisterAttendanceDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $b64url = 'regex:/^[A-Za-z0-9_\-]*$/';

        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1024', $b64url],
            'credential.rawId' => ['nullable', 'string', 'max:1024', $b64url],
            'credential.type' => ['nullable', 'string', 'in:public-key'],
            'credential.authenticatorAttachment' => ['nullable', 'string', 'max:32'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string', 'max:4096', $b64url],
            'credential.response.attestationObject' => ['required', 'string', 'max:16384', $b64url],
            'credential.response.transports' => ['nullable', 'array', 'max:8'],
            'credential.response.transports.*' => ['string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'device_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }
}

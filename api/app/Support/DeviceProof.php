<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The passkey signature a self-service punch carries: the browser's
 * AuthenticationResponseJSON, base64url fields, checked by
 * AttendanceDeviceGate. Optional in the request; whether a punch without one
 * is accepted is the organisation's `device_binding`.
 */
final class DeviceProof
{
    /** Spread into a FormRequest's rules(). */
    public static function rules(string $prefix = ''): array
    {
        $b64url = 'regex:/^[A-Za-z0-9_\-]*$/';

        return [
            $prefix.'device_proof' => ['nullable', 'array'],
            $prefix.'device_proof.id' => ['required_with:'.$prefix.'device_proof', 'string', 'max:1024', $b64url],
            $prefix.'device_proof.rawId' => ['nullable', 'string', 'max:1024', $b64url],
            $prefix.'device_proof.type' => ['nullable', 'string', 'in:public-key'],
            $prefix.'device_proof.response' => ['required_with:'.$prefix.'device_proof', 'array'],
            $prefix.'device_proof.response.clientDataJSON' => ['required_with:'.$prefix.'device_proof', 'string', 'max:4096', $b64url],
            $prefix.'device_proof.response.authenticatorData' => ['required_with:'.$prefix.'device_proof', 'string', 'max:4096', $b64url],
            $prefix.'device_proof.response.signature' => ['required_with:'.$prefix.'device_proof', 'string', 'max:2048', $b64url],
            $prefix.'device_proof.response.userHandle' => ['nullable', 'string', 'max:1024', $b64url],
        ];
    }
}

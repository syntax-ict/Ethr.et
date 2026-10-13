<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Punches a kiosk made while offline, each sealed with the kiosk's public key
 * (see KioskOfflineSeal). Everything that matters is inside the seal; the
 * idempotency key outside it only labels the result.
 */
class KioskSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1', 'max:50'],
            'records.*.idempotency_key' => ['required', 'string', 'max:64'],
            'records.*.sealed' => ['required', 'string', 'max:1024'],
            'records.*.grant' => ['nullable', 'string', 'max:2048'],
        ];
    }
}

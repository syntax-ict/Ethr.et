<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Rules\Base64Image;
use Illuminate\Foundation\Http\FormRequest;

class MobileCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Selfie captured on the device, sent inline as a data URL. The
            // stored object key comes back from FileStorageService — clients
            // never supply `photo_path` themselves.
            'photo' => ['nullable', 'string', new Base64Image],
        ];
    }
}

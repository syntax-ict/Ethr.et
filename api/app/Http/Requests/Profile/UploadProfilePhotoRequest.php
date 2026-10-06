<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The photo has its own endpoint because PHP does not parse a multipart body on
 * PUT — the `photo` field on `PUT /profile` could never have arrived, no matter
 * what the client sent.
 */
class UploadProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // FileStorageService re-verifies the magic bytes and strips EXIF; these
            // rules only keep obviously wrong uploads out of that path.
            'photo' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}
